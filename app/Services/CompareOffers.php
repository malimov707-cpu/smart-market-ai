<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

class CompareOffers
{
    public const UNITS = ['kg' => ['kg', '1'], 'g' => ['kg', '0.001'], 'l' => ['l', '1'], 'ml' => ['l', '0.001'], 'pcs' => ['pcs', '1']];

    public function __construct(private LatestOffers $latest, private ExchangeRates $exchange, private PriceVerification $verification, private SupplierAvailability $availability) {}

    /** Money and quantities remain decimal strings. Latest expired/rejected quotes never revive older prices. */
    public function compare(Product $product, string $quantity, CarbonImmutable $asOf, User $user): array
    {
        abort_if($product->archived_at !== null, 404);
        $offers = $this->latest->get($product, $asOf, $user);
        $rates = $this->exchange->at($product->organization_id, $asOf);
        $reviews = $this->verification->snapshots($product->organization_id, $offers->modelKeys(), $asOf);
        $availability = $this->availability->at($offers, $asOf);
        $rows = $offers->map(fn (Offer $offer): array => $this->calculate($offer, $product, $quantity, $asOf, $rates, $reviews[$offer->id] ?? [], $availability[$offer->supplier_id] ?? []));
        $sorted = $rows->sort(function (array $a, array $b): int {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }
            if ($a['delivery_known'] !== $b['delivery_known']) {
                return $a['delivery_known'] ? -1 : 1;
            }

            return bccomp($a['known_total'] ?? '0', $b['known_total'] ?? '0', 2) ?: ($a['id'] <=> $b['id']);
        })->values();
        $complete = $sorted->filter(fn (array $row): bool => $row['eligible'] && $row['delivery_known']);
        $cheapest = $complete->first()['id'] ?? null;
        $unit = $rows->filter(fn (array $row): bool => $row['eligible'] && $row['normalized_unit_price'] !== null)
            ->sort(fn (array $a, array $b): int => bccomp($a['normalized_unit_price'], $b['normalized_unit_price'], 12) ?: ($a['id'] <=> $b['id']))->first()['id'] ?? null;
        // Nominal prices are comparable only within the same original currency; package sizes can differ.
        $nominal = $rows->filter(fn (array $row): bool => $row['eligible'])->groupBy('currency')->map(fn ($group) => $group
            ->sort(fn (array $a, array $b): int => bccomp($a['price'], $b['price'], 2) ?: ($a['id'] <=> $b['id']))->first()['id'])->all();

