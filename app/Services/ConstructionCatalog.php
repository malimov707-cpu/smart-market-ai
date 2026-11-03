<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ConstructionCatalog
{
    public const SOURCES = ['tezqur' => 'TezQur', 'kslux' => 'KSLux', 'alstroy' => 'Alstroy'];

    public static function key(string $source): string
    {
        return 'public-market:construction:'.$source.':v1';
    }

    public function sync(string $source): int
    {
        abort_unless(isset(self::SOURCES[$source]), 404);

        return Cache::lock(self::key($source).':sync', 180)->block(1, function () use ($source): int {
            try {
                $deadline = microtime(true) + 90;
                $rows = $source === 'tezqur' ? $this->tezqur($deadline) : $this->htmlCatalog($source, $deadline);
                if ($rows === []) {
                    throw new RuntimeException('No usable prices returned.');
                }
                Cache::forever(self::key($source), ['checked_at' => now()->toISOString(), 'products' => array_values($rows)]);
                Cache::forget(self::key($source).':error');

                return count($rows);
            } catch (\Throwable $error) {
                Cache::put(self::key($source).':error', now()->toISOString(), 86400);
                Log::warning('Construction catalog refresh failed.', ['source' => $source, 'exception' => $error]);
                throw new RuntimeException(self::SOURCES[$source].' narxlarini yangilab bo‘lmadi. Oldingi kuzatuv saqlandi.', previous: $error);
            }
        });
    }

    public function snapshot(string $source): array
    {
        return Cache::get(self::key($source), ['checked_at' => null, 'products' => []]) + ['sync_failed' => Cache::has(self::key($source).':error')];
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['цемент', 'гипсокартон', 'шпатлевка', 'шпаклевка', 'краска', 'ламинат', 'герметик', 'клей', 'bo‘yoq', 'boʻyoq', 'shpatlyovka', 'shpaklyovka'],
            ['sement', 'gipsokarton', 'shpaklevka', 'shpaklevka', 'boyoq', 'laminat', 'germetik', 'yelim', 'boyoq', 'boyoq', 'shpaklevka', 'shpaklevka'], $value);

        return trim(preg_replace('/\s+/u', ' ', str_replace(['ʻ', '‘', '’', '`', "'", 'ʼ'], '', $value)));
    }

    private function tezqur(float $deadline): array
    {
        $rows = [];
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            $this->checkDeadline($deadline);
            $result = json_decode($this->get('https://api.tezqur.uz/api/v1/products?granularity=variant&page='.$page.'&limit=50'), true, 40, JSON_THROW_ON_ERROR);
            if (! is_array($result['data'] ?? null) || ! is_int(data_get($result, 'meta.totalPages'))) {
                throw new RuntimeException('Unexpected TezQur schema.');
            }
            $pages = $result['meta']['totalPages'];
            if ($pages < 1 || $pages > 80 || ($result['meta']['page'] ?? null) !== $page) {
                throw new RuntimeException('Unexpected TezQur pagination.');
            }
            foreach ($result['data'] as $item) {
                $price = $this->price($item['discountPrice'] ?? $item['price'] ?? null);
                if ($price === null || ! is_int($item['id'] ?? null) || ! is_int($item['variantId'] ?? null) || ! is_string($item['name'] ?? null)
                    || ! preg_match('/^[a-z0-9-]+$/', $item['slug'] ?? '')) {
                    continue;
                }
                $attributes = collect($item['variantAttributes'] ?? [])->pluck('value')->filter(fn ($value): bool => is_string($value))->implode(' · ');
                $name = $item['name'].($attributes !== '' ? ' · '.$attributes : '');
                $sku = is_string($item['sku'] ?? null) ? $item['sku'] : '';
                $id = 'tezqur:'.$item['variantId'];
                $image = $item['imageUrl'] ?? '';
                $rows[$id] = $this->row($id, $name, $price, (string) ($item['unit'] ?? 'birlik ko‘rsatilmagan'),
                    collect($item['categories'] ?? [])->pluck('name')->filter()->implode(' · '),
                    'https://tezqur.uz/uz/product/'.$item['slug'].'?variant='.$item['variantId'],
                    is_string($image) && preg_match('#^/api/v1/files/\d+$#', $image) ? 'https://api.tezqur.uz'.$image : null,
                    'tezqur', 'SKU: '.$sku.'. Tanlangan variant narxi; boshqa variantlar farq qilishi mumkin.',
                    isset($item['inStock']) ? ($item['inStock'] ? 'in_stock' : 'out_of_stock') : 'unknown');
                $rows[$id]['search_text'] = $this->normalize($name.' '.($item['nameUz'] ?? '').' '.$sku);
            }
        }

        return $rows;
    }

    private function htmlCatalog(string $source, float $deadline): array
    {
        $paths = $source === 'kslux' ? ['/catalog/stroitelnye-materialy/', '/catalog/mdf-hdf-fanera/']
            : ['/catalog/laminat/', '/catalog/elektricheskie_teplye_poly/', '/catalog/nagrevatelnye_maty/', '/catalog/potolochnye_plintusy/'];
        $host = $source === 'kslux' ? 'https://kslux.uz' : 'https://alstroy.uz';
        $rows = [];
        foreach ($paths as $path) {
            $pages = 1;
            for ($page = 1; $page <= $pages; $page++) {
                $this->checkDeadline($deadline);
                $html = $this->get($host.$path.($page > 1 ? '?PAGEN_1='.$page : ''));
                $previousErrors = libxml_use_internal_errors(true);
                try {
                    $document = new DOMDocument;
                    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
                    $xpath = new DOMXPath($document);
                    $cards = $xpath->query($source === 'kslux' ? '//*[@data-entity="item"]' : '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-item ")]');
                    foreach ($cards as $card) {
                        $nameNode = $xpath->query($source === 'kslux' ? './/a[contains(concat(" ",normalize-space(@class)," ")," name ")]' : './/*[@class="catalog-section-item-name"]/a', $card)->item(0);
                        if (! $nameNode instanceof DOMElement) {
                            continue;
                        }
                        $href = $nameNode->getAttribute('href');
                        $priceText = $this->text($xpath, $card, $source === 'kslux' ? './/*[@data-entity="price-block"]/*[contains(concat(" ",normalize-space(@class)," ")," price ")]' : './/*[@data-role="item.price.discount"]');
                        if (! str_contains($priceText, 'UZS') || ! preg_match('/^([\d\s\x{00a0}]+(?:[.,]\d{1,2})?)\s*UZS/u', trim($priceText), $match)
                            || ! str_starts_with($href, '/catalog/') || str_contains($href, '..')) {
                            continue;
                        }
                        $price = $this->price($match[1]);
                        if ($price === null) {
                            continue;
                        }
                        $imageNode = $xpath->query($source === 'kslux' ? './/*[@data-role="lazyload-background"]' : './/img[@data-original]', $card)->item(0);
                        $image = $imageNode instanceof DOMElement ? $imageNode->getAttribute($source === 'kslux' ? 'data-src' : 'data-original') : '';
                        $unit = $this->text($xpath, $card, $source === 'kslux' ? './/*[contains(concat(" ",normalize-space(@class)," ")," measure ")]' : './/*[@data-role="item.price.measure"]');
                        $stock = $this->text($xpath, $card, $source === 'kslux' ? './/*[contains(concat(" ",normalize-space(@class)," ")," in-stock ")]' : './/*[@data-role="item.quantity"]');
                        $availability = str_contains($stock, 'В наличии') || str_contains($stock, 'Осталось мало') ? 'in_stock' : 'unknown';
                        $id = $source.':'.sha1($href);
                        $rows[$id] = $this->row($id, trim($nameNode->textContent), $price, $unit ?: 'Birlik manbada aniqlanmagan',
                            $source === 'kslux' ? 'Qurilish va yog‘och materiallari' : 'Laminat, issiq pol va pardozlash',
                            $host.$href, str_starts_with($image, '/upload/') ? $host.$image : null, $source,
                            'Ommaviy katalog narxi. Narx birligi va variantini sotuvchida tekshiring.', $availability);
                    }
                    foreach ($xpath->query('//a[contains(@href,"PAGEN_1=")]') as $link) {
                        if (preg_match('/[?&]PAGEN_1=(\d+)/', $link->getAttribute('href'), $match)) {
                            $pages = max($pages, min(6, (int) $match[1]));
                        }
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previousErrors);
                }
            }
        }

        return $rows;
    }

    private function row(string $id, string $name, string $price, string $unit, string $category, string $url, ?string $image, string $source, string $conditions, string $availability): array
    {
        return ['id' => $id, 'name' => mb_substr($name, 0, 250), 'price' => $price, 'currency' => 'UZS', 'unit_label' => mb_substr($unit, 0, 100),
            'category' => mb_substr($category, 0, 150), 'image' => $image, 'valid_until' => null, 'card_required' => false,
            'conditions' => $conditions, 'source_url' => $url, 'source_id' => $source, 'source_name' => self::SOURCES[$source],
            'availability' => $availability, 'search_text' => $this->normalize($name)];
    }

    private function text(DOMXPath $xpath, DOMNode $card, string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', $xpath->query($query, $card)->item(0)?->textContent ?? ''));
    }

    private function price(mixed $price): ?string
    {
        if (! is_string($price) && ! is_int($price)) {
            return null;
        }
        $price = str_replace(',', '.', preg_replace('/[\s\x{00a0}]+/u', '', (string) $price));

        return preg_match('/^\d{1,12}(\.\d{1,2})?$/', $price) && bccomp($price, '0', 2) > 0 ? bcadd($price, '0', 2) : null;
    }

    private function checkDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Source deadline reached.');
        }
    }

    private function get(string $url): string
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            $response = Http::withHeaders(['Accept-Language' => 'uz-latn'])->connectTimeout(3)->timeout(10)->withoutRedirecting()
                ->withOptions(['sink' => $stream, 'progress' => function (int $total, int $downloaded): void {
                    if ($total > 4194304 || $downloaded > 4194304) {
                        throw new RuntimeException('Source response too large.');
                    }
                }])->get($url);
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

            return $body;
        } finally {
            fclose($stream);
        }
    }
}
