<?php

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('imports the FayzTools catalog export into the organization schema', function () {
    $path = tempnam(sys_get_temp_dir(), 'fayztools');
    file_put_contents($path, json_encode([
        'productList' => [
            ['productId' => 101, 'title' => 'Pepsi 1 l', 'category' => 'Sodalar', 'price' => 9500, 'skuId' => 1001],
            ['productId' => 102, 'title' => 'Coca-Cola 0.5 l', 'category' => 'Sodalar', 'price' => 8200, 'skuId' => 1002],
            ['productId' => 103, 'title' => 'Samsung USB cable', 'category' => 'Accessories', 'price' => 25000, 'skuId' => 1003],
        ],
    ]));

    $this->artisan('fayztools:import', ['--source' => $path])->assertSuccessful();

    $organization = Organization::query()->where('name', 'FayzTools')->firstOrFail();

    expect($organization->id)->toBeInt()
        ->and(Product::query()->where('organization_id', $organization->id)->count())->toBe(3)
        ->and(Offer::query()->where('organization_id', $organization->id)->count())->toBe(3)
        ->and(Product::query()->where('organization_id', $organization->id)->where('sku', 1001)->first()->unit)->toBe('l')
        ->and(Product::query()->where('organization_id', $organization->id)->where('sku', 1003)->first()->unit)->toBe('pcs');

    unlink($path);
});
