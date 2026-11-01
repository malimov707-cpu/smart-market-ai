<?php

use App\Services\KorzinkaCatalog;
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
    $this->actingAs($user)->postJson('/market/scan', ['image' => UploadedFile::fake()->image('product.jpg', 100, 100)])
        ->assertOk()->assertJsonPath('recognition.name', 'Olma')->assertJsonPath('recognition.uncertain', true)->assertJsonMissingPath('recognition.price');
    Http::assertSent(fn (ClientRequest $request): bool => $request['messages'][2]['content'][1]['type'] === 'image_url'
        && str_starts_with($request['messages'][2]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,'));
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
