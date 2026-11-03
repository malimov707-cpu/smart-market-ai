<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ApprovedCatalogImporter;
use App\Services\ConstructionCatalog;
use App\Services\KorzinkaCatalog;
use App\Services\PublicMarketCatalog;
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

it('marks the aggregate market stale when an applicable source has never been checked', function () {
    Cache::forever(KorzinkaCatalog::CACHE_KEY, ['checked_at' => now()->toISOString(), 'products' => []]);
    config(['services.asaxiy.catalog_enabled' => false]);

    $market = app(PublicMarketCatalog::class)->search();

    expect(collect($market['sources'])->firstWhere('id', 'korzinka')['stale'])->toBeFalse()
        ->and(collect($market['sources'])->firstWhere('id', 'tezqur')['checked_at'])->toBeNull()
        ->and($market['stale'])->toBeTrue();
    Http::assertNothingSent();
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
        ->where('market.total', 1)->where('market.products.0.price', '12990.00')
        ->where('market.products.0.freshness', 'fresh')->missing('market.products.0.search_text'));
    Http::assertNothingSent();
});

it('returns broad electronic catalog matches for generic electronic queries', function () {
    $path = tempnam(sys_get_temp_dir(), 'approved-catalog');
    file_put_contents($path, json_encode([
        ['sku' => 'PHONE-1', 'name' => 'Apple iPhone 15 128 GB', 'brand' => 'Apple', 'category' => 'Smartphone', 'unit' => 'pcs', 'price' => 8900000, 'aliases' => ['iphone 15', 'apple phone']],
        ['sku' => 'LAPTOP-1', 'name' => 'ASUS VivoBook 15', 'brand' => 'ASUS', 'category' => 'Laptop', 'unit' => 'pcs', 'price' => 8250000, 'aliases' => ['asus laptop']],
        ['sku' => 'TV-1', 'name' => 'Samsung Smart TV 55 inch', 'brand' => 'Samsung', 'category' => 'Television', 'unit' => 'pcs', 'price' => 7850000, 'aliases' => ['smart tv']],
    ]));

    app(ApprovedCatalogImporter::class)->import($path);

    $result = app(PublicMarketCatalog::class)->search('elektron mahsulotlar', ['elektronika'], 'all', 'all', true);

    expect($result['total'])->toBeGreaterThanOrEqual(3)
        ->and($result['products'])->toHaveCount(3)
        ->and($result['products'][0]['category'])->toBeTruthy()
        ->and($result['products'][0])->toHaveKeys(['brand', 'model', 'offer_count']);

    unlink($path);
});

it('matches local catalog aliases barcodes and package variants without exposing internal identifiers', function () {
    [, $organization] = marketIdentity();
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Generic smartphone',
        'sku' => 'SMARTPHONE-TEST',
        'unit' => 'pcs',
        'category' => 'Smartphone',
        'barcode' => '1234567890123',
        'aliases' => ['Alternate phone model'],
        'variant_attributes' => [['name' => 'storage', 'value' => '128 GB']],
        'package_quantity' => '128.000',
        'package_unit' => 'gb',
    ]);
    $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
    Offer::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
        'unit' => 'pcs',
        'package_quantity' => '1.000',
        'observed_at' => CarbonImmutable::parse('2026-10-24 10:00:00', 'UTC'),
        'expires_at' => CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'),
    ]);

    Cache::forever(KorzinkaCatalog::CACHE_KEY, ['checked_at' => now()->toISOString(), 'products' => []]);
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false,
        'partial' => false, 'data_period' => '2026-M09', 'products' => [],
    ]);
    config(['services.asaxiy.catalog_enabled' => false]);
    $market = app(PublicMarketCatalog::class)->search('1234567890123', ['Alternate phone model', '128 GB'], 'general', 'all', true);
    $matchedProduct = $market['products'][0];

    expect($market['total'])->toBe(1)
        ->and($matchedProduct)->toHaveKeys(['alias_match', 'barcode_match', 'variant_match', 'package_label'])
        ->and($matchedProduct['alias_match'])->toBeTrue()
        ->and($matchedProduct['barcode_match'])->toBeTrue()
        ->and($matchedProduct['variant_match'])->toBeTrue()
        ->and($matchedProduct['package_label'])->toBe('128 gb')
        ->and($matchedProduct['checked_at'])->toBe('2026-10-24T10:00:00.000000Z')
        ->and($matchedProduct['freshness'])->toBe('stale')
        ->and(collect($market['sources'])->firstWhere('id', 'catalog')['checked_at'])->toBe('2026-10-24T10:00:00.000000Z')
        ->and(array_key_exists('search_text', $matchedProduct))->toBeFalse()
        ->and(array_key_exists('barcode', $matchedProduct))->toBeFalse()
        ->and(array_key_exists('aliases', $matchedProduct))->toBeFalse();
    Http::assertNothingSent();
});

