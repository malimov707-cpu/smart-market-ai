<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicMarketCatalog
{
    public function __construct(
        private KorzinkaCatalog $korzinka,
        private ConstructionCatalog $construction,
        private AsaxiyCatalog $asaxiy,
        private StatUzAveragePrices $statUz,
    ) {}

    public function search(
        string $query = '',
        array $alternatives = [],
        string $department = 'all',
        string $source = 'all',
        bool $includeOnDemand = false,
    ): array {
        $grocery = $this->korzinka->search($query, $alternatives);
        $rawGrocery = Cache::get(KorzinkaCatalog::CACHE_KEY);
        $sources = [['id' => 'korzinka', 'name' => 'Korzinka', 'department' => 'grocery', 'checked_at' => $grocery['checked_at'],
            'count' => count($rawGrocery['products'] ?? []), 'stale' => $grocery['stale'], 'sync_failed' => $grocery['sync_failed'], 'interval' => 5]];
        $asaxiyEnabled = filter_var(config('services.asaxiy.catalog_enabled', false), FILTER_VALIDATE_BOOL);
        $shouldSearchStatUz = $includeOnDemand && $query !== ''
            && in_array($department, ['all', 'grocery'], true) && in_array($source, ['all', 'statuz'], true);
        $statUz = $shouldSearchStatUz
            ? $this->statUz->search($query, $alternatives)
            : ['checked_at' => null, 'stale' => false, 'sync_failed' => false, 'partial' => false,
                'data_period' => null, 'total' => 0, 'dataset_total' => 0, 'products' => []];
        $sources[] = ['id' => 'statuz', 'name' => 'Milliy statistika · Toshkent bozorlari', 'department' => 'grocery',
            'checked_at' => $statUz['checked_at'], 'count' => $statUz['total'], 'stale' => $statUz['stale'],
            'sync_failed' => $statUz['sync_failed'], 'partial' => $statUz['partial'], 'data_period' => $statUz['data_period'],
            'dataset_count' => $statUz['dataset_total'], 'interval' => 720, 'on_demand' => true, 'official_average' => true];
        $shouldSearchAsaxiy = $includeOnDemand && $asaxiyEnabled && $query !== ''
            && in_array($department, ['all', 'general'], true) && in_array($source, ['all', 'asaxiy'], true);
        $asaxiy = $shouldSearchAsaxiy
            ? $this->asaxiy->search($query, $alternatives)
            : ['checked_at' => null, 'stale' => false, 'sync_failed' => false, 'partial' => false, 'total' => 0, 'products' => []];
        $sources[] = ['id' => 'asaxiy', 'name' => 'Asaxiy', 'department' => 'general', 'checked_at' => $asaxiy['checked_at'],
            'count' => $asaxiy['total'], 'stale' => $asaxiy['stale'], 'sync_failed' => $asaxiy['sync_failed'],
            'partial' => $asaxiy['partial'], 'enabled' => $asaxiyEnabled, 'interval' => 5, 'on_demand' => true];
        $groups = [];
        if (! in_array($department, ['construction', 'general'], true) && in_array($source, ['all', 'korzinka'], true)) {
            $groups[] = collect($grocery['products'])->map(fn (array $row): array => [...$row, 'key' => 'korzinka:'.$row['id'],
                'source_id' => 'korzinka', 'source_name' => 'Korzinka', 'checked_at' => $grocery['checked_at'], 'availability' => 'unknown'])->all();
        }
        $localCatalog = $this->localCatalogProducts($query, $alternatives, $department, $source);
        if ($localCatalog['products'] !== []) {
            $groups[] = $localCatalog['products'];
            $latestObservation = collect($localCatalog['products'])->pluck('checked_at')->filter()->sort()->last();
            $localCatalogStale = $latestObservation === null
                || CarbonImmutable::parse($latestObservation)->lt(now()->subDays(7))
                || collect($localCatalog['products'])->contains(fn (array $row): bool => ($row['stale'] ?? false)
                    || ($row['valid_until'] !== null && CarbonImmutable::parse($row['valid_until'])->lte(now())));
            $sources[] = ['id' => 'catalog', 'name' => 'Tashkilot katalogi', 'department' => 'general', 'checked_at' => $latestObservation,
                'count' => $localCatalog['total'], 'stale' => $localCatalogStale, 'sync_failed' => false, 'partial' => false, 'interval' => 5, 'on_demand' => true];
        }
        $total = ! in_array($department, ['construction', 'general'], true) && in_array($source, ['all', 'korzinka'], true) ? $grocery['total'] : 0;
        $total += $localCatalog['total'];
        if ($shouldSearchStatUz) {
            $groups[] = $statUz['products'];
            $total += $statUz['total'];
        }
        if ($shouldSearchAsaxiy) {
            $groups[] = $asaxiy['products'];
            $total += $asaxiy['total'];
        }
        $needles = array_values(array_filter(array_map($this->construction->normalize(...), array_slice([$query, ...$alternatives], 0, 20))));
        foreach (ConstructionCatalog::SOURCES as $id => $name) {
            $snapshot = $this->construction->snapshot($id);
            $checked = $snapshot['checked_at'];
            $stale = $checked === null || CarbonImmutable::parse($checked)->lt(now()->subMinutes(30));
            $sources[] = ['id' => $id, 'name' => $name, 'department' => 'construction', 'checked_at' => $checked, 'count' => count($snapshot['products']),
                'stale' => $stale, 'sync_failed' => $snapshot['sync_failed'], 'interval' => 15];
            if (in_array($department, ['grocery', 'general'], true) || ! in_array($source, ['all', $id], true)) {
                continue;
            }
            $matches = collect($snapshot['products'])->filter(function (array $row) use ($needles): bool {
                if ($needles === []) {
                    return true;
                }
                foreach ($needles as $needle) {
                    if (str_contains($row['search_text'], $needle)) {
                        return true;
                    }
                }

                return false;
            });
            $total += $matches->count();
            $groups[] = $matches->take(60)->map(fn (array $row): array => [...array_diff_key($row, ['search_text' => true]), 'key' => $row['id'], 'checked_at' => $checked])->values()->all();
        }
        $products = [];
        for ($index = 0; $index < 60 && count($products) < 60; $index++) {
            foreach ($groups as $group) {
                if (isset($group[$index])) {
                    $product = $group[$index];
                    $sourceStatus = collect($sources)->firstWhere('id', $product['source_id']);
                    $product['freshness'] = $this->freshness($product, $sourceStatus);
                    $products[] = $product;
                    if (count($products) === 60) {
                        break;
                    }
                }
            }
        }
        $active = collect($sources)->filter(fn (array $row): bool => ($row['enabled'] ?? true)
            && (! ($row['on_demand'] ?? false) || $query !== '')
            && ($department === 'all' || $row['department'] === $department) && ($source === 'all' || $row['id'] === $source));
        $checked = $active->pluck('checked_at')->filter()->sort()->last();

        return ['checked_at' => $checked, 'stale' => $active->isNotEmpty()
                && $active->contains(fn (array $row): bool => $row['stale'] || $row['checked_at'] === null),
            'sync_failed' => $active->contains('sync_failed', true), 'partial' => $active->contains('partial', true),
            'total' => $total, 'products' => $products, 'sources' => $sources,
            'coverage' => 'Ochiq sotuvchi kataloglari · O‘zbekiston; qamrov to‘liq emas, narx va mavjudlikni sotuvchi sahifasida tekshiring'];
    }

    private function freshness(array $product, ?array $source): string
    {
        if (! empty($product['data_period'])) {
            return 'historical';
        }
        if (is_string($product['valid_until'] ?? null) && CarbonImmutable::parse($product['valid_until'])->lte(now())) {
            return 'expired';
        }
        if (! is_string($product['checked_at'] ?? null)) {
            return 'unknown';
        }
        if (($product['stale'] ?? false) || ($source['stale'] ?? false) || ($source['sync_failed'] ?? false)) {
            return 'stale';
        }

        return 'fresh';
    }

    private function localCatalogProducts(string $query, array $alternatives, string $department, string $source): array
    {
        if ($source !== 'all' && $source !== 'korzinka' && $source !== 'asaxiy' && $source !== 'statuz') {
            return ['total' => 0, 'products' => []];
        }
        if ($department !== 'all' && $department !== 'general') {
            return ['total' => 0, 'products' => []];
        }
        if (! Schema::hasTable('products') || ! Schema::hasTable('organizations') || ! Schema::hasTable('offers')) {
            return ['total' => 0, 'products' => []];
        }

        $terms = array_values(array_filter(array_map(fn ($term): ?string => is_string($term) ? trim($term) : null,
            [$query, ...$alternatives]), fn (?string $term): bool => $term !== null && $term !== ''));
        if ($terms === []) {
            return ['total' => 0, 'products' => []];
        }

        $normalized = array_map(fn (string $term): string => strtolower(Str::ascii($term)), $terms);
        $broadTerms = ['elektron', 'electronic', 'mahsulot', 'product', 'texnika', 'tech', 'devices', 'device', 'qurilma', 'telefon', 'smartphone', 'noutbuk', 'laptop', 'tv', 'televizor', 'monitor', 'quloqchin', 'headphone', 'smartwatch', 'watch'];
        $isBroadCatalogQuery = collect($normalized)->contains(fn (string $term): bool => collect($broadTerms)->contains(fn (string $broadTerm): bool => str_contains($term, $broadTerm) || str_contains($broadTerm, $term)));
        $products = Product::whereIn('organization_id', Organization::allIds())
            ->whereNull('archived_at')->orderBy('name')->get();

        $matches = [];
        foreach ($products as $product) {
            $organizationName = Organization::find($product->organization_id)?->name ?? 'Catalog';
            $categoryText = strtolower(Str::ascii((string) ($product->category ?? '')));
            $aliases = array_values(array_filter(array_map(
                fn ($alias): ?string => is_string($alias) ? trim($alias) : null,
                (array) ($product->aliases ?? []),
            ), fn (?string $alias): bool => $alias !== null && $alias !== ''));
            $variantValues = collect((array) ($product->variant_attributes ?? []))
                ->flatMap(fn ($attribute): array => is_array($attribute)
                    ? array_values(array_filter($attribute, fn ($value): bool => is_scalar($value)))
                    : (is_scalar($attribute) ? [$attribute] : []))
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->values()
                ->all();
            $packageLabel = null;
            if ($product->package_quantity !== null && $product->package_unit !== null) {
                $quantity = rtrim(rtrim((string) $product->package_quantity, '0'), '.');
                $packageLabel = trim($quantity.' '.$product->package_unit);
            }
            $variantText = implode(' ', [...$variantValues, ...array_filter([$packageLabel])]);
            $normalizedAliases = array_map(fn (string $alias): string => strtolower(Str::ascii($alias)), $aliases);
            $normalizedVariants = strtolower(Str::ascii($variantText));
            $coreSearchText = strtolower(Str::ascii($product->name.' '.$product->sku.' '.$categoryText));
            $searchText = strtolower(Str::ascii($product->name.' '.$product->sku.' '.$categoryText.' '.implode(' ', $aliases).' '.$variantText));
            $aliasMatch = collect($normalized)->contains(fn (string $needle): bool => mb_strlen($needle) >= 3
                && collect($normalizedAliases)->contains(fn (string $alias): bool => str_contains($alias, $needle))
                && ! str_contains($coreSearchText, $needle));
            $variantMatch = collect($normalized)->contains(fn (string $needle): bool => mb_strlen($needle) >= 3
                && str_contains($normalizedVariants, $needle));
            $barcode = is_string($product->barcode) ? strtolower(Str::ascii(trim($product->barcode))) : '';
            $barcodeMatch = preg_match('/^\d{8,14}$/', $barcode) === 1 && in_array($barcode, $normalized, true);
            $score = 0;
            foreach ($normalized as $needle) {
                if ($needle === '') {
                    continue;
                }
                if (str_contains($searchText, $needle)) {
                    $score += 5;
                }
                if (str_contains(strtolower(Str::ascii($product->name)), $needle)) {
                    $score += 2;
                }
                if ($isBroadCatalogQuery && preg_match('/(smartphone|phone|laptop|tablet|tv|monitor|watch|headphone|charger|audio|router|printer|camera|audio|keyboard|mouse|loudspeaker|speaker|usb|battery|power bank|device)/i', $categoryText.' '.$product->name) === 1) {
                    $score += 1;
                }
            }
            if ($barcodeMatch) {
                $score += 20;
            }
            if ($score === 0 && ! $isBroadCatalogQuery) {
                continue;
            }
            if ($isBroadCatalogQuery && ! preg_match('/(smartphone|phone|laptop|tablet|tv|monitor|watch|headphone|charger|audio|router|printer|camera|keyboard|mouse|speaker|usb|battery|power bank|device)/i', $categoryText.' '.$product->name.' '.implode(' ', (array) $product->aliases ?? [])) && ! collect($terms)->contains(fn (string $term): bool => str_contains(strtolower(Str::ascii($product->name)), strtolower(Str::ascii($term))))) {
                continue;
            }

            $offer = Offer::where('organization_id', $product->organization_id)
                ->where('product_id', $product->id)->orderByDesc('observed_at')->first();
            $offerCount = Offer::where('organization_id', $product->organization_id)
                ->where('product_id', $product->id)->count();
            $price = $offer?->price ?? '0';
            $currency = $offer?->currency ?? 'UZS';
            $unitLabel = match ($product->unit ?? 'pcs') {
                'kg' => '1 kg',
                'l' => '1 litr',
                default => '1 dona',
            };
            $brand = $this->extractBrand($product->name, (array) ($product->aliases ?? []));
            $model = $this->extractModel($product->name, $brand);
            $sourceReference = $offer?->source_reference ?? '';
            $hasSyntheticObservation = str_starts_with($sourceReference, 'approved:')
                || str_starts_with($sourceReference, 'fayztools:');
            $checkedAt = $hasSyntheticObservation ? null : $offer?->observed_at?->toISOString();
            $validUntil = $offer?->expires_at?->toISOString();
            $stale = ($checkedAt !== null && CarbonImmutable::parse($checkedAt)->lt(now()->subDays(7)))
                || ($validUntil !== null && CarbonImmutable::parse($validUntil)->lte(now()));
            $matches[] = ['id' => 'catalog-'.$product->id, 'name' => $product->name, 'brand' => $brand, 'model' => $model,
                'offer_count' => $offerCount, 'search_text' => $searchText, 'alias_match' => $aliasMatch,
                'variant_match' => $variantMatch, 'barcode_match' => $barcodeMatch, 'package_label' => $packageLabel,
                'price' => is_numeric($price) ? number_format((float) $price, 2, '.', '') : '0.00', 'currency' => $currency,
                'unit_label' => $unitLabel, 'category' => $product->category ?? 'Umumiy', 'image' => null,
                'valid_until' => $validUntil, 'card_required' => false, 'conditions' => '',
                'source_id' => 'catalog', 'source_name' => $product->organization?->name ?? 'Catalog', 'checked_at' => $checkedAt,
                'key' => 'catalog-'.$product->id, 'availability' => 'unknown', 'score' => $score, 'organization_id' => $product->organization_id,
                'stale' => $stale];
        }

        usort($matches, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $products = array_map(fn (array $row): array => array_diff_key($row, [
            'score' => true,
            'organization_id' => true,
            'search_text' => true,
            'stale' => true,
        ]), $matches);

        return ['total' => count($products), 'products' => array_slice($products, 0, 60)];
    }

    private function extractBrand(string $name, array $aliases = []): ?string
    {
        $haystack = implode(' ', [$name, ...$aliases]);
        $brands = ['Apple', 'Samsung', 'Xiaomi', 'Huawei', 'Lenovo', 'ASUS', 'HP', 'Dell', 'LG', 'Sony', 'Philips', 'NVIDIA', 'Zeromax', 'Redmi'];

        foreach ($brands as $brand) {
            if (preg_match('/\b'.preg_quote($brand, '/').'\b/i', $haystack) === 1) {
                return $brand;
            }
        }

        return null;
    }

    private function extractModel(string $name, ?string $brand): ?string
    {
        $clean = trim($name);
        if ($brand !== null && preg_match('/^'.preg_quote($brand, '/').'\s+/i', $clean) === 1) {
            $clean = preg_replace('/^'.preg_quote($brand, '/').'\s+/i', '', $clean);
        }

        $clean = trim(preg_replace('/\s+\d+\s*(GB|gb|MB|mb|TB|tb|inch|inches|"|\")?$/i', '', $clean) ?? $clean);
        if ($clean === '') {
            return null;
        }

        return preg_replace('/\s{2,}/', ' ', $clean) ?: null;
    }

    /**
     * @param  array<int, array{name: string, price: string, currency: string, source_name: string, source_url: string, image: string|null, unit_label: string, checked_at: string|null}>  $products
     */
    public function insight(string $query, array $products): ?array
    {
        $comparableGroups = collect($products)
            ->filter(fn (array $row): bool => ($row['source_id'] ?? null) !== 'statuz'
                && ($row['currency'] ?? null) === 'UZS'
                && preg_match('/^\d+(\.\d+)?$/', (string) ($row['price'] ?? '')) === 1
                && filled($row['name'] ?? null) && filled($row['unit_label'] ?? null))
            ->groupBy(fn (array $row): string => $this->construction->normalize($row['name']).'|'.$this->construction->normalize($row['unit_label']))
            ->filter(fn ($rows): bool => $rows->pluck('source_name')->unique()->count() > 1);
        if ($comparableGroups->count() !== 1) {
            return null;
        }
        $rows = $comparableGroups->first()->map(fn (array $row): array => [...$row, 'price_float' => (float) $row['price']])->values();

        $cheapest = $rows->sortBy('price_float')->first();
        $priciest = $rows->sortByDesc('price_float')->first();
        $extra = (float) $priciest['price_float'] - (float) $cheapest['price_float'];
        if ($cheapest['price_float'] <= 0 || $extra <= 0) {
            return null;
        }
        $percent = round($extra / $cheapest['price_float'] * 100, 1);
        if ($percent < 5.0) {
            return null;
        }

        return ['query' => Str::limit(trim($query), 80), 'best' => $cheapest, 'worst' => $priciest,
            'savings' => bcadd((string) $extra, '0', 2), 'savings_percent' => $percent, 'offers' => $rows->count(),
            'sources' => $rows->pluck('source_name')->unique()->values()->all()];
    }
}
