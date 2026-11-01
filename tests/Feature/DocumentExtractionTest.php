<?php

use App\Jobs\CommitImport;
use App\Jobs\PrepareImport;
use App\Models\ExtractionAttempt;
use App\Services\FileScanner;
use App\Services\ImportFailure;
use App\Services\ManageImports;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function extractionOutput(): array
{
    $field = fn (string $value) => ['value' => $value, 'confidence' => '0.85', 'page' => 1, 'region' => ['0.1', '0.1', '0.9', '0.9'], 'method' => 'vision'];

    return ['rows' => [['fields' => ['name' => $field('TEST extracted product'), 'sku' => $field('EXTRACTED-1'), 'unit' => $field('kg')], 'warnings' => ['TEST fixture, not a live AI result'], 'missing_fields' => []]],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'cost' => '0.00123456', 'currency' => 'USD']];
}

function configuredExtraction(): void
{
    config(['imports.provider' => 'http', 'imports.model' => 'TEST-MODEL', 'imports.endpoint' => 'https://extract.example.test/v1', 'imports.key' => 'TEST-ONLY-KEY']);
}

it('does not call providers without credentials and supports explicitly reviewed manual extraction', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    Http::preventStrayRequests();
    Http::fake(['https://extract.example.test/v1' => Http::response([])]);
    [$user,$org] = marketIdentity();
    $batch = prepareManaged(app(ManageImports::class)->upload($user, $org, UploadedFile::fake()->createWithContent('TEST.pdf', "%PDF-1.4\nTEST ONLY document"), ['type' => 'products', 'timezone' => 'UTC']));
    expect($batch->status)->toBe('failed');
    expect($batch->error_code)->toBe('provider_unavailable');
    $this->actingAs($user)->get('/import-center/'.$batch->id.'/file')->assertOk();
    app(ManageImports::class)->manualRow($user, $batch);
    $batch = $batch->fresh();
    $row = $batch->rows()->sole();
    $cells = array_fill(0, count($batch->headers), null);
    foreach (['name' => 'TEST manually read', 'sku' => 'MANUAL-1', 'unit' => 'kg'] as $field => $value) {
        $cells[array_search($field, $batch->headers, true)] = $value;
    }
    app(ManageImports::class)->correct($user, $batch, $row->id, ['revision' => $batch->revision, 'cells' => $cells]);
    $batch = $batch->fresh();
    expect($batch->error_count)->toBe(0);
    app(ManageImports::class)->confirm($user, $batch, $batch->revision);
    expect(commitManaged($batch)->status)->toBe('completed');
    $this->assertDatabaseHas('products', ['sku' => 'MANUAL-1']);
    Http::assertNothingSent();
});

it('validates structured extraction confidence and usage before explicit human corrections and approval', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    configuredExtraction();
    Http::preventStrayRequests();
    $outerTransaction = DB::transactionLevel();
    $requestTransaction = null;
    Http::fake(['https://extract.example.test/v1' => function () use (&$requestTransaction) {
        $requestTransaction = DB::transactionLevel();

        return Http::response(extractionOutput());
    }]);
    [$user,$org] = marketIdentity();
    $batch = app(ManageImports::class)->upload($user, $org, UploadedFile::fake()->createWithContent('TEST.pdf', "%PDF-1.4\nTEST ONLY fixture"), ['type' => 'products', 'timezone' => 'UTC']);
    $outerTransaction = DB::transactionLevel();
    $batch = prepareManaged($batch);
    expect($requestTransaction)->toBe($outerTransaction);
    expect($batch->status)->toBe('awaiting_confirmation');
    expect($batch->error_count)->toBe(0);
    $this->assertDatabaseCount('products', 0);
    $row = $batch->rows()->sole();
    expect($row->extraction['fields']['name']['confidence'])->toBe('0.85');
    expect($row->extraction['missing_fields'])->toContain('barcode');
    $attempt = ExtractionAttempt::sole();
    expect($attempt->cost)->toBe('0.00123456');
    expect($attempt->input_tokens)->toBe(120);
    $cells = $row->raw;
    $cells[array_search('name', $batch->headers, true)] = 'TEST corrected human name';
    app(ManageImports::class)->correct($user, $batch, $row->id, ['revision' => $batch->revision, 'cells' => $cells]);
    $batch = $batch->fresh();
    expect($row->fresh()->extraction['fields']['name']['value'])->toBe('TEST extracted product');
    app(ManageImports::class)->confirm($user, $batch, $batch->revision);
    commitManaged($batch);
    $this->assertDatabaseHas('products', ['name' => 'TEST corrected human name']);
    $this->assertDatabaseCount('price_reviews', 0);
    Http::assertSentCount(1);
});

