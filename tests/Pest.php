<?php

use App\Jobs\CommitImport;
use App\Jobs\PrepareImport;
use App\Models\ImportBatch;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ManageImports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function marketIdentity(string $role = 'owner', bool $isPlatformAdmin = true): array
{
    $user = User::factory()->create(['is_platform_admin' => $isPlatformAdmin]);
    $organization = Organization::factory()->create();
    $organization->users()->attach($user, ['role' => $role]);

    return [$user, $organization];
}

function offerInput(Organization $organization): array
{
    return [
        'product_id' => Product::factory()->create(['organization_id' => $organization->id])->id,
        'supplier_id' => Supplier::factory()->create(['organization_id' => $organization->id])->id,
        'price' => '10000.00', 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '1.000',
        'minimum_packages' => 1, 'delivery_fee' => '0.00',
        'observed_at' => now()->toIso8601String(), 'expires_at' => null, 'source_reference' => 'TEST invoice 001',
    ];
}

function comparisonUser(Offer $offer): User
{
    $user = User::factory()->create(['is_platform_admin' => true]);
    $user->organizations()->attach($offer->organization_id, ['role' => 'auditor']);

    return $user;
}

function importCsv(array $rows): UploadedFile
{
    $stream = fopen('php://temp', 'w+b');
    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '');
    }
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);

    return UploadedFile::fake()->createWithContent('TEST-import.csv', $contents);
}

function prepareManaged(ImportBatch $batch): ImportBatch
{
    (new PrepareImport($batch->id, $batch->generation))->handle(app(ManageImports::class));

    return $batch->fresh();
}

function commitManaged(ImportBatch $batch): ImportBatch
{
    $batch = $batch->fresh();
    (new CommitImport($batch->id, $batch->generation))->handle(app(ManageImports::class));

    return $batch->fresh();
}

function importXlsx(array $sheets, array $extra = []): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'smart-market-xlsx-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $xml = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sheetList = '';
    $rels = '';
    $sheetIndex = 0;
    foreach ($sheets as $name => $rows) {
        $sheetIndex++;
        $sheetList .= '<sheet name="'.$xml($name).'" sheetId="'.$sheetIndex.'" r:id="rId'.$sheetIndex.'"/>';
        $rels .= '<Relationship Id="rId'.$sheetIndex.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetIndex.'.xml"/>';
        $body = '';
        foreach ($rows as $index => $values) {
            $rowNumber = $index + 1;
            $cells = '';
            foreach ($values as $column => $value) {
                $letters = '';
                $number = $column + 1;
                while ($number > 0) {
                    $number--;
                    $letters = chr(65 + $number % 26).$letters;
                    $number = intdiv($number, 26);
                }
                $address = $letters.$rowNumber;
                if (is_array($value)) {
                    $cells .= '<c r="'.$address.'" t="'.$xml($value['type'] ?? 'n').'" s="'.($value['style'] ?? 0).'">'.(isset($value['formula']) ? '<f>'.$xml($value['formula']).'</f>' : '').'<v>'.$xml($value['value'] ?? '').'</v></c>';
                } else {
                    $cells .= '<c r="'.$address.'" t="inlineStr"><is><t>'.$xml($value).'</t></is></c>';
                }
            }
            $body .= '<row r="'.$rowNumber.'">'.$cells.'</row>';
        }
        $zip->addFromString('xl/worksheets/sheet'.$sheetIndex.'.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$body.'</sheetData></worksheet>');
    }
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetList.'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
    $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');
    foreach ($extra as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $contents = file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('TEST-workbook.xlsx', $contents);
}
