<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\AppNotification;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

class BrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || ! str_contains((string) config('database.connections.sqlite.database'), 'smart-market-browser-')) {
            throw new \RuntimeException('Browser fixtures require an isolated testing database.');
        }
        foreach (['desktop', 'mobile'] as $platform) {
            User::factory()->create(['name' => 'Browser Consumer', 'email' => 'consumer-'.$platform.'@example.test', 'password' => 'BrowserPassword123456']);
            User::factory()->create(['name' => 'Platform Admin', 'email' => 'admin-'.$platform.'@example.test', 'password' => 'BrowserPassword123456', 'is_platform_admin' => true]);
            User::factory()->create(['name' => 'Admin Target '.$platform, 'email' => 'admin-target-'.$platform.'@example.test', 'password' => 'BrowserPassword123456']);
            $user = User::factory()->create(['name' => 'Browser Test Owner', 'email' => $platform.'@example.test', 'password' => 'BrowserPassword123456', 'is_platform_admin' => true]);
            $organization = Organization::create(['name' => 'AUTOMATED TEST — '.$platform]);
            $organization->users()->attach($user, ['role' => 'owner']);
            $manager = User::factory()->create(['name' => 'Browser Test Manager', 'email' => 'manager-'.$platform.'@example.test', 'password' => 'BrowserPassword123456', 'is_platform_admin' => true]);
            $organization->users()->attach($manager, ['role' => 'manager']);
            $reviewer = User::factory()->create(['email' => 'reviewer-'.$platform.'@example.test', 'password' => 'BrowserPassword123456', 'is_platform_admin' => true]);
            $organization->users()->attach($reviewer, ['role' => 'owner']);
            $auditor = User::factory()->create(['email' => 'auditor-'.$platform.'@example.test', 'password' => 'BrowserPassword123456', 'is_platform_admin' => true]);
            $organization->users()->attach($auditor, ['role' => 'auditor']);
            $product = Product::factory()->create(['organization_id' => $organization->id, 'name' => 'QUALITY Analytics Product', 'sku' => 'QUALITY-'.$platform]);
            foreach ([['QUALITY Supplier A', '10', '12'], ['QUALITY Supplier B', '100', '90']] as [$name,$previous,$latest]) {
                $supplier = Supplier::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
                foreach ([[$previous, now()->subDay()], [$latest, now()->subMinute()]] as [$price,$observed]) {
                    Offer::factory()->create(['organization_id' => $organization->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'price' => $price, 'observed_at' => $observed, 'created_at' => $observed]);
                }
            }
            foreach ([1, 2] as $index) {
                AppNotification::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'event_type' => 'new_offer', 'dedupe_key' => 'QUALITY-'.$platform.'-'.$index, 'title' => 'QUALITY Notification '.$index, 'body' => 'TEST ONLY', 'email_status' => 'not_requested']);
            }

        }
        $zip = new \ZipArchive;
        $zip->open(dirname((string) config('database.connections.sqlite.database')).'/quality.xlsx', \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>sku</t></is></c><c r="C1" t="inlineStr"><is><t>unit</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>QUALITY XLSX Product</t></is></c><c r="B2" t="inlineStr"><is><t>QUALITY-XLSX</t></is></c><c r="C2" t="inlineStr"><is><t>kg</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $image = imagecreatetruecolor(16, 16);
        imagepng($image, dirname((string) config('database.connections.sqlite.database')).'/fixture.png');
        imagedestroy($image);
    }
}
