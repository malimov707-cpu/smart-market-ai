<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
        $total = ! in_array($department, ['construction', 'general'], true) && in_array($source, ['all', 'korzinka'], true) ? $grocery['total'] : 0;
        if ($shouldSearchStatUz) {
            $groups[] = $statUz['products'];
            $total += $statUz['total'];
        }
        if ($shouldSearchAsaxiy) {
            $groups[] = $asaxiy['products'];
            $total += $asaxiy['total'];
        }
        $needles = array_values(array_filter(array_map($this->construction->normalize(...), array_slice([$query, ...$alternatives], 0, 4))));
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
                    $products[] = $group[$index];
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
                && ($checked === null || $active->whereNotNull('checked_at')->contains('stale', true)),
            'sync_failed' => $active->contains('sync_failed', true), 'partial' => $active->contains('partial', true),
            'total' => $total, 'products' => $products, 'sources' => $sources,
            'coverage' => 'Ochiq sotuvchi kataloglari · O‘zbekiston; qamrov to‘liq emas, narx va mavjudlikni sotuvchi sahifasida tekshiring'];
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
