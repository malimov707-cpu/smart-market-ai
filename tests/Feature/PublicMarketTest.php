<?php

use App\Services\ConstructionCatalog;
use App\Services\KorzinkaCatalog;
use App\Services\StatUzAveragePrices;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));
});

function publicCatalogFixture(): array
{
    return [
        'https://catalog.korzinka.uz/api/mobile/catalogs-list' => Http::response(['code' => 200, 'data' => [
            ['id' => 1, 'status' => true, 'is_special' => 0, 'start_date' => '2026-10-01', 'end_date' => '2026-11-05',
                'categories' => [['get_products' => 42, 'title_uz' => 'Mevalar']]],
            ['id' => 2, 'status' => true, 'is_special' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-11-05',
                'categories' => [['get_products' => 99, 'title_uz' => 'Other region']]],
        ]]),
        'https://catalog.korzinka.uz/api/mobile/catalogs/category/products' => Http::response(['code' => 200, 'data' => [
            ['id' => 100, 'title_uz' => 'Olma test kg', 'title_ru' => 'Яблоки', 'prices' => ['actual_price' => '12 990'],
                'weight_param' => '1 kg', 'small_image_url' => 'https://catalog.korzinka.uz/storage/test.png',
                'promotion_tags' => [['value' => '27.10.2026-05.11.2026']]],
            ['id' => 101, 'title_uz' => 'Expired', 'prices' => ['actual_price' => '9 990'], 'promotion_tags' => [['value' => '01.10.2026-30.10.2026']]],
            ['id' => 102, 'title_uz' => 'Invalid', 'prices' => ['actual_price' => 'CALL']],
        ]]),
    ];
}

it('synchronizes actual catalogue prices and rejects expired invalid and unscoped special offers', function () {
    Http::fake(publicCatalogFixture());
    $this->artisan('market:sync')->assertSuccessful();
    $snapshot = app(KorzinkaCatalog::class)->search('olma');
    expect($snapshot['total'])->toBe(1);
    expect($snapshot['products'][0]['price'])->toBe('12990.00');
    expect($snapshot['products'][0]['name'])->toBe('Olma test kg');
    expect($snapshot['stale'])->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://catalog.korzinka.uz/api/mobile/catalogs/category/products' && $request['get_products'] === 42);
});

it('retains the last successful observation time when a source fails', function () {
    $fail = false;
    $responses = publicCatalogFixture();
    Http::fake(function (ClientRequest $request) use (&$fail, $responses) {
        return $fail ? Http::response([], 503) : $responses[$request->url()];
    });
    app(KorzinkaCatalog::class)->sync();
    $checked = Cache::get(KorzinkaCatalog::CACHE_KEY)['checked_at'];
    $this->travel(11)->minutes();
    $fail = true;
    $this->artisan('market:sync')->assertFailed();
    $snapshot = app(KorzinkaCatalog::class)->search();
    expect($snapshot['checked_at'])->toBe($checked);
    expect($snapshot['stale'])->toBeTrue();
    expect($snapshot['sync_failed'])->toBeTrue();
    expect($snapshot['total'])->toBe(1);
});

it('shows source timestamps and filters cached prices without hitting the source per user request', function () {
    [$user] = marketIdentity();
    Http::fake(publicCatalogFixture());
    app(KorzinkaCatalog::class)->sync();
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false,
        'partial' => false, 'data_period' => '2026-M09', 'products' => [],
    ]);
    Http::fake();
    $this->actingAs($user)->get('/market?q=olma')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PublicMarket')
        ->where('market.total', 1)->where('market.products.0.price', '12990.00')->missing('market.products.0.search_text'));
    Http::assertNothingSent();
});

it('recognizes an image without accepting generated prices as real market evidence', function () {
    [$user] = marketIdentity();
    config(['services.openrouter.key' => 'test-only', 'services.openrouter.model' => 'test/vision']);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'name' => 'Olma', 'brand' => null, 'package' => null, 'uncertain' => true, 'search_terms' => ['olma', 'яблоки'], 'price' => 1,
    ])]]]])]);
    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->image('product.jpg', 500, 1200)])
        ->assertOk()->assertJsonPath('recognition.name', 'Olma')->assertJsonPath('recognition.uncertain', true)->assertJsonMissingPath('recognition.price');
    Http::assertSent(function (ClientRequest $request): bool {
        $content = $request['messages'][1]['content'];
        $instructions = $request['messages'][0]['content'];
        $encodedImage = substr($content[1]['image_url']['url'], strlen('data:image/jpeg;base64,'));
        $dimensions = getimagesizefromstring(base64_decode($encodedImage, true));

        return $content[1]['type'] === 'image_url'
            && str_contains($instructions, 'tabiiy, grammatik jihatdan to‘g‘ri o‘zbek tilida va faqat lotin yozuvida')
            && str_contains($instructions, 'g‘alati yoki uydirma so‘z ishlatmang')
            && str_contains($content[0]['text'], 'tushunarli, tabiiy o‘zbek lotin yozuvida')
            && $dimensions[0] === 427 && $dimensions[1] === 1024
            && $request['max_tokens'] === 220 && $request['temperature'] === 0.1
            && $request['provider']['sort'] === 'latency'
            && ! str_contains(json_encode($request['messages']), 'Current authorized platform snapshot');
    });
});