        return $sorted->map(fn (array $row): array => [...$row, 'cheapest_complete' => $row['id'] === $cheapest,
            'lowest_normalized' => $row['id'] === $unit, 'lowest_nominal' => ($nominal[$row['currency']] ?? null) === $row['id']])->all();
    }

    public function calculate(Offer $offer, Product $product, string $quantity, CarbonImmutable $asOf, array $rates, array $reviews = [], array $availability = []): array
    {
        [$base, $factor] = self::UNITS[$offer->unit] ?? [null, '0'];
        $warnings = [];
        $excluded = [];
        $supplier = $offer->supplier;
        $warnings[] = $supplier->verification_status === 'verified' ? 'Yetkazib beruvchi tashkilot doirasida tasdiqlangan; platforma tasdig‘i alohida' : 'Yetkazib beruvchi tasdiqlanmagan';
        if ($supplier->platform_verification_status === 'verified') {
            $warnings[] = 'Platforma supplier tekshiruvi tasdiqlangan; narx tasdig‘i alohida';
        }
        $stale = $offer->observed_at->lt($asOf->subDays(7));
        $expired = $offer->expires_at && $offer->expires_at->lte($asOf);
        $compatible = $base === $product->unit && bccomp($offer->package_quantity, '0', 3) > 0;
        if ($availability['archived'] ?? ($supplier->archived_at && $supplier->archived_at->lte($asOf))) {
            $excluded[] = 'Yetkazib beruvchi arxivlangan';
        }
        if ($availability['suspended'] ?? ($supplier->verification_status === 'suspended' || $supplier->platform_verification_status === 'suspended')) {
            $excluded[] = 'Yetkazib beruvchi tekshiruvi shu vaqtda to‘xtatilgan';
        }
        if ($stale) {
            $excluded[] = 'Narx 7 kundan eski';
        }
        if ($expired) {
            $excluded[] = 'Taklif muddati tugagan';
        }
        if (! $compatible) {
            $excluded[] = 'O‘lchov birligi yoki qadoq mos emas';
        }
        foreach (['organization', 'platform'] as $scope) {
            if (in_array($reviews[$scope]['status'] ?? null, ['rejected', 'disputed'], true)) {
                $excluded[] = 'Narx tekshiruvi: '.$scope.' '.$reviews[$scope]['status'];
            }
        }
        $packageSize = $compatible ? bcmul($offer->package_quantity, $factor, 6) : null;
        $packages = $compatible ? bcdiv($quantity, $packageSize, 0) : null;
        if ($compatible && bccomp(bcmul($packages, $packageSize, 6), $quantity, 6) < 0) {
            $packages = bcadd($packages, '1', 0);
        }
        if ($compatible && bccomp($packages, (string) $offer->minimum_packages, 0) < 0) {
            $packages = (string) $offer->minimum_packages;
        }
        $subtotal = $compatible ? bcmul($packages, $offer->price, 2) : null;
        $originalTotal = $compatible ? bcadd($subtotal, $offer->delivery_fee ?? '0', 2) : null;
        $total = $compatible ? $this->exchange->convert($originalTotal, $offer->currency, $rates, $asOf) : null;
        if ($total === null && $compatible) {
            $excluded[] = 'Tasdiqlangan, shu vaqtda amal qiluvchi valyuta kursi mavjud emas';
        }
        if ($offer->delivery_fee === null) {
            $warnings[] = 'Yetkazish narxi noma’lum; to‘liq landed cost yo‘q';
        }
        $normalized = $compatible ? $this->exchange->unitPrice($offer->price, $packageSize, $offer->currency, $rates, $asOf) : null;
        $rate = $offer->currency === 'UZS' ? null : ($rates[$offer->currency] ?? null);
        $status = [];
        foreach (['organization', 'platform'] as $scope) {
            $status[$scope] = $expired ? 'expired' : ($reviews[$scope]['status'] ?? 'unverified');
        }

        return [
            'id' => $offer->id, 'supplier_id' => $offer->supplier_id, 'supplier' => $supplier->name,
            'price' => $offer->price, 'currency' => $offer->currency, 'base_currency' => 'UZS',
            'unit' => $offer->unit, 'package_quantity' => $offer->package_quantity, 'normalized_package' => $packageSize,
            'minimum_packages' => $offer->minimum_packages, 'packages' => $packages,
            'supplied_quantity' => $compatible ? bcmul($packages, $packageSize, 6) : null,
            'excess_quantity' => $compatible ? bcsub(bcmul($packages, $packageSize, 6), $quantity, 6) : null,
            'subtotal' => $subtotal, 'delivery_fee' => $offer->delivery_fee, 'delivery_known' => $offer->delivery_fee !== null,
            'original_known_total' => $originalTotal, 'known_total' => $total,
            'normalized_unit_price' => $normalized, 'eligible' => $excluded === [],
            'complete' => $excluded === [] && $offer->delivery_fee !== null,
            'warnings' => [...$warnings, ...$excluded], 'exclusion_reasons' => $excluded,
            'stale' => $stale, 'expired' => (bool) $expired, 'verification' => $status, 'verification_evidence' => $reviews,
            'provenance' => $offer->source_type ?? $offer->provenance, 'source_reference' => $offer->source_reference,
            'source_identifier' => $offer->source_identifier, 'task_reference' => $offer->task_reference,
            'observed_at' => $offer->observed_at->toIso8601String(), 'valid_from' => $offer->observed_at->toIso8601String(),
            'recorded_at' => CarbonImmutable::parse($offer->recorded_at ?? $offer->created_at)->toISOString(), 'expires_at' => $offer->expires_at?->toIso8601String(),
            'correction_of_id' => $offer->correction_of_id, 'exchange_rate' => $rate?->toArray(),
        ];
    }
}
