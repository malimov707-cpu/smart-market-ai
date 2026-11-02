<?php

use App\Services\PublicMarketCatalog;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

function asaxiySitemapXml(array $urls): string
{
    $locations = implode('', array_map(fn (string $url): string => '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>', $urls));

    return '<?xml version="1.0" encoding="UTF-8"?><urlset>'.$locations.'</urlset>';
}

function asaxiyProductHtml(array $products): string
{
    $data = ['@context' => 'https://schema.org', '@graph' => $products];

    return '<script type="application/ld+json">'.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).'</script>';
}

function asaxiyProductNode(string $name, string $price, string $currency = 'UZS', string $availability = 'https://schema.org/InStock'): array
{
    return ['@type' => 'Product', 'name' => $name, 'brand' => ['@type' => 'Brand', 'name' => 'Apple'],
        'image' => 'https://assets.asaxiy.uz/product/test.jpg',
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => $currency, 'availability' => $availability]];
}

it('uses only sitemap-listed Asaxiy product pages for exact UZS offers when enabled', function () {
    config(['services.asaxiy.catalog_enabled' => true]);
    Http::preventStrayRequests();
    Http::fake([
        'https://asaxiy.uz/sitemap.xml' => Http::response(asaxiySitemapXml([
            'https://asaxiy.uz/sitemap-goods1.xml',
            'https://outside.example/sitemap-goods2.xml',
        ])),
        'https://asaxiy.uz/sitemap-goods1.xml' => Http::response(asaxiySitemapXml([
            'https://asaxiy.uz/product/airpods-case',
        ])),
        'https://asaxiy.uz/product/airpods-case' => Http::response(asaxiyProductHtml([
            asaxiyProductNode('Apple AirPods Case', '399000'),
        ])),
    ]);

    $market = app(PublicMarketCatalog::class)->search('AirPods case', [], 'general', 'asaxiy', true);

    expect($market['products'])->toHaveCount(1)
        ->and($market['products'][0]['name'])->toBe('Apple AirPods Case')
        ->and($market['products'][0]['price'])->toBe('399000.00')
        ->and($market['products'][0]['source_url'])->toBe('https://asaxiy.uz/product/airpods-case')
        ->and($market['products'][0]['availability'])->toBe('in_stock')
        ->and($market['partial'])->toBeFalse();
    Http::assertSentCount(3);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://asaxiy.uz/product/airpods-case');
});

it('does not make Asaxiy requests while the catalog is disabled or irrelevant to the selected source', function () {
    Http::preventStrayRequests();
    config(['services.asaxiy.catalog_enabled' => false]);

    $disabled = app(PublicMarketCatalog::class)->search('AirPods case', [], 'general', 'all', true);

    expect($disabled['sources'])->toContain([
        'id' => 'asaxiy',
        'name' => 'Asaxiy',
        'department' => 'general',
        'checked_at' => null,
        'count' => 0,
        'stale' => false,
        'sync_failed' => false,
        'partial' => false,
        'enabled' => false,
        'interval' => 5,
        'on_demand' => true,
    ]);

    config(['services.asaxiy.catalog_enabled' => true]);
    $emptyGeneral = app(PublicMarketCatalog::class)->search('', [], 'general', 'all', true);
    app(PublicMarketCatalog::class)->search('AirPods case', [], 'construction', 'all', true);
    app(PublicMarketCatalog::class)->search('AirPods case', [], 'all', 'korzinka', true);

    expect($emptyGeneral['stale'])->toBeFalse()
        ->and($emptyGeneral['checked_at'])->toBeNull();
    Http::assertNothingSent();
});

it('marks a capped Asaxiy lookup as partial instead of claiming complete cheapest results', function () {
    config(['services.asaxiy.catalog_enabled' => true]);
    Http::preventStrayRequests();
    $productUrls = array_map(fn (int $number): string => "https://asaxiy.uz/product/airpods-case-{$number}", range(1, 7));
    Http::fake([
        'https://asaxiy.uz/sitemap.xml' => Http::response(asaxiySitemapXml(['https://asaxiy.uz/sitemap-goods1.xml'])),
        'https://asaxiy.uz/sitemap-goods1.xml' => Http::response(asaxiySitemapXml($productUrls)),
        'https://asaxiy.uz/product/*' => Http::response(asaxiyProductHtml([
            asaxiyProductNode('Apple AirPods Case', '399000'),
        ])),
    ]);

    $market = app(PublicMarketCatalog::class)->search('AirPods case', [], 'general', 'asaxiy', true);

    expect($market['products'])->toHaveCount(6)
        ->and($market['partial'])->toBeTrue()
        ->and($market['sync_failed'])->toBeFalse()
        ->and($market['sources'][1]['partial'])->toBeTrue();
    Http::assertSentCount(8);
});

it('rejects non-UZS, zero-priced, and out-of-stock structured offers', function () {
    config(['services.asaxiy.catalog_enabled' => true]);
    Http::preventStrayRequests();
    Http::fake([
        'https://asaxiy.uz/sitemap.xml' => Http::response(asaxiySitemapXml(['https://asaxiy.uz/sitemap-goods1.xml'])),
        'https://asaxiy.uz/sitemap-goods1.xml' => Http::response(asaxiySitemapXml([
            'https://asaxiy.uz/product/airpods-case',
        ])),
        'https://asaxiy.uz/product/airpods-case' => Http::response(asaxiyProductHtml([
            asaxiyProductNode('USD AirPods Case', '99', 'USD'),
            asaxiyProductNode('Free AirPods Case', '0'),
            asaxiyProductNode('Unavailable AirPods Case', '399000', 'UZS', 'https://schema.org/OutOfStock'),
        ])),
    ]);

    $market = app(PublicMarketCatalog::class)->search('AirPods case', [], 'general', 'asaxiy', true);

    expect($market['products'])->toBe([])
        ->and($market['sync_failed'])->toBeFalse();
    Http::assertSentCount(3);
});

it('marks unavailable product pages as incomplete and does not treat them as zero-price offers', function () {
    config(['services.asaxiy.catalog_enabled' => true]);
    Http::preventStrayRequests();
    Http::fake([
        'https://asaxiy.uz/sitemap.xml' => Http::response(asaxiySitemapXml(['https://asaxiy.uz/sitemap-goods1.xml'])),
        'https://asaxiy.uz/sitemap-goods1.xml' => Http::response(asaxiySitemapXml([
            'https://asaxiy.uz/product/airpods-case',
        ])),
        'https://asaxiy.uz/product/airpods-case' => Http::response([], 503),
    ]);

    $market = app(PublicMarketCatalog::class)->search('AirPods case', [], 'general', 'asaxiy', true);

    expect($market['products'])->toBe([])
        ->and($market['sync_failed'])->toBeTrue()
        ->and($market['partial'])->toBeTrue();
    Http::assertSentCount(3);
});