it('rejects malformed or verification claiming AI output without creating records', function (string $case) {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class]);
    configuredExtraction();
    Http::preventStrayRequests();
    $output = extractionOutput();
    if ($case === 'unknown') {
        $output['rows'][0]['fields']['verification_status'] = $output['rows'][0]['fields']['name'];
    }
    if ($case === 'confidence') {
        $output['rows'][0]['fields']['name']['confidence'] = '1.1';
    }
    if ($case === 'number') {
        $output['rows'][0]['fields']['name']['value'] = 1234567890123456.78;
    }
    if ($case === 'region') {
        $output['rows'][0]['fields']['name']['region'] = ['0.9', '0.9', '0.1', '0.1'];
    }
    Http::fake(['https://extract.example.test/v1' => Http::response($output)]);
    [$user,$org] = marketIdentity();
    $batch = prepareManaged(app(ManageImports::class)->upload($user, $org, UploadedFile::fake()->createWithContent('TEST.pdf', "%PDF-1.4\nTEST ONLY invalid output"), ['type' => 'products', 'timezone' => 'UTC']));
    expect($batch->status)->toBe('failed');
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('import_rows', 0);
    Http::assertSentCount(1);
})->with(['unknown', 'confidence', 'number', 'region']);

it('retries transient provider failures with a stable request key and no duplicate business records', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    configuredExtraction();
    Http::preventStrayRequests();
    Http::fake(['https://extract.example.test/v1' => Http::sequence()->push(['error' => 'TEST only'], 503)->push(extractionOutput())]);
    [$user,$org] = marketIdentity();
    $batch = app(ManageImports::class)->upload($user, $org, UploadedFile::fake()->createWithContent('TEST.pdf', "%PDF-1.4\nTEST ONLY retry"), ['type' => 'products', 'timezone' => 'UTC']);
    expect(fn () => prepareManaged($batch))->toThrow(ImportFailure::class, 'provider_transient');
    expect($batch->fresh()->status)->toBe('queued');
    $batch = prepareManaged($batch->fresh());
    expect($batch->status)->toBe('awaiting_confirmation');
    expect(ExtractionAttempt::sole()->transport_attempts)->toBe(2);
    app(ManageImports::class)->confirm($user, $batch, $batch->revision);
    commitManaged($batch);
    commitManaged($batch);
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('extraction_attempts', 1);
    Http::assertSentCount(2);
});

it('blocks infected documents and required but unavailable scanner access', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class]);
    [$user,$org] = marketIdentity();
    app()->instance(FileScanner::class, new class implements FileScanner
    {
        public function scan(string $path): string
        {
            return 'infected';
        }
    });
    $batch = prepareManaged(app(ManageImports::class)->upload($user, $org, UploadedFile::fake()->createWithContent('TEST.pdf', "%PDF-1.4\nTEST ONLY flagged"), ['type' => 'products', 'timezone' => 'UTC']));
    expect($batch->error_code)->toBe('malware_detected');
    $this->actingAs($user)->get('/import-center/'.$batch->id.'/file')->assertNotFound();
    $this->post('/import-center/'.$batch->id.'/manual')->assertConflict();
});
