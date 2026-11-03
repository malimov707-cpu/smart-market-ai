<?php

use App\Services\ApprovedCatalogImporter;
use App\Services\PublicMarketCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('imports an approved internal catalog and matches custom products in the local market search', function () {
    $result = app(ApprovedCatalogImporter::class)->import(base_path('database/data/approved-market-catalog.json'));

    expect($result['imported'])->toBeGreaterThan(20);

    $market = app(PublicMarketCatalog::class)->search('iphone 15', ['apple phone'], 'all', 'all', true);

    expect($market['total'])->toBeGreaterThan(0);
    expect($market['products'])->not->toBeEmpty();
    expect($market['products'][0]['name'])->toContain('iPhone');
});
