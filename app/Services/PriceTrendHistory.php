<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Stores the cheapest observed price per normalized query per day so the
 * UI can draw a trend line without a database table.
 */
class PriceTrendHistory
{
    private const KEY = 'public-market:trend:v1';

    private const DAYS = 30;

    /**
     * @param  array<int, array{price: string}>  $products
     */
    public function record(string $query, array $products, CarbonImmutable $on = new CarbonImmutable): void
    {
        $cheapest = collect($products)
            ->filter(fn (array $row): bool => preg_match('/^\d+(\.\d+)?$/', (string) $row['price']) === 1)
            ->map(fn (array $row): float => (float) $row['price'])
            ->filter(fn (float $price): bool => $price > 0)
            ->min();
        if ($cheapest === null) {
            return;
        }
        $needle = $this->needle($query);
        if ($needle === '') {
            return;
        }
        $history = Cache::get(self::KEY, []);
        $history[$needle][$on->format('Y-m-d')] = $cheapest;
        $history[$needle] = $this->slice($history[$needle]);
        Cache::forever(self::KEY, $history);
    }

    /**
     * @return array<int, array{date: string, price: float}>
     */
    public function series(string $query, int $points = 14): array
    {
        $needle = $this->needle($query);
        $history = Cache::get(self::KEY, [])[$needle] ?? [];
        ksort($history);
        $history = array_slice($history, -$points, null, true);
        $series = array_map(fn (string $date, float $price): array => ['date' => $date, 'price' => $price],
            array_keys($history), array_values($history));

        return $series;
    }

    /**
     * Percent change between the two most recent distinct price points; null when not enough data.
     */
    public function change(string $query): ?float
    {
        $series = $this->series($query, 2);
        if (count($series) < 2 || $series[0]['price'] <= 0) {
            return null;
        }

        return round(($series[1]['price'] - $series[0]['price']) / $series[0]['price'] * 100, 1);
    }

    /**
     * Deal window: current cheapest price is a true drop — at least $percent below the
     * average of all previous days. Needs 3+ days of history.
     *
     * @return array{query: string, price: float, percent: float, previous_average: float}|null
     */
    public function deal(string $query, float $percent = 10.0): ?array
    {
        $series = $this->series($query);
        if (count($series) < 3) {
            return null;
        }
        $current = $series[count($series) - 1];
        $previous = array_slice($series, 0, -1);
        $average = array_sum(array_column($previous, 'price')) / count($previous);
        if ($average <= 0 || $current['price'] >= $average) {
            return null;
        }
        $drop = round(($average - $current['price']) / $average * 100, 1);
        if ($drop < $percent) {
            return null;
        }

        return ['query' => $query, 'price' => $current['price'],
            'percent' => $drop, 'previous_average' => round($average, 2)];
    }

    private function slice(array $days): array
    {
        ksort($days);

        return array_slice($days, -self::DAYS, null, true);
    }

    private function needle(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($query)));
    }
}
