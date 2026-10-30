<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceReview;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

class PriceHistory
{
    public function __construct(private LatestOffers $latest, private CompareOffers $comparison, private ExchangeRates $exchange, private PriceVerification $verification) {}

    public function changes(Product $product, CarbonImmutable $asOf, CarbonImmutable $from, User $user, ?int $supplier = null): array
    {
        $quotes = $this->latest->get($product, $asOf, $user, 1, $supplier === null ? null : fn ($query) => $query->where('supplier_id', $supplier));
        $rates = $this->exchange->at($product->organization_id, $asOf);
        $reviewStates = PriceReview::where('organization_id', $product->organization_id)->where('created_at', '<=', $asOf->utc()->format('Y-m-d H:i:s.u'))
            ->select('price_reviews.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY offer_id, scope ORDER BY created_at DESC, id DESC) AS review_rank');
        $rejected = PriceReview::fromSub($reviewStates, 'price_reviews')->where('review_rank', 1)->whereIn('status', ['rejected', 'disputed'])->select('offer_id');
        $units = array_keys(array_filter(CompareOffers::UNITS, fn (array $unit): bool => $unit[0] === $product->unit));
        $currencies = ['UZS', ...array_keys(array_filter($rates, fn ($rate): bool => $rate->expires_at->gt($asOf)))];
        $currentIds = $quotes->modelKeys();
        $previous = $this->latest->get($product, $asOf, $user, 1, fn ($query) => $query->when($supplier, fn ($query) => $query->where('supplier_id', $supplier))->whereNotIn('id', $currentIds)
            ->whereNotIn('id', $rejected)->whereIn('unit', $units)->whereIn('currency', $currencies)->where('observed_at', '>=', $from));
        $quotes = $quotes->concat($previous);
        $reviews = $this->verification->snapshots($product->organization_id, $quotes->modelKeys(), $asOf);
        $changes = [];
        foreach ($quotes->groupBy('supplier_id') as $supplierId => $pair) {
            if ($supplier !== null && $supplier !== $supplierId) {
                continue;
            }
            $latest = $pair->first();
            $previous = $pair->get(1);
            $current = $this->comparison->calculate($latest, $product, '1', $asOf, $rates, $reviews[$latest->id] ?? []);
            $prior = $previous ? $this->comparison->calculate($previous, $product, '1', $asOf, $rates, $reviews[$previous->id] ?? []) : null;
            $validPrior = $previous && $previous->observed_at->gte($from) && ! array_intersect(array_values($prior['verification']), ['rejected', 'disputed']);
            $available = collect($reviews[$latest->id] ?? [])->pluck('status')->intersect(['rejected', 'disputed'])->isEmpty() && $latest->observed_at->gte($from) && $validPrior && $current['normalized_unit_price'] !== null && $prior['normalized_unit_price'] !== null;
            $absolute = $available ? bcsub($current['normalized_unit_price'], $prior['normalized_unit_price'], 12) : null;
            $percent = $available && bccomp($prior['normalized_unit_price'], '0', 12) > 0 ? bcdiv(bcmul($absolute, '100', 18), $prior['normalized_unit_price'], 4) : null;
            $changes[] = ['supplier_id' => $supplierId, 'supplier' => $latest->supplier->name, 'latest_id' => $latest->id,
                'previous_id' => $previous?->id, 'latest_price' => $current['normalized_unit_price'], 'previous_price' => $prior['normalized_unit_price'] ?? null,
                'absolute_change' => $absolute, 'percentage_change' => $percent, 'status' => $available ? 'available' : 'insufficient_data'];
        }
        $matched = collect($changes)->where('status', 'available');
        $low = fn (string $field): ?string => $matched->pluck($field)->sort(fn (string $a, string $b): int => bccomp($a, $b, 12))->first();
        $currentLow = $low('latest_price');
        $previousLow = $low('previous_price');

        return ['suppliers' => $changes, 'product' => ['status' => $matched->isEmpty() ? 'insufficient_data' : 'available',
            'cohort_suppliers' => $matched->count(), 'latest_minimum' => $currentLow, 'previous_minimum' => $previousLow,
            'absolute_change' => $matched->isEmpty() ? null : bcsub($currentLow, $previousLow, 12),
            'percentage_change' => $matched->isEmpty() || bccomp($previousLow, '0', 12) <= 0 ? null : bcdiv(bcmul(bcsub($currentLow, $previousLow, 12), '100', 18), $previousLow, 4)]];
    }
}
