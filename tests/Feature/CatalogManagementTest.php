<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);
function catalogInput(array $overrides = []): array
{
    return [...['name' => 'Test Flour', 'sku' => 'CAT-1', 'unit' => 'kg', 'barcode' => '1234567890123', 'aliases' => ['Un'], 'package_quantity' => '25', 'package_unit' => 'kg'], ...$overrides];
}

it('creates reads updates archives and restores a product with metadata', function () {
    [$user,$org] = marketIdentity();
    $this->actingAs($user)->post('/products', catalogInput(['organization_id' => 999]))->assertSessionHasNoErrors();
    $product = Product::sole();
    expect($product->organization_id)->toBe($org->id);
    $this->get('/products/'.$product->id)->assertInertia(fn (Assert $page) => $page->component('Product')->where('product.aliases.0', 'Un'));
    $this->post('/products/'.$product->id, catalogInput(['name' => 'Updated']))->assertSessionHasNoErrors();
    expect($product->fresh()->name)->toBe('Updated');
    $this->post('/products/'.$product->id.'/archive')->assertSessionHasNoErrors();
    expect($product->fresh()->archived_at)->not->toBeNull();
    $this->post('/products/'.$product->id.'/restore')->assertSessionHasNoErrors();
    expect($product->fresh()->archived_at)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.restored']);
});
it('rejects duplicate sku barcode incompatible packages and aliases', function (string $field, mixed $value) {
    [$user,$org] = marketIdentity();
    Product::factory()->create(['organization_id' => $org->id, 'sku' => 'DUP', 'barcode' => '12345678']);
    $this->actingAs($user)->post('/products', catalogInput([$field => $value]))->assertRedirect()->assertSessionHasErrors($field === 'aliases' ? 'aliases.0' : $field);
})->with([['sku', 'DUP'], ['barcode', '12345678'], ['barcode', 'not-a-barcode'], ['package_unit', 'l'], ['aliases', ['same', 'same']]]);
it('creates a variant with a separate SKU and protects parent consistency', function () {
    [$user,$org] = marketIdentity();
    $parent = Product::factory()->create(['organization_id' => $org->id, 'unit' => 'kg']);
    $this->actingAs($user)->post('/products', catalogInput(['parent_product_id' => $parent->id, 'variant_attributes' => [['name' => 'grade', 'value' => 'A']]]))->assertSessionHasNoErrors();
    $this->post('/products/'.$parent->id.'/archive')->assertSessionHasErrors('product');
    $variant = Product::where('parent_product_id', $parent->id)->sole();
    expect($variant->variant_attributes[0]['value'])->toBe('A');
    $this->post('/products', catalogInput(['sku' => 'BAD', 'barcode' => null, 'parent_product_id' => $variant->id, 'variant_attributes' => [['name' => 'x', 'value' => 'y']]]))->assertSessionHasErrors('parent_product_id');
});
it('denies cross tenant product and supplier CRUD by direct URL', function () {
    [$user] = marketIdentity();
    $product = Product::factory()->create();
    $supplier = Supplier::factory()->create();
    $this->actingAs($user)->get('/products/'.$product->id)->assertNotFound();
    $this->post('/products/'.$product->id, catalogInput())->assertNotFound();
    $this->post('/products/'.$product->id.'/restore')->assertNotFound();
    $this->get('/suppliers/'.$supplier->id)->assertNotFound();
    $this->post('/suppliers/'.$supplier->id.'/archive')->assertNotFound();
});
it('edits archives and restores suppliers without losing offers', function () {
    [$user,$org] = marketIdentity();
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/suppliers/'.$offer->supplier_id, ['name' => 'Updated Supplier', 'city' => 'Toshkent', 'verification_status' => 'verified'])->assertSessionHasNoErrors();
    expect($offer->supplier->fresh()->verification_status)->toBe('unverified');
    $this->post('/suppliers/'.$offer->supplier_id.'/archive')->assertSessionHasNoErrors();
    expect($offer->supplier->fresh()->archived_at)->not->toBeNull();
    $this->assertDatabaseHas('offers', ['id' => $offer->id]);
    $this->post('/suppliers/'.$offer->supplier_id.'/restore')->assertSessionHasNoErrors();
    expect($offer->supplier->fresh()->archived_at)->toBeNull();
});
it('denies catalog mutations to read-only and buyer roles', function (string $role) {
    [$user,$org] = marketIdentity($role);
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id, catalogInput())->assertForbidden();
    $this->post('/products/'.$product->id.'/restore')->assertForbidden();
})->with(['buyer', 'analyst', 'auditor']);
it('paginates remote catalog beyond five hundred records and rejects unsafe ordering', function () {
    [$user,$org] = marketIdentity();
    Product::factory()->count(501)->create(['organization_id' => $org->id]);
    $last = Product::factory()->create(['organization_id' => $org->id, 'name' => 'Needle501', 'aliases' => ['SpecialAlias']]);
    $this->actingAs($user)->get('/catalog/products/lookup?q=SpecialAlias')->assertJsonPath('data.0.id', $last->id);
    $this->get('/catalog/products/lookup')->assertJsonCount(20, 'data')->assertJsonPath('total', 502);
    $this->get('/products?sort=name%20desc%3Bdrop')->assertSessionHasErrors('sort');
});

