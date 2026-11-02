<?php

use App\Jobs\CommitImport;
use App\Jobs\PrepareImport;
use App\Models\ImportBatch;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ManageImports;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('retains private CSV previews and requires explicit human approval before atomic product creation', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    [$user, $org] = marketIdentity();
    $this->actingAs($user)->post('/import-center', ['type' => 'products', 'timezone' => 'Asia/Tashkent',
        'file' => importCsv([['name', 'sku', 'unit'], ['TEST Product', 'TEST-NEW', 'kg']])])->assertRedirect();
    $batch = ImportBatch::sole();
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseHas('import_events', ['action' => 'uploaded']);
    $batch = prepareManaged($batch);
    expect($batch->status)->toBe('awaiting_confirmation');
    expect($batch->error_count)->toBe(0);
    $this->get('/import-center/'.$batch->id)->assertInertia(fn (Assert $page) => $page->component('ManagedImport')->has('rows.data', 1)->missing('batch.file_path')->missing('batch.lease_token'));
    $this->post('/import-center/'.$batch->id.'/confirm', ['revision' => $batch->revision, 'confirm' => 1])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('products', 0);
    $batch = commitManaged($batch);
    expect($batch->status)->toBe('completed');
    expect($batch->summary['created'])->toBe(1);
    expect($batch->reviewed_by)->toBe($user->id);
    commitManaged($batch);
    $this->assertDatabaseCount('products', 1);
    $this->get('/import-center/'.$batch->id.'/file')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    Bus::assertDispatched(CommitImport::class);
});

it('imports immutable offers with exact decimals UTC normalization retained evidence and separate price verification', function () {
    $this->freezeTime();
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    [$user,$org] = marketIdentity('buyer');
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    $file = importCsv([['sku', 'supplier_id', 'price', 'currency', 'unit', 'package_quantity', 'minimum_packages', 'delivery_fee', 'observed_at', 'source_reference'],
        [$product->sku, $supplier->id, '1234567890123456.78', 'UZS', 'g', '250', '5', '', '2026-01-01 10:00:00', 'TEST invoice']]);
    $batch = app(ManageImports::class)->upload($user, $org, $file, ['type' => 'offers', 'timezone' => 'Asia/Tashkent']);
    $batch = prepareManaged($batch);
    expect($batch->error_count)->toBe(0);
    app(ManageImports::class)->confirm($user, $batch, $batch->revision);
    $batch = commitManaged($batch);
    expect($batch->status)->toBe('completed');
    $offer = Offer::sole();
    expect($offer->price)->toBe('1234567890123456.78');
    expect($offer->observed_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 05:00:00');
    expect($offer->delivery_fee)->toBeNull();
    expect($offer->evidence_file_id)->not->toBeNull();
    expect($offer->import_batch_id)->toBe($batch->id);
    $this->assertDatabaseCount('price_reviews', 0);
    commitManaged($batch);
    $this->assertDatabaseCount('offers', 1);
});

it('allows correcting invalid rows and mapping without changing the original extraction or writing business records', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    [$user,$org] = marketIdentity();
    $batch = prepareManaged(app(ManageImports::class)->upload($user, $org, importCsv([['Title', 'Code', 'Measure'], ['TEST row', 'A', 'bad']]), ['type' => 'products', 'timezone' => 'UTC']));
    expect($batch->error_count)->toBe(1);
    $this->actingAs($user)->post('/import-center/'.$batch->id.'/mapping', ['revision' => $batch->revision, 'mapping' => ['name' => 0, 'sku' => 1, 'unit' => 2], 'duplicate_policy' => 'skip', 'error_policy' => 'atomic'])->assertSessionHasNoErrors();
    $batch = $batch->fresh();
    $row = $batch->rows()->sole();
    $this->post('/import-center/'.$batch->id.'/confirm', ['revision' => $batch->revision, 'confirm' => 1])->assertSessionHasErrors('import');
    $this->post('/import-center/'.$batch->id.'/rows/'.$row->id, ['revision' => $batch->revision, 'cells' => ['TEST row', 'A', 'kg']])->assertSessionHasNoErrors();
    $batch = $batch->fresh();
    expect($batch->error_count)->toBe(0);
    $this->post('/import-center/'.$batch->id.'/confirm', ['revision' => $batch->revision - 1, 'confirm' => 1])->assertConflict();
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseHas('import_events', ['action' => 'row_corrected', 'actor_id' => $user->id]);
});

it('rejects ambiguous suppliers and lets administrators inspect imports across partitions', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class]);
    [$user,$org] = marketIdentity();
    $input = offerInput($org);
    Supplier::factory()->count(2)->create(['organization_id' => $org->id, 'name' => 'TEST ambiguous']);
    $batch = prepareManaged(app(ManageImports::class)->upload($user, $org, importCsv([['product_id', 'supplier_name', 'price', 'currency', 'unit', 'package_quantity', 'minimum_packages', 'observed_at', 'source_reference'], [$input['product_id'], 'TEST ambiguous', '1', 'UZS', 'kg', '1', '1', '2026-01-01', 'TEST source']]), ['type' => 'offers', 'timezone' => 'UTC']));
    expect($batch->error_count)->toBe(1);
    expect($batch->rows()->sole()->errors)->toHaveKey('supplier_id');
    $this->actingAs($user)->get('/import-center/'.$batch->id.'/errors')->assertOk();
    [$other,$otherOrg] = marketIdentity();
    $this->actingAs($other)->withSession(['organization_id' => $otherOrg->id])->get('/import-center/'.$batch->id.'/file')->assertOk();
    $this->get('/import-center/'.$batch->id)->assertOk();
});