it('does not present imported catalog timestamps as fresh price observations', function (string $sourceReference) {
    [, $organization] = marketIdentity();
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Imported catalog headphones',
        'sku' => 'IMPORTED-HEADPHONES',
        'unit' => 'pcs',
        'category' => 'Electronics',
    ]);
    $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
    Offer::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
        'observed_at' => now(),
        'source_reference' => $sourceReference,
        'provenance' => 'csv',
    ]);

    Cache::forever(KorzinkaCatalog::CACHE_KEY, ['checked_at' => now()->toISOString(), 'products' => []]);
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false,
        'partial' => false, 'data_period' => '2026-M09', 'products' => [],
    ]);
    config(['services.asaxiy.catalog_enabled' => false]);
    $market = app(PublicMarketCatalog::class)->search('Imported catalog headphones', [], 'general', 'all', true);

    expect($market['products'][0]['checked_at'])->toBeNull()
        ->and($market['products'][0]['freshness'])->toBe('unknown')
        ->and(collect($market['sources'])->firstWhere('id', 'catalog')['stale'])->toBeTrue();
    Http::assertNothingSent();
})->with(['approved:TEST', 'fayztools:TEST']);

it('marks local offers as expired when their stated validity has ended', function () {
    [, $organization] = marketIdentity();
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Expired wireless headphones',
        'sku' => 'EXPIRED-HEADPHONES',
        'unit' => 'pcs',
        'category' => 'Electronics',
    ]);
    $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
    Offer::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
        'observed_at' => now()->subDay(),
        'expires_at' => now(),
    ]);

    Cache::forever(KorzinkaCatalog::CACHE_KEY, ['checked_at' => now()->toISOString(), 'products' => []]);
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false,
        'partial' => false, 'data_period' => '2026-M09', 'products' => [],
    ]);
    config(['services.asaxiy.catalog_enabled' => false]);
    $market = app(PublicMarketCatalog::class)->search('Expired wireless headphones', [], 'general', 'all', true);

    expect($market['products'][0]['name'])->toBe('Expired wireless headphones')
        ->and($market['products'][0]['freshness'])->toBe('expired')
        ->and(collect($market['sources'])->firstWhere('id', 'catalog')['stale'])->toBeTrue();
    Http::assertNothingSent();
});

it('recognizes an image without accepting generated prices as real market evidence', function () {
    [$user] = marketIdentity();
    config(['services.openrouter.key' => 'test-only', 'services.openrouter.model' => 'test/vision']);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'name' => 'Yog‘och stul', 'brand' => null, 'package' => null, 'uncertain' => true, 'search_terms' => ['stul', 'стул', 'chair'], 'price' => 1,
    ])]]]])]);
    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->image('product.jpg', 500, 1200)])
        ->assertOk()->assertJsonPath('recognition.name', 'Yog‘och stul')->assertJsonPath('recognition.search_terms.2', 'chair')
        ->assertJsonPath('recognition.uncertain', true)->assertJsonMissingPath('recognition.price');
    Http::assertSent(function (ClientRequest $request): bool {
        $content = $request['messages'][1]['content'];
        $instructions = $request['messages'][0]['content'];
        $encodedImage = substr($content[1]['image_url']['url'], strlen('data:image/jpeg;base64,'));
        $dimensions = getimagesizefromstring(base64_decode($encodedImage, true));

        return $content[1]['type'] === 'image_url'
            && str_contains($instructions, 'tabiiy, grammatik jihatdan to‘g‘ri o‘zbek tilida va faqat lotin yozuvida')
            && str_contains($instructions, 'mebel (stol, stul)')
            && str_contains($instructions, 'telefon g‘ilofi')
            && str_contains($instructions, 'phone case')
            && str_contains($instructions, 'maishiy texnika')
            && str_contains($instructions, 'mahsulotni olma yoki oziq-ovqat deb taxmin qilmang')
            && str_contains($instructions, 'g‘alati yoki uydirma so‘z ishlatmang')
            && str_contains($content[0]['text'], 'tushunarli, tabiiy o‘zbek lotin yozuvida')
            && str_contains($content[0]['text'], 'yorliq bo‘lishi shart emas')
            && $dimensions[0] === 427 && $dimensions[1] === 1024
            && $request['max_tokens'] === 220 && $request['temperature'] === 0.1
            && $request['provider']['sort'] === 'latency'
            && ! str_contains(json_encode($request['messages']), 'Current authorized platform snapshot');
    });
});

