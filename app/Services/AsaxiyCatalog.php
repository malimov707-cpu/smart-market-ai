<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AsaxiyCatalog
{
    public const INDEX_KEY = 'public-market:asaxiy:index:v1';

    private const SITEMAP_INDEX_URL = 'https://asaxiy.uz/sitemap.xml';

    private const MAX_SITEMAPS = 10;

    private const MAX_PRODUCT_PAGES = 6;

    /**
     * @return array{checked_at: string|null, stale: bool, sync_failed: bool, partial: bool, total: int, products: list<array<string, mixed>>}
     */
    public function search(string $query, array $alternatives = []): array
    {
        $query = trim($query);
        $errorKey = self::INDEX_KEY.':error';
        if ($query === '') {
            return ['checked_at' => null, 'stale' => false, 'sync_failed' => false, 'partial' => false, 'total' => 0, 'products' => []];
        }

        $cacheKey = $this->queryCacheKey($query, $alternatives);
        $previous = Cache::get($cacheKey.':stale');
        if (($cached = Cache::get($cacheKey)) !== null) {
            return [...$cached, 'partial' => $cached['partial'] ?? false];
        }

        try {
            $index = Cache::get(self::INDEX_KEY);
            if (! is_array($index) || ! is_array($index['products'] ?? null)) {
                if (Cache::has($errorKey)) {
                    throw new RuntimeException('Asaxiy katalog indeksi hozircha mavjud emas.');
                }
                $this->syncIndex();
                $index = Cache::get(self::INDEX_KEY);
            }
            if (! is_array($index) || ! is_array($index['products'] ?? null)) {
                throw new RuntimeException('Asaxiy katalog indeksi bo‘sh.');
            }

            $matches = $this->matchingProducts($index['products'], [$query, ...$alternatives]);
            if ($matches === []) {
                $result = ['checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false,
                    'partial' => false, 'total' => 0, 'products' => []];
                Cache::put($cacheKey, $result, now()->addMinutes(5));
                Cache::put($cacheKey.':stale', $result, now()->addDay());
                Cache::forget($errorKey);

                return $result;
            }
            [$rows, $syncFailed] = $this->fetchProducts(array_slice($matches, 0, self::MAX_PRODUCT_PAGES));
            usort($rows, fn (array $left, array $right): int => (float) $left['price'] <=> (float) $right['price']);

            $partial = count($matches) > self::MAX_PRODUCT_PAGES || $syncFailed;
            $result = ['checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => $syncFailed,
                'partial' => $partial,
                'total' => count($rows), 'products' => $rows];
            Cache::put($cacheKey, $result, now()->addMinutes(5));
            Cache::put($cacheKey.':stale', $result, now()->addDay());
            if ($syncFailed) {
                Cache::put($errorKey, now()->toISOString(), now()->addMinutes(5));
            } else {
                Cache::forget($errorKey);
            }

            return $result;
        } catch (Throwable $error) {
            Cache::put($errorKey, now()->toISOString(), now()->addMinutes(5));
            Log::warning('Asaxiy public catalogue lookup failed.', ['exception' => $error]);

            if (is_array($previous)) {
                return [...$previous, 'stale' => true, 'sync_failed' => true];
            }

            return ['checked_at' => null, 'stale' => true, 'sync_failed' => true, 'partial' => true, 'total' => 0, 'products' => []];
        }
    }

    public function syncIndex(): int
    {
        return Cache::lock(self::INDEX_KEY.':sync', 90)->block(2, function (): int {
            try {
                $indexResponse = Http::accept('application/xml')->connectTimeout(3)->timeout(10)->withoutRedirecting()->get(self::SITEMAP_INDEX_URL);
                if (! $indexResponse->successful() || strlen($indexResponse->body()) > 1_000_000) {
                    throw new RuntimeException('Asaxiy sitemap index is unavailable or too large.');
                }

                $sitemaps = array_values(array_filter($this->xmlLocations($indexResponse->body()),
                    fn (string $url): bool => preg_match('#^https://asaxiy\.uz/sitemap-goods\d+\.xml$#', $url) === 1));
                $sitemaps = array_slice(array_unique($sitemaps), 0, self::MAX_SITEMAPS);
                if ($sitemaps === []) {
                    throw new RuntimeException('Asaxiy sitemap index has no allowed product catalogues.');
                }

                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $url, int $index) => $pool->as((string) $index)->accept('application/xml')
                        ->connectTimeout(3)->timeout(12)->withoutRedirecting()->get($url),
                    $sitemaps,
                    array_keys($sitemaps),
                ), concurrency: 3);

                $products = [];
                foreach ($responses as $response) {
                    if ($response instanceof Throwable || ! $response instanceof Response || ! $response->successful()
                        || strlen($response->body()) > 4_000_000) {
                        throw new RuntimeException('An Asaxiy product sitemap could not be loaded.');
                    }
                    foreach ($this->xmlLocations($response->body()) as $url) {
                        if (preg_match('#^https://asaxiy\.uz/product/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $url, $match) === 1) {
                            $products[$url] = ['url' => $url, 'slug' => $match[1]];
                        }
                    }
                }
                if ($products === []) {
                    throw new RuntimeException('Asaxiy product sitemaps contain no supported product URLs.');
                }

                $snapshot = ['checked_at' => now()->toISOString(), 'products' => array_values($products)];
                Cache::put(self::INDEX_KEY.':stale', $snapshot, now()->addDays(3));
                Cache::put(self::INDEX_KEY, $snapshot, now()->addHours(12));
                Cache::forget(self::INDEX_KEY.':error');

                return count($products);
            } catch (Throwable $error) {
                Cache::put(self::INDEX_KEY.':error', now()->toISOString(), now()->addMinutes(5));
                throw new RuntimeException('Asaxiy mahsulotlar katalogi yangilanmadi.', previous: $error);
            }
        });
    }

    /**
     * @param  list<array{url: string, slug: string}>  $products
     * @param  list<string>  $terms
     * @return list<array{url: string, slug: string, score: int}>
     */
    private function matchingProducts(array $products, array $terms): array
    {
        $needles = collect($terms)->filter(fn ($term): bool => is_string($term))
            ->map(fn (string $term): array => array_values(array_diff(explode('-', Str::slug($term)), ['uchun', 'dlya', 'the', 'for', 'and', 'va'])))
            ->filter(fn (array $tokens): bool => $tokens !== [])
            ->values()
            ->all();

        $matches = [];
        foreach ($products as $product) {
            if (! is_array($product) || ! is_string($product['url'] ?? null) || ! is_string($product['slug'] ?? null)) {
                continue;
            }
            $slugTokens = explode('-', $product['slug']);
            $score = 0;
            foreach ($needles as $tokens) {
                $matched = 0;
                foreach ($tokens as $token) {
                    foreach ($slugTokens as $slugToken) {
                        if ($token === $slugToken || (strlen($token) >= 6 && levenshtein($token, $slugToken) <= 1)) {
                            $matched++;
                            break;
                        }
                    }
                }
                if ($matched >= min(2, count($tokens))) {
                    $score = max($score, $matched * 10 + (int) (100 * $matched / count($tokens)));
                }
            }
            if ($score > 0) {
                $matches[] = [...$product, 'score' => $score];
            }
        }

        usort($matches, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $matches;
    }

    /**
     * @param  list<array{url: string, slug: string, score: int}>  $candidates
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function fetchProducts(array $candidates): array
    {
        if ($candidates === []) {
            return [[], false];
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (array $product, int $index) => $pool->as((string) $index)->accept('text/html')
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()->get($product['url']),
            $candidates,
            array_keys($candidates),
        ), concurrency: 3);

        $rows = [];
        $syncFailed = false;
        foreach ($responses as $index => $response) {
            if ($response instanceof Throwable || ! $response instanceof Response || ! $response->successful()
                || strlen($response->body()) > 2_000_000) {
                $syncFailed = true;

                continue;
            }
            $row = $this->productFromPage($response->body(), $candidates[(int) $index]['url']);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return [$rows, $syncFailed];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productFromPage(string $html, string $url): ?array
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML($html, LIBXML_NONET)) {
                return null;
            }
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
                $data = json_decode($script->textContent, true);
                foreach ($this->productNodes($data) as $product) {
                    $offer = $product['offers'] ?? null;
                    if (is_array($offer) && array_is_list($offer)) {
                        $offer = $offer[0] ?? null;
                    }
                    if (! is_array($offer) || ($offer['priceCurrency'] ?? null) !== 'UZS'
                        || str_ends_with((string) ($offer['availability'] ?? ''), 'OutOfStock')) {
                        continue;
                    }
                    $price = $offer['price'] ?? $offer['lowPrice'] ?? null;
                    if ((! is_string($price) && ! is_int($price) && ! is_float($price))
                        || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', (string) $price) || bccomp((string) $price, '0', 2) <= 0
                        || ! is_string($product['name'] ?? null) || trim($product['name']) === '') {
                        continue;
                    }
                    $brand = data_get($product, 'brand.name', $product['brand'] ?? null);
                    $image = is_array($product['image'] ?? null) ? ($product['image'][0] ?? null) : ($product['image'] ?? null);
                    if (! is_string($image) || ! str_starts_with($image, 'https://assets.asaxiy.uz/product/')) {
                        $image = null;
                    }

                    return ['id' => 'asaxiy:'.basename($url), 'name' => trim($product['name']),
                        'price' => bcadd((string) $price, '0', 2), 'currency' => 'UZS', 'unit_label' => '1 dona · katalogdagi mahsulot',
                        'category' => 'Umumiy mahsulotlar', 'image' => $image, 'valid_until' => null, 'card_required' => false,
                        'conditions' => 'Asaxiy katalogidagi narx; xariddan oldin mahsulot sahifasida tekshiring.',
                        'source_url' => $url, 'source_id' => 'asaxiy', 'source_name' => 'Asaxiy',
                        'availability' => str_ends_with((string) ($offer['availability'] ?? ''), 'InStock') ? 'in_stock' : 'unknown',
                        'checked_at' => now()->toISOString(), 'brand' => is_string($brand) ? $brand : null];
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productNodes(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        $nodes = array_is_list($data) ? $data : [$data];
        $products = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $types = (array) ($node['@type'] ?? []);
            if (in_array('Product', $types, true)) {
                $products[] = $node;
            }
            if (isset($node['@graph'])) {
                array_push($products, ...$this->productNodes($node['@graph']));
            }
        }

        return $products;
    }

    /**
     * @return list<string>
     */
    private function xmlLocations(string $xml): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('Asaxiy sitemap XML is invalid.');
            }
            $locations = [];
            foreach ((new DOMXPath($document))->query('//*[local-name()="loc"]') as $node) {
                $value = trim($node->textContent);
                if ($value !== '') {
                    $locations[] = $value;
                }
            }

            return $locations;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function queryCacheKey(string $query, array $alternatives): string
    {
        return 'public-market:asaxiy:query:'.hash('sha256', mb_strtolower(json_encode([$query, $alternatives], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
