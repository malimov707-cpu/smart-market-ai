<?php

use App\Services\PublicMarketCatalog;
use App\Services\StatUzAveragePrices;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

function statUzDatasetFixture(): array
{
    return [[
        'metadata' => [
            ['name_uz' => "So'nggi o'zgartirilgan sana", 'value_uz' => '2026-10-15'],
        ],
        'data' => [
            [
                'Code' => '101',
                'Klassifikator' => 'Olma (1 kg)',
                'Klassifikator_ru' => 'Яблоки (1 кг)',
                'Klassifikator_en' => 'Apples (1 kg)',
                'Klassifikator_uzc' => 'Олма (1 кг)',
                '2026-M08' => 12000,
                '2026-M09' => 13000,
            ],
            [
                'Code' => '102',
                'Klassifikator' => 'Nok (1 kg)',
                'Klassifikator_ru' => 'Груши (1 кг)',
                'Klassifikator_en' => 'Pears (1 kg)',
                '2026-M09' => 18000,
            ],
        ],
    ]];
}

it('returns the latest official monthly average for matching product labels', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json' => Http::response(statUzDatasetFixture())]);

    $result = app(StatUzAveragePrices::class)->search('apple');

    expect($result['total'])->toBe(1);
    expect($result['products'][0]['name'])->toBe('Olma');
    expect($result['products'][0]['price'])->toBe('13000.00');
    expect($result['products'][0]['unit_label'])->toBe('1 kg');
    expect($result['products'][0]['data_period'])->toBe('2026-M09');
    expect($result['products'][0])->not->toHaveKey('search_labels');
    expect($result['data_period'])->toBe('2026-M09');
    expect($result['dataset_total'])->toBe(2);
    expect($result['stale'])->toBeFalse();
    Http::assertSentCount(1);
});

it('explains that a search has no match while retaining the number of products in the dataset', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json' => Http::response(statUzDatasetFixture())]);

    $result = app(StatUzAveragePrices::class)->search('quloqchin quti');

    expect($result['total'])->toBe(0);
    expect($result['dataset_total'])->toBe(2);
    expect($result['data_period'])->toBe('2026-M09');
    expect($result['products'])->toBe([]);
    Http::assertSentCount(1);
});

it('serves the last successful monthly snapshot when the statistics endpoint fails', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json' => Http::sequence()
        ->push(statUzDatasetFixture())
        ->push([], 503)]);
    $service = app(StatUzAveragePrices::class);
    $service->search('olma');
    Cache::forget(StatUzAveragePrices::CACHE_KEY);

    $result = $service->search('olma');

    expect($result['products'])->toHaveCount(1);
    expect($result['products'][0]['price'])->toBe('13000.00');
    expect($result['stale'])->toBeTrue();
    expect($result['sync_failed'])->toBeTrue();
    expect($result['partial'])->toBeTrue();
    Http::assertSentCount(2);
});

it('reports an unavailable statistics source without returning fabricated prices', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json' => Http::response([], 503)]);

    $result = app(StatUzAveragePrices::class)->search('olma');

    expect($result['products'])->toBe([]);
    expect($result['sync_failed'])->toBeTrue();
    expect($result['partial'])->toBeTrue();
    expect($result['checked_at'])->toBeNull();
});

it('allows filtering market results to official monthly averages', function () {
    [$user] = marketIdentity();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Http::fake(['https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json' => Http::response(statUzDatasetFixture())]);

    $this->actingAs($user)->get('/market?department=grocery&source=statuz&q=olma')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.source', 'statuz')
        ->where('market.total', 1)
        ->where('market.products.0.source_id', 'statuz')
        ->where('market.products.0.price', '13000.00')
        ->where('market.products.0.data_period', '2026-M09')
        ->where('market.sources.1.id', 'statuz')
        ->where('market.sources.1.dataset_count', 2));
    Http::assertSentCount(1);
});

it('shows the dataset coverage when a product search has no official average', function () {
    [$user] = marketIdentity();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Http::fake(['https://api.siat.stat.uz/media/uploads/sdmx/sdmx_data_3780.json' => Http::response(statUzDatasetFixture())]);

    $this->actingAs($user)->get('/market?department=grocery&source=statuz&q=quloqchin%20quti')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('market.total', 0)
        ->where('market.sources.1.count', 0)
        ->where('market.sources.1.dataset_count', 2)
        ->where('market.sources.1.data_period', '2026-M09'));
    Http::assertSentCount(1);
});

it('does not compare an official average as a live seller price', function () {
    $catalog = app(PublicMarketCatalog::class);
    $products = [
        ['name' => 'Olma (1 kg)', 'price' => '10000.00', 'currency' => 'UZS', 'source_id' => 'statuz', 'source_name' => 'Milliy statistika', 'unit_label' => '1 kg'],
        ['name' => 'Olma (1 kg)', 'price' => '13000.00', 'currency' => 'UZS', 'source_id' => 'korzinka', 'source_name' => 'Korzinka', 'unit_label' => '1 kg'],
    ];

    expect($catalog->insight('olma', $products))->toBeNull();
});
