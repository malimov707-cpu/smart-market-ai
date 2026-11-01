<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class KorzinkaCatalog
{
    public const CACHE_KEY = 'public-market:korzinka:tashkent:v1';

    /** The website's public catalogue is not a guaranteed partner API or branch inventory feed. */
    public function sync(): int
    {
        return Cache::lock(self::CACHE_KEY.':sync', 240)->block(1, function (): int {
            try {
                $catalogs = $this->fetch('/mobile/catalogs-list')['data'] ?? null;
                if (! is_array($catalogs) || $catalogs === []) {
                    throw new RuntimeException('Korzinka katalog formati o‘zgargan.');
                }
                $today = now('Asia/Tashkent')->format('Y-m-d');
                $rows = [];
                $deadline = microtime(true) + 60;
                $requests = 0;
                foreach ($catalogs as $catalog) {
                    if (($catalog['is_special'] ?? 1) !== 0 || ! ($catalog['status'] ?? false) || ($catalog['start_date'] ?? '9999') > $today || ($catalog['end_date'] ?? '0000') < $today) {
                        continue;
                    }
                    foreach ($catalog['categories'] ?? [] as $category) {
                        if (microtime(true) >= $deadline || ++$requests > 40 || ! is_int($category['get_products'] ?? null)) {
                            throw new RuntimeException('Katalog chegarasi yoki formati o‘zgargan.');
                        }
                        $products = $this->fetch('/mobile/catalogs/category/products', ['get_products' => $category['get_products']])['data'] ?? null;
                        if (! is_array($products)) {
                            throw new RuntimeException('Mahsulotlar formati o‘zgargan.');
                        }
                        foreach ($products as $product) {
                            $price = $this->price(data_get($product, 'prices.actual_price'));
                            $name = $product['title_uz'] ?? $product['title'] ?? null;
                            if (! is_int($product['id'] ?? null) || ! is_string($name) || $price === null || ($product['is_banner'] ?? false)) {
                                continue;
                            }
                            $start = $catalog['start_date'];
                            $end = $catalog['end_date'];
                            foreach ($product['promotion_tags'] ?? [] as $tag) {
                                if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})$/', $tag['value'] ?? '', $dates)) {
                                    $start = max($start, CarbonImmutable::createFromFormat('!d.m.Y', $dates[1], 'Asia/Tashkent')->format('Y-m-d'));
                                    $end = min($end, CarbonImmutable::createFromFormat('!d.m.Y', $dates[2], 'Asia/Tashkent')->format('Y-m-d'));
                                }
                            }
                            if ($start > $today || $end < $today) {
                                continue;
                            }
                            $image = $product['small_image_url'] ?? '';
                            $rows[$product['id']] = ['id' => $product['id'], 'name' => Str::limit($name, 200),
                                'search_text' => $this->normalize($name.' '.($product['title_ru'] ?? '').' '.($product['title_en'] ?? '')),
                                'price' => $price, 'currency' => 'UZS', 'unit_label' => Str::limit((string) ($product['weight_param'] ?? data_get($product, 'prices.product_type', 'qadoq')), 60),
                                'category' => Str::limit((string) ($category['title_uz'] ?? $category['title'] ?? ''), 120),
                                'image' => is_string($image) && str_starts_with($image, 'https://catalog.korzinka.uz/storage/') ? $image : null,
                                'valid_until' => CarbonImmutable::parse($end, 'Asia/Tashkent')->endOfDay()->toISOString(),
                                'card_required' => (bool) ($product['discount_by_card'] ?? false),
                                'conditions' => Str::limit((string) data_get($product, 'prices.promotion_condition', ''), 200),
                                'source_url' => 'https://www.korzinka.uz/catalog'];
                        }
                    }
                }
                if ($rows === []) {
                    throw new RuntimeException('Amaldagi katalogda narxlar topilmadi.');
                }
                Cache::forever(self::CACHE_KEY, ['checked_at' => now()->toISOString(), 'products' => array_values($rows)]);
                Cache::forget(self::CACHE_KEY.':error');

                return count($rows);
            } catch (\Throwable $error) {
                Cache::put(self::CACHE_KEY.':error', now()->toISOString(), 86400);
                throw new RuntimeException('Korzinka katalogini yangilab bo‘lmadi. Oxirgi muvaffaqiyatli kuzatuv saqlandi.');
            }
        });
    }

    public function search(string $query = '', array $alternatives = []): array
    {
        $snapshot = Cache::get(self::CACHE_KEY);
        $checked = $snapshot['checked_at'] ?? null;
        $needles = array_values(array_filter(array_map($this->normalize(...), array_slice([$query, ...$alternatives], 0, 4))));
        $products = collect($snapshot['products'] ?? [])->filter(fn (array $row): bool => CarbonImmutable::parse($row['valid_until'])->isFuture());
        if ($needles !== []) {
            $products = $products->filter(function (array $row) use ($needles): bool {
                foreach ($needles as $needle) {
                    if (str_contains($row['search_text'], $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        return ['checked_at' => $checked, 'stale' => $checked === null || CarbonImmutable::parse($checked)->lt(now()->subMinutes(10)),
            'sync_failed' => Cache::has(self::CACHE_KEY.':error'), 'total' => $products->count(),
            'products' => $products->take(60)->map(fn (array $row): array => array_diff_key($row, ['search_text' => true]))->values(),
            'coverage' => 'Korzinka ommaviy katalogi · Toshkent shahri va viloyati; filialdagi mavjudlik tekshirilmagan'];
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(['ʻ', '‘', '’', '`', "'", 'ʼ'], '', $value))));
    }

    private function price(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = str_replace(',', '.', preg_replace('/[\s\x{00a0}]+/u', '', (string) $value));

        return preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) && bccomp($value, '0', 2) > 0 ? bcadd($value, '0', 2) : null;
    }

    private function fetch(string $path, ?array $payload = null): array
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            $request = Http::acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting()->withOptions([
                'sink' => $stream, 'progress' => function (int $total, int $downloaded): void {
                    if ($total > 4194304 || $downloaded > 4194304) {
                        throw new RuntimeException('Source response too large.');
                    }
                },
            ]);
            $url = 'https://catalog.korzinka.uz/api'.$path;
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
            if (! $response->successful()) {
                throw new RuntimeException('Source unavailable.');
            }
            rewind($stream);
            $body = stream_get_contents($stream, 4194305);
            if ($body === '' && app()->environment('testing')) {
                $body = $response->body();
            }
            if (strlen($body) > 4194304) {
                throw new RuntimeException('Source response too large.');
            }
            $result = json_decode($body, true, 40, JSON_THROW_ON_ERROR);
            if (! is_array($result) || ($result['code'] ?? null) !== 200) {
                throw new RuntimeException('Unexpected source response.');
            }

            return $result;
        } finally {
            fclose($stream);
        }
    }
}
