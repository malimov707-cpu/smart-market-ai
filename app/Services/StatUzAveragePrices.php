<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class StatUzAveragePrices
{
    public const CACHE_KEY = 'public-market:statuz:tashkent-market-averages:v1';

    public const STALE_CACHE_KEY = self::CACHE_KEY.':stale';

    private const API_URL = 'https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json';

    private const SOURCE_URL = 'https://stat.uz/uz/rasmiy-statistika/prices-and-indexes-2';

    /**
     * @return array{checked_at: string|null, stale: bool, sync_failed: bool, partial: bool, data_period: string|null, total: int, dataset_total: int, products: list<array<string, mixed>>}
     */
    public function search(string $query, array $alternatives = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->emptyResult();
        }

        try {
            $snapshot = Cache::lock(self::CACHE_KEY.':sync', 30)->block(2, function (): array {
                $snapshot = Cache::remember(self::CACHE_KEY, now()->addHours(12), fn (): array => $this->fetchSnapshot());
                Cache::put(self::STALE_CACHE_KEY, $snapshot, now()->addDays(7));

                return $snapshot;
            });
        } catch (Throwable $error) {
            Log::warning('Stat.uz public average-price lookup failed.', ['exception' => $error]);
            $snapshot = Cache::get(self::STALE_CACHE_KEY);
            if (! is_array($snapshot)) {
                return [...$this->emptyResult(), 'stale' => true, 'sync_failed' => true, 'partial' => true];
            }

            $snapshot['stale'] = true;
            $snapshot['sync_failed'] = true;
            $snapshot['partial'] = true;
        }

        $terms = collect([$query, ...$alternatives])
            ->filter(fn ($term): bool => is_string($term))
            ->map(fn (string $term): string => $this->normalize($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->take(6);

        $products = collect($snapshot['products'])->filter(function (array $row) use ($terms): bool {
            $labels = collect($row['search_labels'] ?? [])
                ->map(fn (string $label): string => $this->normalize($label))
                ->filter();

            return $terms->contains(fn (string $term): bool => $labels->contains(
                fn (string $label): bool => str_contains($label, $term) || str_contains($term, $label),
            ));
        })->map(function (array $row): array {
            unset($row['search_labels']);

            return $row;
        })->values()->all();
        $dataPeriod = collect($products)->pluck('data_period')->filter()->sortDesc()->first()
            ?? $snapshot['data_period'];

        return [...$snapshot, 'total' => count($products), 'dataset_total' => count($snapshot['products']),
            'products' => $products, 'data_period' => $dataPeriod];
    }

    /**
     * @return array{checked_at: string, stale: bool, sync_failed: bool, partial: bool, data_period: string|null, products: list<array<string, mixed>>}
     */
    private function fetchSnapshot(): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(12)
            ->get(self::API_URL);

        if (! $response->successful() || strlen($response->body()) > 5_000_000) {
            throw new RuntimeException('Stat.uz price dataset is unavailable or too large.');
        }

        $payload = $response->json();
        $dataset = is_array($payload) ? ($payload[0] ?? null) : null;
        if (! is_array($dataset) || ! is_array($dataset['metadata'] ?? null) || ! is_array($dataset['data'] ?? null)) {
            throw new RuntimeException('Stat.uz returned an unexpected average-price dataset.');
        }

        $updatedAt = collect($dataset['metadata'])->first(fn ($item): bool => is_array($item)
            && ($item['name_uz'] ?? null) === "So'nggi o'zgartirilgan sana")['value_uz'] ?? null;
        if (! is_string($updatedAt) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedAt)) {
            throw new RuntimeException('Stat.uz average-price dataset has no valid update date.');
        }

        $products = [];
        foreach ($dataset['data'] as $row) {
            if (! is_array($row) || ! is_string($row['Code'] ?? null) || ! is_string($row['Klassifikator'] ?? null)) {
                continue;
            }

            $periods = collect($row)->filter(fn ($value, string $key): bool => preg_match('/^20\d{2}-M(?:0[1-9]|1[0-2])$/', $key) === 1
                && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
                && is_finite((float) $value) && (float) $value > 0);
            if ($periods->isEmpty()) {
                continue;
            }

            $period = $periods->keys()->sortDesc()->first();
            $price = $periods->get($period);
            [$name, $unit] = $this->nameAndUnit($row['Klassifikator']);
            $labels = array_values(array_filter([
                $row['Klassifikator'],
                $row['Klassifikator_ru'] ?? null,
                $row['Klassifikator_en'] ?? null,
                $row['Klassifikator_uzc'] ?? null,
            ], 'is_string'));

            $products[] = [
                'id' => 'statuz:tashkent:'.$row['Code'],
                'key' => 'statuz:tashkent:'.$row['Code'],
                'name' => $name,
                'price' => bcadd((string) $price, '0', 2),
                'currency' => 'UZS',
                'unit_label' => $unit,
                'category' => 'Rasmiy oylik o‘rtacha narx',
                'image' => null,
                'valid_until' => null,
                'card_required' => false,
                'conditions' => 'Toshkent shahar bozorlarida kuzatilgan oylik o‘rtacha narx; sotuvchining ayni vaqtdagi taklif narxi emas.',
                'source_url' => self::SOURCE_URL,
                'source_id' => 'statuz',
                'source_name' => 'Milliy statistika · Toshkent bozorlari',
                'availability' => 'unknown',
                'checked_at' => CarbonImmutable::parse($updatedAt)->toISOString(),
                'data_period' => $period,
                'search_labels' => $labels,
            ];
        }

        if ($products === []) {
            throw new RuntimeException('Stat.uz average-price dataset contains no valid observations.');
        }

        $latestPeriod = collect($products)->pluck('data_period')->sortDesc()->first();

        return [
            'checked_at' => CarbonImmutable::parse($updatedAt)->toISOString(),
            'stale' => CarbonImmutable::parse($updatedAt)->lt(now()->subDays(45)),
            'sync_failed' => false,
            'partial' => false,
            'data_period' => $latestPeriod,
            'products' => $products,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function nameAndUnit(string $label): array
    {
        if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $label, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [trim($label), '1 kg'];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['ʻ', '‘', '’', '`', "'", 'ʼ'], '', $value))));
    }

    /**
     * @return array{checked_at: null, stale: false, sync_failed: false, partial: false, data_period: null, total: 0, dataset_total: 0, products: list<never>}
     */
    private function emptyResult(): array
    {
        return ['checked_at' => null, 'stale' => false, 'sync_failed' => false, 'partial' => false,
            'data_period' => null, 'total' => 0, 'dataset_total' => 0, 'products' => []];
    }
}
