<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceInsights
{
    public function __construct(private CompareOffers $comparison) {}

    /** Evidence-based signals use the same eligibility rules as the comparison screen. */
    public function summarize(Organization $organization, User $user, ?int $productId = null, string $quantity = '1', ?string $catalogQuery = null): array
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
        $catalogMatches = $this->catalogMatches($catalogQuery, $asOf);

        return ['as_of' => $asOf->toISOString(), 'quantity' => $quantity, 'sample_limit' => 6, 'product_count' => $products->count(),
            'decisions' => $decisions, 'catalog_match_limit' => 20, 'catalog_matches' => $catalogMatches,
            'catalog_match_count' => count($catalogMatches), 'timeline' => $timeline, 'categories' => $categories,
            'observation_count' => (clone $observations)->count(),
            'missing_delivery' => (clone $observations)->whereNull('delivery_fee')->count(),
            'expired' => (clone $observations)->whereNotNull('expires_at')->where('expires_at', '<=', $asOf)->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogMatches(?string $question, CarbonImmutable $asOf): array
    {
        $tokens = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $question)) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->reject(fn (string $token): bool => in_array($token, [
                'uchun', 'qaysi', 'qayerda', 'bor', 'bormi', 'narxi', 'narxlar', 'narxlarini', 'mahsulot',
                'mahsulotlar', 'nomlar', 'nomlarini', 'miqdor', 'yetkazib', 'beruvchi', 'tasdiqlanmagan',
                'category', 'categories', 'kategoriyasi', 'kategoriyada', 'mahsulotning', 'iltimos', 'aniq',
                'topib', 'show', 'list', 'names', 'name', 'prices', 'price', 'products', 'product', 'please',
                'find', 'all', 'and', 'the', 'for', 'with', 'from', 'have', 'available', 'quantity', 'snapshot',
                'пожалуйста', 'категория', 'категории', 'товары', 'товар', 'цена', 'цены', 'для',
                'есть', 'покажи', 'найди', 'список', 'какие', 'названия', 'название', 'цены', 'количество',
            ], true))
            ->unique()
            ->take(10)
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $organizationIds = Organization::allIds();
        $matchedProducts = Product::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('archived_at')
            ->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->orWhere('name', 'like', '%'.$token.'%')
                        ->orWhere('category', 'like', '%'.$token.'%')
                        ->orWhere('sku', 'like', '%'.$token.'%');
                }
            })
            ->limit(500)
            ->get(['id', 'organization_id', 'name', 'sku', 'unit', 'category']);

        if ($matchedProducts->isEmpty()) {
            return [];
        }

        $questionText = Str::lower(Str::ascii((string) $question));
        $rankedProducts = $matchedProducts->map(function (Product $product) use ($tokens, $questionText): array {
            $name = Str::lower(Str::ascii($product->name));
            $category = Str::lower(Str::ascii((string) $product->category));
            $sku = Str::lower($product->sku);
            $searchText = $category.' '.$name.' '.$sku;
            $score = 0;
            if ($questionText !== '' && str_contains($category, $questionText)) {
                $score += 30;
            }
            if ($questionText !== '' && str_contains($name, $questionText)) {
                $score += 25;
            }
            foreach ($tokens as $token) {
                $normalizedToken = Str::lower(Str::ascii($token));
                $matchedToken = $normalizedToken !== '' && str_contains($searchText, $normalizedToken)
                    ? $normalizedToken
                    : $token;
                if (str_contains($searchText, $matchedToken)) {
                    $score += str_contains($category, $matchedToken) ? 5 : 3;
                }
            }

            return ['product' => $product, 'score' => $score];
        })->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->take(20)
            ->values();

        if ($rankedProducts->isEmpty()) {
            return [];
        }

        $productIds = $rankedProducts->map(fn (array $row): int => $row['product']->id)->all();
        $rankedOffers = Offer::query()
            ->select('offers.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY offers.product_id ORDER BY offers.observed_at DESC, offers.id DESC) AS catalog_rank')
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('product_id', $productIds)
            ->where('observed_at', '<=', $asOf->utc())
            ->whereRaw(LatestOffers::recordedExpression().' <= ?', [$asOf->utc()->format('Y-m-d H:i:s.u')]);
        $offers = Offer::query()
            ->fromSub($rankedOffers, 'offers')
            ->where('catalog_rank', '<=', 3)
            ->with('supplier:id,name,verification_status,platform_verification_status')
            ->orderBy('product_id')
            ->orderBy('catalog_rank')
            ->get()
            ->groupBy('product_id');

        $offerCounts = Offer::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('product_id', $productIds)
            ->where('observed_at', '<=', $asOf->utc())
            ->whereRaw(LatestOffers::recordedExpression().' <= ?', [$asOf->utc()->format('Y-m-d H:i:s.u')])
            ->selectRaw('product_id, COUNT(*) AS total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');
        $organizationNames = Organization::query()->whereIn('id', $organizationIds)->pluck('name', 'id');

        return $rankedProducts->map(function (array $row) use ($offers, $offerCounts, $organizationNames): array {
            /** @var Product $product */
            $product = $row['product'];

            return [
                'product_id' => $product->id,
                'organization_id' => $product->organization_id,
                'organization' => $organizationNames[$product->organization_id] ?? 'Ichki katalog',
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category,
                'unit' => $product->unit,
                'offer_count' => (int) ($offerCounts[$product->id] ?? 0),
                'offers' => ($offers[$product->id] ?? collect())->map(fn (Offer $offer): array => [
                    'offer_id' => $offer->id,
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                    'unit' => $offer->unit,
                    'package_quantity' => $offer->package_quantity,
                    'minimum_packages' => $offer->minimum_packages,
                    'delivery_fee' => $offer->delivery_fee,
                    'observed_at' => $offer->observed_at->toIso8601String(),
                    'expires_at' => $offer->expires_at?->toIso8601String(),
                    'supplier' => $offer->supplier->name,
                    'supplier_verification' => $offer->supplier->verification_status,
                    'platform_verification' => $offer->supplier->platform_verification_status,
                ])->all(),
            ];
        })->all();
    }
}
