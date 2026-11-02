<?php

use App\Services\PublicMarketCatalog;

it('computes savings insight when offers differ enough across sources', function () {
    $catalog = app(PublicMarketCatalog::class);

    $products = [
        ['name' => 'Olma 1kg', 'price' => '12000', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null],
        ['name' => 'Olma 1kg', 'price' => '15900', 'currency' => 'UZS', 'source_name' => 'TezQur', 'source_url' => 'https://y', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null],
    ];

    $insight = $catalog->insight('olma', $products);

    expect($insight)->toBeArray()
        ->and($insight['best']['price'])->toBe('12000')
        ->and($insight['worst']['price'])->toBe('15900')
        ->and($insight['savings'])->toBe('3900.00')
        ->and($insight['savings_percent'])->toBe(32.5)
        ->and($insight['offers'])->toBe(2)
        ->and($insight['sources'])->toBe(['Korzinka', 'TezQur']);
});

it('returns no insight for a single offer or near identical prices', function () {
    $catalog = app(PublicMarketCatalog::class);

    $row = fn (string $price, string $source = 'Korzinka'): array => ['name' => 'Olma', 'price' => $price, 'currency' => 'UZS', 'source_name' => $source, 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null];

    expect($catalog->insight('olma', [$row('12000')]))->toBeNull();
    expect($catalog->insight('olma', [$row('12000'), $row('12300', 'TezQur')]))->toBeNull();
    expect($catalog->insight('olma', [$row('12000'), $row('CALL', 'TezQur')]))->toBeNull();
});

it('does not compare different product names or units', function () {
    $catalog = app(PublicMarketCatalog::class);
    $row = fn (string $name, string $unit, string $source): array => ['name' => $name, 'price' => '12000', 'currency' => 'UZS',
        'source_name' => $source, 'source_url' => 'https://example.test', 'image' => null, 'unit_label' => $unit, 'checked_at' => null];

    expect($catalog->insight('olma', [
        $row('Olma 1kg', '1 kg', 'Korzinka'),
        $row('Olma premium 1kg', '1 kg', 'TezQur'),
    ]))->toBeNull();
    expect($catalog->insight('olma', [
        $row('Olma', '1 kg', 'Korzinka'),
        $row('Olma', '5 kg', 'TezQur'),
    ]))->toBeNull();
});
