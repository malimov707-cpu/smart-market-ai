<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class PublicMarketCatalog
{
    public function __construct(private KorzinkaCatalog $korzinka, private ConstructionCatalog $construction) {}

    public function search(string $query = '', array $alternatives = [], string $department = 'all', string $source = 'all'): array
    {
        $grocery = $this->korzinka->search($query, $alternatives);
        $rawGrocery = Cache::get(KorzinkaCatalog::CACHE_KEY);
        $sources = [['id' => 'korzinka', 'name' => 'Korzinka', 'department' => 'grocery', 'checked_at' => $grocery['checked_at'],
            'count' => count($rawGrocery['products'] ?? []), 'stale' => $grocery['stale'], 'sync_failed' => $grocery['sync_failed'], 'interval' => 5]];
        $groups = [];
        if ($department !== 'construction' && in_array($source, ['all', 'korzinka'], true)) {
            $groups[] = collect($grocery['products'])->map(fn (array $row): array => [...$row, 'key' => 'korzinka:'.$row['id'],
                'source_id' => 'korzinka', 'source_name' => 'Korzinka', 'checked_at' => $grocery['checked_at'], 'availability' => 'unknown'])->all();
        }
        $total = $department !== 'construction' && in_array($source, ['all', 'korzinka'], true) ? $grocery['total'] : 0;
        $needles = array_values(array_filter(array_map($this->construction->normalize(...), array_slice([$query, ...$alternatives], 0, 4))));
        foreach (ConstructionCatalog::SOURCES as $id => $name) {
            $snapshot = $this->construction->snapshot($id);
            $checked = $snapshot['checked_at'];
            $stale = $checked === null || CarbonImmutable::parse($checked)->lt(now()->subMinutes(30));
            $sources[] = ['id' => $id, 'name' => $name, 'department' => 'construction', 'checked_at' => $checked, 'count' => count($snapshot['products']),
                'stale' => $stale, 'sync_failed' => $snapshot['sync_failed'], 'interval' => 15];
            if ($department === 'grocery' || ! in_array($source, ['all', $id], true)) {
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
        $active = collect($sources)->filter(fn (array $row): bool => ($department === 'all' || $row['department'] === $department) && ($source === 'all' || $row['id'] === $source));
        $checked = $active->pluck('checked_at')->filter()->sort()->first();

        return ['checked_at' => $checked, 'stale' => $checked === null || $active->whereNotNull('checked_at')->contains('stale', true),
            'sync_failed' => $active->contains('sync_failed', true), 'total' => $total, 'products' => $products, 'sources' => $sources,
            'coverage' => 'Ommaviy katalog narxlari · Toshkent; manbalar qamrovi cheklangan, yetkazish narxi kiritilmagan'];
    }
}
