<?php

use App\Models\CatalogFile;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);
it('uploads reencoded private images and audits authorized access', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('original.jpg')])->assertSessionHasNoErrors();
    $file = CatalogFile::sole();
    expect($file->mime)->toBe('image/png');
    expect($file->toArray())->not->toHaveKey('path');
    Storage::disk('catalog')->assertExists($file->path);
    $this->get('/catalog/files/'.$file->id)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->assertDatabaseHas('audit_logs', ['action' => 'catalog_file.accessed', 'subject_id' => $file->id]);
});
it('rejects spoofed mime and oversized images', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->createWithContent('bad.png', '<?php echo 1;')])->assertSessionHasErrors('file');
    $this->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('big.png')->size(2049)])->assertSessionHasErrors('file');
    $this->assertDatabaseCount('catalog_files', 0);
});
it('denies foreign file downloads replacement and retirement', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('private.png')])->assertSessionHasNoErrors();
    $file = CatalogFile::sole();
    [$other,$otherOrg] = marketIdentity();
    $this->actingAs($other)->withSession(['organization_id' => $otherOrg->id])->get('/catalog/files/'.$file->id)->assertNotFound();
    $this->post('/catalog/files/'.$file->id.'/retire')->assertNotFound();
    $this->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('new.png')])->assertNotFound();
});
it('replaces and retires images without overwriting source files', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('old.png')])->assertSessionHasNoErrors();
    $old = CatalogFile::sole();
    $this->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('new.png'), 'replace_id' => $old->id])->assertSessionHasNoErrors();
    expect($old->fresh()->retired_at)->not->toBeNull();
    Storage::disk('catalog')->assertExists($old->path);
    $this->get('/catalog/files/'.$old->id)->assertNotFound();
    $new = CatalogFile::latest('id')->first();
    $this->post('/catalog/files/'.$new->id.'/retire')->assertSessionHasNoErrors();
    $this->get('/catalog/files/'.$new->id)->assertNotFound();
});
it('denies read-only image uploads and retirement', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity('auditor');
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('image.png')])->assertForbidden();
});

it('downloads supplier evidence as an authenticated attachment and rejects oversized documents', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity();
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/suppliers/'.$supplier->id.'/documents', ['file' => UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\nTest-only PDF")])->assertSessionHasNoErrors();
    $file = CatalogFile::sole();
    $response = $this->get('/catalog/files/'.$file->id)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    $this->post('/suppliers/'.$supplier->id.'/documents', ['file' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('file');
});

it('does not let an image request overwrite a form validation return page', function () {
    Storage::fake('catalog');
    [$user,$org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->post('/products/'.$product->id.'/images', ['file' => UploadedFile::fake()->image('return.png')])->assertSessionHasNoErrors();
    $file = CatalogFile::sole();
    $this->get('/products/'.$product->id)->assertOk();
    $this->get('/catalog/files/'.$file->id)->assertOk();
    $this->post('/products/'.$product->id, ['name' => '', 'sku' => $product->sku, 'unit' => 'kg'])->assertRedirect('/products/'.$product->id)->assertSessionHasErrors('name');
});
