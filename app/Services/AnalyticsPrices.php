<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

class AnalyticsPrices
{
    public function __construct(private CompareOffers $comparison, private PriceHistory $history) {}

    public function product(Product $product, User $user, CarbonImmutable $asOf, CarbonImmutable $from, ?int $supplier = null): array
    {
        $current = collect($this->comparison->compare($product, '1', $asOf, $user));
        if ($supplier !== null) {
            $current = $current->where('supplier_id', $supplier);
        }
        $changes = $this->history->changes($product, $asOf, $from, $user, $supplier);
        $eligible = $current->where('eligible', true)->keyBy('supplier_id');
        $series = collect($changes['suppliers'])->map(function (array $change) use ($eligible): array {
            if (! isset($eligible[$change['supplier_id']]) || $change['percentage_change'] === null) {
                $change = [...$change, 'status' => 'insufficient_data', 'absolute_change' => null, 'percentage_change' => null];
            }

            return $change;
        });
        $cohort = $series->where('status', 'available');
        $minimum = fn ($values) => $values->sort(fn (string $a, string $b): int => bccomp($a, $b, 12))->first();
        $latest = $minimum($cohort->pluck('latest_price'));
        $previous = $minimum($cohort->pluck('previous_price'));
        $unitPrices = $eligible->pluck('normalized_unit_price')->filter(fn ($value) => $value !== null)
            ->sort(fn (string $a, string $b): int => bccomp($a, $b, 12))->values();

        return ['product_id' => $product->id, 'product_name' => $product->name, 'unit' => $product->unit, 'category' => $product->category,
            'lowest_eligible' => $unitPrices->first(), 'highest_eligible' => $unitPrices->last(), 'eligible_suppliers' => $eligible->count(),
            'suppliers' => $series->values()->all(), 'cohort_supplier_ids' => $cohort->pluck('supplier_id')->values()->all(),
            'status' => $cohort->isEmpty() ? 'insufficient_data' : 'available', 'latest_price' => $latest, 'previous_price' => $previous,
            'absolute_change' => $latest === null ? null : bcsub($latest, $previous, 12),
            'change_percent' => $previous === null || bccomp($previous, '0', 12) <= 0 ? null : bcdiv(bcmul(bcsub($latest, $previous, 12), '100', 18), $previous, 4)];
    }
}
