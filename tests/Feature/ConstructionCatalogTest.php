<?php

use App\Services\ConstructionCatalog;
use App\Services\PublicMarketCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
});

it('loads paginated TezQur SKU prices with exact unit variant and availability', function () {
    Http::fake(function (ClientRequest $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];

        return Http::response(['meta' => ['page' => $page, 'totalPages' => 2], 'data' => [
            ['id' => 10, 'variantId' => $page, 'name' => 'GLORIA gipsokarton 12 mm', 'nameUz' => 'GLORIA gipsokarton', 'slug' => 'gloria-gipsokarton',
                'unit' => 'dona', 'sku' => 'TEST-'.$page, 'price' => '42100', 'discountPrice' => $page === 1 ? '40000' : null,
                'inStock' => $page === 1, 'imageUrl' => '/api/v1/files/9', 'variantAttributes' => [['value' => $page === 1 ? 'Oddiy' : 'Namga chidamli']],
                'categories' => [['name' => 'Devor materiallari']]],
        ]]);
    });
    $this->artisan('market:sync-construction', ['--source' => 'tezqur'])->assertSuccessful();
    $rows = app(ConstructionCatalog::class)->snapshot('tezqur')['products'];
    expect($rows)->toHaveCount(2);
    expect($rows[0]['price'])->toBe('40000.00');
    expect($rows[1]['price'])->toBe('42100.00');
    expect($rows[0]['unit_label'])->toBe('dona');
    expect($rows[1]['name'])->toContain('Namga chidamli');
    expect($rows[1]['availability'])->toBe('out_of_stock');
    expect($rows[1]['source_url'])->toBe('https://tezqur.uz/uz/product/gloria-gipsokarton?variant=2');
    Http::assertSentCount(2);
});

it('extracts KSLux retail price rather than the crossed out price and keeps missing units explicit', function () {
    $html = '<div data-entity="item"><a class="name font-title" href="/catalog/test-germetik/">Bostik test</a><div data-entity="price-block"><div class="price font-body">20 655 UZS</div><div class="old-price">99 000 UZS</div></div><div class="in-stock">В наличии</div></div>';
    Http::fake(['https://kslux.uz/catalog/*' => Http::response($html)]);
    $this->artisan('market:sync-construction', ['--source' => 'kslux'])->assertSuccessful();
    $rows = app(ConstructionCatalog::class)->snapshot('kslux')['products'];
    expect($rows)->toHaveCount(1);
    expect($rows[0]['price'])->toBe('20655.00');
    expect($rows[0]['unit_label'])->toBe('Birlik manbada aniqlanmagan');
    expect($rows[0]['availability'])->toBe('in_stock');
});

it('extracts Alstroy price and square metre unit without converting package prices', function () {
    $html = '<div class="catalog-section-item intec-grid-item-4"><div class="catalog-section-item-name"><a href="/catalog/laminat/test/">Laminat 8 mm 2,131 m²</a></div><span data-role="item.price.discount">85&nbsp;000 UZS</span><span data-role="item.price.measure">за 1 м²</span><div data-role="item.quantity">В наличии</div></div>';
    Http::fake(['https://alstroy.uz/catalog/*' => Http::response($html)]);
    $this->artisan('market:sync-construction', ['--source' => 'alstroy'])->assertSuccessful();
    $rows = app(ConstructionCatalog::class)->snapshot('alstroy')['products'];
    expect($rows[0]['price'])->toBe('85000.00');
    expect($rows[0]['unit_label'])->toBe('за 1 м²');
    expect($rows[0]['valid_until'])->toBeNull();
});

it('retains each last successful snapshot and timestamp when the source changes or fails', function () {
    Cache::forever(ConstructionCatalog::key('tezqur'), ['checked_at' => '2026-10-31T10:00:00Z', 'products' => [['id' => 'old']]]);
    Http::fake(['https://api.tezqur.uz/*' => Http::response(['message' => 'Unavailable'], 503)]);
    $this->artisan('market:sync-construction', ['--source' => 'tezqur'])->assertFailed();
    $snapshot = app(ConstructionCatalog::class)->snapshot('tezqur');
    expect($snapshot['checked_at'])->toBe('2026-10-31T10:00:00Z');
    expect($snapshot['products'])->toBe([['id' => 'old']]);
    expect($snapshot['sync_failed'])->toBeTrue();
});

it('filters sources and construction direction without doing external requests while users browse', function () {
    [$user] = marketIdentity();
    Cache::forever(ConstructionCatalog::key('tezqur'), ['checked_at' => now()->toISOString(), 'products' => [
        ['id' => 'tezqur:1', 'name' => 'Sement 50 kg', 'price' => '60000.00', 'search_text' => 'sement 50 kg', 'source_name' => 'TezQur', 'source_id' => 'tezqur'],
    ]]);
    Cache::forever(ConstructionCatalog::key('kslux'), ['checked_at' => now()->toISOString(), 'products' => [
        ['id' => 'kslux:1', 'name' => 'Other product', 'search_text' => 'other product'],
    ]]);
    $this->actingAs($user)->get('/market?department=construction&source=tezqur&q='.urlencode('цемент'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.department', 'construction')->where('market.total', 1)->where('market.products.0.source_name', 'TezQur')
        ->missing('market.products.0.search_text')->has('market.sources', 4));
    Http::assertNothingSent();
    expect(app(PublicMarketCatalog::class)->search('', [], 'grocery')['total'])->toBe(0);
    $this->get('/market?source=untrusted')->assertSessionHasErrors('source');
});
