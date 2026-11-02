<?php

use App\Services\KorzinkaCatalog;
use App\Services\PriceTrendHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));
    Http::fake(productCatalogFixture());
    app(KorzinkaCatalog::class)->sync();
    Http::fake();
});

function productCatalogFixture(): array
{
    return [
        'https://catalog.korzinka.uz/api/mobile/catalogs-list' => Http::response(['code' => 200, 'data' => [
            ['id' => 1, 'status' => true, 'is_special' => 0, 'start_date' => '2026-10-01', 'end_date' => '2026-11-05',
                'categories' => [['get_products' => 42, 'title_uz' => 'Mevalar']]],
        ]]),
        'https://catalog.korzinka.uz/api/mobile/catalogs/category/products' => Http::response(['code' => 200, 'data' => [
            ['id' => 100, 'title_uz' => 'Olma Golden 1 kg', 'title_ru' => 'Яблоки', 'prices' => ['actual_price' => '12 990'],
                'weight_param' => '1 kg', 'small_image_url' => 'https://catalog.korzinka.uz/storage/test.png'],
            ['id' => 101, 'title_uz' => 'Olma Red 1 kg', 'title_ru' => 'Яблоки красные', 'prices' => ['actual_price' => '15 500'],
                'weight_param' => '1 kg'],
        ]]),
    ];
}

it('renders the product detail page with sources trend and savings', function () {
    [$user] = marketIdentity();
    $trends = app(PriceTrendHistory::class);
    foreach ([['2026-10-30', '12000'], ['2026-10-31', '12990']] as [$day, $price]) {
        $trends->record('olma', [['price' => $price, 'name' => 'Olma', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null]], CarbonImmutable::parse($day, 'UTC'));
    }

    $this->actingAs($user)->get('/market/product/olma')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ProductDetail')
        ->where('query', 'olma')
        ->has('trend.series', 2)
        ->has('insight.best')
        ->where('insight.sources', ['Korzinka'])
        ->has('related.0'));
});

it('redirects unknown product slugs to the market search and rejects malformed slugs', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->get('/market/product/nomalum-mahsulot')->assertRedirect(route('market', ['q' => 'nomalum mahsulot']));
    $this->actingAs($user)->get('/market/product/'.str_repeat('x', 80))->assertNotFound();
    $this->actingAs($user)->get('/market/product/olma<script>')->assertNotFound();
});

it('lists popular tracked products', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->get('/market/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ProductIndex')->has('products', 16)->where('products.0.slug', 'olma'));
});

it('exposes sitemap and robots for public search engines', function () {
    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')
        ->assertSee('/market/product/olma');
    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:');
});
