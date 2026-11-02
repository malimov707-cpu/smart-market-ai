<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class WorkspaceInsights
{
    public function __construct(private CompareOffers $comparison) {}

    /** Evidence-based signals use the same eligibility rules as the comparison screen. */
    public function summarize(Organization $organization, User $user, ?int $productId = null, string $quantity = '1'): array
    {
        abort_unless($user->can('view', $organization), 403);
        $asOf = CarbonImmutable::now();
        $products = Product::whereIn('organization_id', Organization::allIds())->whereNull('archived_at');
        if ($productId !== null) {
            (clone $products)->findOrFail($productId);
        }
        $selected = (clone $products)->when($productId !== null, fn ($query) => $query->whereKey($productId))
            ->orderBy('name')->limit(6)->get();
        $decisions = $selected->map(function (Product $product) use ($user, $asOf, $quantity): array {
            try {
                $rows = collect($this->comparison->compare($product, $quantity, $asOf, $user));
            } catch (ValidationException) {
                return ['id' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'status' => 'too_many_suppliers',
                    'eligible' => 0, 'total' => null, 'supplier' => null, 'offer_id' => null, 'alternatives' => 0, 'spread' => null,
                    'warnings' => ['500 dan ortiq yetkazib beruvchi. Batafsil tahlil uchun katalogni toraytiring.']];
            }
            $complete = $rows->where('complete', true)->values();
            $best = $complete->first();
            $worst = $complete->last();

            return ['id' => $product->id, 'name' => $product->name, 'unit' => $product->unit,
                'status' => $best ? 'ready' : ($rows->isEmpty() ? 'no_offers' : 'incomplete'),
                'eligible' => $rows->where('eligible', true)->count(), 'alternatives' => $complete->count(),
                'total' => $best['known_total'] ?? null, 'supplier' => $best['supplier'] ?? null, 'offer_id' => $best['id'] ?? null,
                'spread' => $best && $complete->count() > 1 ? bcsub($worst['known_total'], $best['known_total'], 2) : null,
                'warnings' => $best ? $best['warnings'] : $rows->flatMap(fn (array $row): array => $row['warnings'])->unique()->values()->take(4)->all()];
        });
        $observations = Offer::whereIn('organization_id', Organization::allIds())->where('observed_at', '<=', $asOf)
            ->whereRaw(LatestOffers::recordedExpression().' <= ?', [$asOf->format('Y-m-d H:i:s.u')]);
        $daily = (clone $observations)->where('observed_at', '>=', $asOf->startOfDay()->subDays(13))
            ->selectRaw('DATE(observed_at) AS day, COUNT(*) AS total')->groupByRaw('DATE(observed_at)')->pluck('total', 'day');
        $timeline = collect(range(13, 0))->map(fn (int $days): array => [
            'day' => $asOf->subDays($days)->format('Y-m-d'), 'count' => (int) ($daily[$asOf->subDays($days)->format('Y-m-d')] ?? 0),
        ]);
        $categories = (clone $products)->selectRaw('category, COUNT(*) AS total')->groupBy('category')->orderByDesc('total')->limit(5)->get()
            ->map(fn (Product $row): array => ['name' => $row->category ?: 'Kategoriyasiz', 'count' => (int) $row->total]);

        return ['as_of' => $asOf->toISOString(), 'quantity' => $quantity, 'sample_limit' => 6, 'product_count' => $products->count(),
            'decisions' => $decisions, 'timeline' => $timeline, 'categories' => $categories,
            'observation_count' => (clone $observations)->count(),
            'missing_delivery' => (clone $observations)->whereNull('delivery_fee')->count(),
            'expired' => (clone $observations)->whereNotNull('expires_at')->where('expires_at', '<=', $asOf)->count(),
        ];
    }
}
