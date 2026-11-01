<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('shows an honest empty decision desk without invented opportunities', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->get('/ai')->assertInertia(fn (Assert $page) => $page->component('Workspace')
        ->where('insights.product_count', 0)->has('insights.decisions', 0)->has('insights.timeline', 14));
});

it('compares actual complete eligible prices for the requested purchase quantity', function () {
    $this->freezeTime();
    [$user, $organization] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $organization->id, 'unit' => 'kg']);
    foreach ([['Supplier A', '10.00', '5.00'], ['Supplier B', '15.00', '10.00']] as [$name, $price, $delivery]) {
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
        Offer::factory()->create(['organization_id' => $organization->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id,
            'price' => $price, 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '1.000', 'minimum_packages' => 1,
            'delivery_fee' => $delivery, 'observed_at' => now()->subMinute(), 'created_at' => now()->subMinute(), 'expires_at' => null]);
    }
    $this->actingAs($user)->get('/ai?product_id='.$product->id.'&quantity=2')->assertInertia(fn (Assert $page) => $page
        ->where('insights.quantity', '2')->where('insights.decisions.0.total', '25.00')
        ->where('insights.decisions.0.supplier', 'Supplier A')->where('insights.decisions.0.spread', '15.00')
        ->where('insights.decisions.0.alternatives', 2));
});

it('never recommends expired incomplete or foreign offers', function () {
    $this->freezeTime();
    [$user, $organization] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $organization->id, 'unit' => 'kg']);
    $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
    Offer::factory()->create(['organization_id' => $organization->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id,
        'delivery_fee' => null, 'observed_at' => now()->subDay(), 'created_at' => now()->subDay(), 'expires_at' => now()->subHour()]);
    Offer::factory()->create();
    $this->actingAs($user)->get('/ai')->assertInertia(fn (Assert $page) => $page
        ->where('insights.product_count', 1)->where('insights.observation_count', 1)->where('insights.missing_delivery', 1)
        ->where('insights.decisions.0.status', 'incomplete')->where('insights.decisions.0.total', null));
    $foreign = Product::factory()->create();
    $this->get('/ai?product_id='.$foreign->id)->assertNotFound();
});

it('rejects invalid purchase quantities instead of producing a recommendation', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->get('/ai?quantity=-1')->assertSessionHasErrors('quantity');
});