it('filters archived suppliers and paginates search in the selected tenant', function () {
    [$user,$org] = marketIdentity();
    Supplier::factory()->count(22)->create(['organization_id' => $org->id]);
    $archived = Supplier::factory()->create(['organization_id' => $org->id, 'name' => 'ARCHIVED-NEEDLE', 'archived_at' => now()]);
    Supplier::factory()->create(['name' => 'FOREIGN-NEEDLE']);
    $this->actingAs($user)->get('/suppliers')->assertInertia(fn (Assert $page) => $page->has('suppliers.data', 20)->where('suppliers.total', 22));
    $this->get('/suppliers?status=archived&q=NEEDLE')->assertInertia(fn (Assert $page) => $page->has('suppliers.data', 1)->where('suppliers.data.0.id', $archived->id));
    $this->get('/catalog/suppliers/lookup?q=NEEDLE')->assertJsonPath('total', 0);
});

it('preserves unit identity after offers exist and rejects new offers from archived suppliers', function () {
    [$user,$org] = marketIdentity();
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$offer->product_id, ['name' => 'Rename', 'sku' => $offer->product->sku, 'unit' => 'l'])->assertSessionHasErrors('unit');
    $this->post('/suppliers/'.$offer->supplier_id.'/archive')->assertSessionHasNoErrors();
    $input = ['product_id' => $offer->product_id, 'supplier_id' => $offer->supplier_id, 'price' => '10', 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '1', 'minimum_packages' => 1, 'observed_at' => now()->toIso8601String(), 'source_reference' => 'archived test'];
    $this->post('/offers', $input)->assertSessionHasErrors('supplier_id');
    $this->assertDatabaseCount('offers', 1);
});

it('keeps validation on the Inertia detail page after remote lookups', function () {
    [$user,$org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')), 'X-Requested-With' => 'XMLHttpRequest'])->get('/products/'.$product->id)->assertOk();
    $this->withHeaders(['X-Inertia' => '', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->get('/catalog/products/lookup')->assertOk();
    $this->withHeaders(['Accept' => 'text/html', 'X-Requested-With' => ''])->post('/products/'.$product->id, ['name' => '', 'sku' => $product->sku, 'unit' => 'kg'])->assertRedirect('/products/'.$product->id)->assertSessionHasErrors('name');
});

it('rejects object-shaped aliases that would break array rendering', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/products', catalogInput(['aliases' => ['key' => 'not a list']]))->assertSessionHasErrors('aliases');
});

it('does not let background catalog reads consume pending success feedback', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/products', catalogInput())->assertRedirect('/products');
    $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->get('/catalog/products/lookup')->assertOk();
    $this->withHeaders(['X-Requested-With' => '', 'Accept' => 'text/html'])->get('/products')->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Mahsulot qo‘shildi.'));
});
