<?php

use App\Models\Product;
use App\Models\ProductImport;
use App\Services\ProductCsv;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);
it('previews maps confirms and repeats a product import without duplicate writes', function () {
    [$user,$org] = marketIdentity();
    $this->actingAs($user)->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('products.csv', "Title,Code,Measure\nTest Product,SKU1,kg\n")])->assertRedirect();
    $batch = ProductImport::sole();
    $this->assertDatabaseCount('products', 0);
    $this->post('/catalog/product-imports/'.$batch->id.'/mapping', ['mapping' => ['name' => 0, 'sku' => 1, 'unit' => 2]])->assertSessionHasNoErrors();
    $this->get('/catalog/product-imports/'.$batch->id)->assertInertia(fn (Assert $page) => $page->component('ProductImport')->where('preview.0.errors', []));
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasNoErrors();
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('products', ['organization_id' => $org->id, 'sku' => 'SKU1']);
    $this->assertDatabaseCount('products', 1);
    expect($batch->fresh()->summary)->toBe(['created' => 1, 'duplicates' => 0]);
});
it('reports invalid rows atomically and exports a safe error report', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('bad.csv', "name,sku,unit\nGood,GOOD,kg\nBad,BAD,invented\n")])->assertRedirect();
    $batch = ProductImport::sole();
    $this->get('/catalog/product-imports/'.$batch->id)->assertInertia(fn (Assert $page) => $page->has('preview.1.errors', 1));
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasErrors('import');
    $this->assertDatabaseCount('products', 0);
    $this->get('/catalog/product-imports/'.$batch->id.'/errors')->assertOk()->assertDownload();
});
it('detects exact duplicates and conflicting skus without updating history', function () {
    [$user,$org] = marketIdentity();
    Product::factory()->create(['organization_id' => $org->id, 'sku' => 'EXISTING', 'name' => 'Original', 'unit' => 'kg']);
    $this->actingAs($user)->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('dups.csv', "name,sku,unit\nOriginal,EXISTING,kg\nOther,NEW,kg\nOther,NEW,kg\n")])->assertRedirect();
    $batch = ProductImport::sole();
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasNoErrors();
    expect($batch->fresh()->summary)->toBe(['created' => 1, 'duplicates' => 2]);
    $this->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('conflict.csv', "name,sku,unit\nChanged,EXISTING,kg\n")])->assertRedirect();
    $conflict = ProductImport::latest('id')->first();
    $this->post('/catalog/product-imports/'.$conflict->id.'/confirm')->assertSessionHasErrors('import');
    expect(Product::where('sku', 'EXISTING')->value('name'))->toBe('Original');
});
it('escapes formula cells and isolates exports and import batches', function () {
    [$user,$org] = marketIdentity();
    Product::factory()->create(['organization_id' => $org->id, 'name' => '=HYPERLINK("bad")', 'sku' => 'SAFE']);
    $foreign = Product::factory()->create(['name' => 'FOREIGN-SECRET']);
    $response = $this->actingAs($user)->get('/catalog/products/export')->assertOk()->assertDownload();
    $content = $response->streamedContent();
    expect($content)->toContain("'=HYPERLINK")->not->toContain('FOREIGN-SECRET');
    $this->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('file.csv', "name,sku,unit\nTest,T,kg\n")]);
    $batch = ProductImport::sole();
    [$other,$otherOrg] = marketIdentity();
    $this->actingAs($other)->withSession(['organization_id' => $otherOrg->id])->get('/catalog/product-imports/'.$batch->id)->assertNotFound();
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertNotFound();
});

it('refuses expired previews and repeated barcodes before any import', function () {
    $this->freezeTime();
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('barcodes.csv', "name,sku,unit,barcode\nOne,A,kg,12345678\nTwo,B,kg,12345678\n")])->assertRedirect();
    $batch = ProductImport::sole();
    $this->get('/catalog/product-imports/'.$batch->id)->assertInertia(fn (Assert $page) => $page->has('preview.1.errors', 1));
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasErrors('import');
    $this->travel(8)->days();
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasErrors('import');
    $this->assertDatabaseCount('products', 0);
});

it('imports variant attributes and aliases with an existing tenant parent SKU', function () {
    [$user,$org] = marketIdentity();
    $parent = Product::factory()->create(['organization_id' => $org->id, 'sku' => 'PARENT', 'unit' => 'kg']);
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['name', 'sku', 'unit', 'aliases', 'variant_attributes', 'parent_sku'], ',', '"', '');
    fputcsv($handle, ['Variant', 'CHILD', 'kg', json_encode(['Alias']), json_encode([['name' => 'grade', 'value' => 'A']]), 'PARENT'], ',', '"', '');
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    $this->actingAs($user)->post('/catalog/product-imports', ['file' => UploadedFile::fake()->createWithContent('variant.csv', $csv)])->assertRedirect();
    $batch = ProductImport::sole();
    $this->post('/catalog/product-imports/'.$batch->id.'/confirm')->assertSessionHasNoErrors();
    $child = Product::where('sku', 'CHILD')->sole();
    expect($child->parent_product_id)->toBe($parent->id);
    expect($child->aliases)->toBe(['Alias']);
    expect($child->variant_attributes)->toBe([['name' => 'grade', 'value' => 'A']]);
});

it('keeps preview database reads bounded as row count grows', function () {
    [$user,$org] = marketIdentity();
    $rows = [];
    foreach (range(1, 100) as $number) {
        $rows[] = ['Product '.$number, 'BATCH-'.$number, 'kg'];
    }
    $batch = ProductImport::create(['organization_id' => $org->id, 'user_id' => $user->id, 'headers' => ['name', 'sku', 'unit'], 'rows' => $rows, 'mapping' => ['name' => 0, 'sku' => 1, 'unit' => 2], 'expires_at' => now()->addDay()]);
    DB::enableQueryLog();
    $preview = app(ProductCsv::class)->preview($batch);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($preview)->toHaveCount(100);
    expect(count($queries))->toBeLessThanOrEqual(4);
});