it('searches matching seller offers using all recognized product terms', function () {
    [$user] = marketIdentity();
    Cache::forever(KorzinkaCatalog::CACHE_KEY, [
        'checked_at' => now()->toISOString(),
        'products' => [
            [
                'id' => 999,
                'name' => 'Wooden chair',
                'search_text' => 'wooden chair stul стул',
                'price' => '50000.00',
                'currency' => 'UZS',
                'unit_label' => '1 dona',
                'category' => 'Uy jihozlari',
                'image' => null,
                'valid_until' => now()->addDay()->toISOString(),
                'card_required' => false,
                'conditions' => '',
                'source_url' => 'https://catalog.korzinka.uz/product/wooden-chair',
            ],
            [
                'id' => 1000,
                'name' => 'Apple juice',
                'search_text' => 'apple juice olma sharbati',
                'price' => '12000.00',
                'currency' => 'UZS',
                'unit_label' => '1 litr',
                'category' => 'Ichimliklar',
                'image' => null,
                'valid_until' => now()->addDay()->toISOString(),
                'card_required' => false,
                'conditions' => '',
                'source_url' => 'https://catalog.korzinka.uz/product/apple-juice',
            ],
        ],
    ]);
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(),
        'stale' => false,
        'sync_failed' => false,
        'partial' => false,
        'data_period' => '2026-M09',
        'products' => [],
    ]);

    $this->actingAs($user)->get('/market?q=unrelated&terms%5B%5D=wrong%20one&terms%5B%5D=wrong%20two&terms%5B%5D=wrong%20three&terms%5B%5D=wrong%20four&terms%5B%5D=chair')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('PublicMarket')
        ->where('filters.q', 'unrelated')
        ->where('filters.terms.4', 'chair')
        ->where('market.total', 1)
        ->where('market.products.0.name', 'Wooden chair'));
});

it('accepts basket-style image recognition and merges multiple product terms for catalog search', function () {
    [$user] = marketIdentity();
    config(['services.openrouter.key' => 'test-only', 'services.openrouter.model' => 'test/vision']);
    Cache::forever(KorzinkaCatalog::CACHE_KEY, [
        'checked_at' => now()->toISOString(),
        'products' => [
            ['id' => 201, 'name' => 'Pepsi cola 1 l', 'search_text' => 'pepsi cola pepsi gazli ichimlik', 'price' => '12000.00', 'currency' => 'UZS', 'unit_label' => '1 litr', 'category' => 'Ichimliklar', 'image' => null, 'valid_until' => now()->addDay()->toISOString(), 'card_required' => false, 'conditions' => '', 'source_url' => 'https://catalog.korzinka.uz/product/pepsi'],
            ['id' => 202, 'name' => 'Coca-Cola 1.5 l', 'search_text' => 'coca cola coca-cola gazli ichimlik', 'price' => '15000.00', 'currency' => 'UZS', 'unit_label' => '1.5 litr', 'category' => 'Ichimliklar', 'image' => null, 'valid_until' => now()->addDay()->toISOString(), 'card_required' => false, 'conditions' => '', 'source_url' => 'https://catalog.korzinka.uz/product/coca-cola'],
            ['id' => 203, 'name' => 'Fanta 0.5 l', 'search_text' => 'fanta sharbat ichimlik', 'price' => '8000.00', 'currency' => 'UZS', 'unit_label' => '0.5 litr', 'category' => 'Ichimliklar', 'image' => null, 'valid_until' => now()->addDay()->toISOString(), 'card_required' => false, 'conditions' => '', 'source_url' => 'https://catalog.korzinka.uz/product/fanta'],
        ],
    ]);
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false, 'partial' => false,
        'data_period' => '2026-M09', 'products' => [],
    ]);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'name' => 'Pepsi', 'brand' => null, 'package' => '1 litr', 'uncertain' => false, 'search_terms' => ['pepsi', 'pepsi cola', 'gazli ichimlik'],
        'items' => [
            ['name' => 'Pepsi', 'brand' => null, 'package' => '1 litr', 'uncertain' => false, 'search_terms' => ['pepsi', 'pepsi cola']],
            ['name' => 'Coca-Cola', 'brand' => null, 'package' => '1.5 litr', 'uncertain' => false, 'search_terms' => ['coca-cola', 'coca cola', 'gazli ichimlik']],
            ['name' => 'Fanta', 'brand' => null, 'package' => '0.5 litr', 'uncertain' => false, 'search_terms' => ['fanta', 'sharbat', 'ichimlik']],
        ],
    ])]]]])]);

    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->image('basket.jpg', 500, 1200)])
        ->assertOk()->assertJsonPath('recognition.name', 'Pepsi')->assertJsonPath('recognition.items.1.name', 'Coca-Cola')
        ->assertJsonCount(8, 'recognition.search_terms')->assertJsonPath('market.total', 3)
        ->assertJsonPath('market.products.0.name', 'Pepsi cola 1 l');
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
