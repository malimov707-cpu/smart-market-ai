<?php

use App\Jobs\PrepareImport;
use App\Services\ManageImports;
use App\Services\SpreadsheetReader;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('reads actual XLSX sheets headers exact scientific numbers and Excel date styles', function () {
    $file = importXlsx(['First' => [['title'], ['note']], 'Prices' => [['Title', 'Amount', 'Date'], ['TEST rice', ['value' => '1.23456789012345678E15'], ['value' => '45292', 'style' => 1]]]]);
    $parsed = app(SpreadsheetReader::class)->read($file->getRealPath(), 'xlsx', 1, 1, 'Asia/Tashkent');
    expect($parsed['sheets'])->toBe(['First', 'Prices']);
    expect($parsed['headers'])->toBe(['Title', 'Amount', 'Date']);
    expect($parsed['rows'][2])->toBe(['TEST rice', '1234567890123456.78', '2023-12-31T19:00:00+00:00']);
});

it('rejects formulas macros external links unsafe paths and XML entities without extracting files', function (string $kind) {
    $extra = match ($kind) {
        'macro' => ['xl/vbaProject.bin' => 'TEST binary'],
        'path' => ['../escape.xml' => 'TEST'],
        'external' => ['xl/worksheets/_rels/sheet1.xml.rels' => "<Relationships><Relationship Id='ext' TargetMode='external' Target='https://example.test/private'/></Relationships>"],
        'entity' => ['xl/workbook.xml' => '<!DOCTYPE workbook [<!ENTITY x SYSTEM "file:///nonexistent-test-file">]><workbook>&x;</workbook>'],
        default => [],
    };
    $value = $kind === 'formula' ? ['formula' => '1+1', 'value' => '2'] : 'TEST';
    $file = importXlsx(['Data' => [['name'], [$value]]], $extra);
    expect(fn () => app(SpreadsheetReader::class)->read($file->getRealPath(), 'xlsx'))->toThrow(ValidationException::class);
})->with(['formula', 'macro', 'path', 'external', 'entity']);

it('rejects invalid workbooks decompression bombs duplicate headers and invalid encodings', function () {
    $file = UploadedFile::fake()->createWithContent('TEST.xlsx', 'not a workbook');
    expect(fn () => app(SpreadsheetReader::class)->read($file->getRealPath(), 'xlsx'))->toThrow(ValidationException::class);
    $bomb = importXlsx(['Data' => [['name'], ['TEST']]], ['xl/huge.xml' => str_repeat('A', 9000000)]);
    expect(fn () => app(SpreadsheetReader::class)->read($bomb->getRealPath(), 'xlsx'))->toThrow(ValidationException::class);
    $csv = UploadedFile::fake()->createWithContent('TEST.csv', "\xEF\xBB\xBFname,name\nA,B\n");
    expect(fn () => app(SpreadsheetReader::class)->read($csv->getRealPath(), 'csv'))->toThrow(ValidationException::class);
    $bad = UploadedFile::fake()->createWithContent('TEST.csv', "name,sku,unit\nTEST,\0,kg\n");
    expect(fn () => app(SpreadsheetReader::class)->read($bad->getRealPath(), 'csv'))->toThrow(ValidationException::class);
});

it('processes selected XLSX sheets through the managed product workflow', function () {
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class]);
    [$user,$org] = marketIdentity();
    $batch = app(ManageImports::class)->upload($user, $org, importXlsx(['Data' => [['name', 'sku', 'unit'], ['TEST XLSX', 'XLSX-1', 'kg']], 'Second' => [['name', 'sku', 'unit'], ['TEST second', 'XLSX-2', 'kg']]]), ['type' => 'products', 'timezone' => 'UTC']);
    $batch = prepareManaged($batch);
    expect($batch->error_count)->toBe(0);
    expect($batch->rows()->sole()->data['sku'])->toBe('XLSX-1');
    app(ManageImports::class)->selectSheet($user, $batch, 1, 1);
    $batch = prepareManaged($batch->fresh());
    expect($batch->rows()->sole()->data['sku'])->toBe('XLSX-2');
});