it('does not present a cheapest-price insight when the Asaxiy search was partial', function () {
    [$user] = marketIdentity();
    config(['services.openrouter.key' => 'test-only', 'services.openrouter.model' => 'test/vision',
        'services.asaxiy.catalog_enabled' => true]);
    $productUrls = array_map(fn (int $number): string => "https://asaxiy.uz/product/airpods-case-{$number}", range(1, 7));
    $productLocations = implode('', array_map(fn (string $url): string => '<url><loc>'.$url.'</loc></url>', $productUrls));
    Cache::forever(ConstructionCatalog::key('tezqur'), ['checked_at' => now()->toISOString(), 'products' => [[
        'id' => 'tezqur:airpods-case', 'name' => 'Apple AirPods Case', 'price' => '300000.00', 'currency' => 'UZS',
        'source_id' => 'tezqur', 'source_name' => 'TezQur', 'source_url' => 'https://tezqur.uz/product/airpods-case',
        'image' => null, 'unit_label' => '1 dona · katalogdagi mahsulot', 'category' => 'Elektronika', 'valid_until' => null,
        'card_required' => false, 'conditions' => '', 'availability' => 'in_stock', 'search_text' => 'airpods case',
    ]]]);
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'name' => 'AirPods case', 'brand' => 'Apple', 'package' => null, 'uncertain' => false, 'search_terms' => ['AirPods case'],
        ])]]]]),
        'https://asaxiy.uz/sitemap.xml' => Http::response('<sitemapindex><sitemap><loc>https://asaxiy.uz/sitemap-goods1.xml</loc></sitemap></sitemapindex>'),
        'https://asaxiy.uz/sitemap-goods1.xml' => Http::response('<urlset>'.$productLocations.'</urlset>'),
        'https://asaxiy.uz/product/*' => Http::response('<script type="application/ld+json">'.json_encode([
            '@type' => 'Product', 'name' => 'Apple AirPods Case',
            'offers' => ['@type' => 'Offer', 'price' => '399000', 'priceCurrency' => 'UZS', 'availability' => 'https://schema.org/InStock'],
        ], JSON_THROW_ON_ERROR).'</script>'),
    ]);

    $this->actingAs($user)->postJson('/market/scan', [
        'image' => UploadedFile::fake()->image('product.jpg'),
    ])->assertOk()
        ->assertJsonPath('market.partial', true)
        ->assertJsonPath('insight', null)
        ->assertJsonCount(7, 'market.products');
});

it('does not show a Russian-script product name as Uzbek recognition', function () {
    [$user] = marketIdentity();
    config(['services.openrouter.key' => 'test-only', 'services.openrouter.model' => 'test/vision']);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'name' => 'Беспроводные наушники', 'brand' => null, 'package' => null, 'uncertain' => true, 'search_terms' => ['quloqchin', 'наушники'],
    ])]]]])]);

    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->image('product.jpg')])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'AI mahsulot nomini o‘zbek lotin yozuvida aniq qaytara olmadi. Boshqa surat bilan urinib ko‘ring.');
});

it('refuses invalid uploads before calling a vision provider', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->create('bad.svg', 5, 'image/svg+xml')])
        ->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->postJson('/market/scan', ['image' => UploadedFile::fake()->image('large.jpg', 4200, 100)])
        ->assertUnprocessable()->assertJsonValidationErrors('image');
    Http::assertNothingSent();
});

it('requires authentication for browsing and scanning and reports unavailable vision safely', function () {
    $this->get('/market')->assertRedirect('/login');
    $this->postJson('/market/scan')->assertUnauthorized();
    [$user] = marketIdentity();
    config(['services.openrouter.key' => '']);
    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->image('product.jpg')])->assertStatus(503);
    Http::assertNothingSent();
});
