<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('preserves existing products suppliers and offer history during phase two upgrade', function () {
    config(['database.connections.catalog_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    foreach (['0001_01_01_000000_create_users_table.php', '2026_10_29_203945_create_market_tables.php', '2026_10_29_215043_extend_identity_and_organization_management.php'] as $file) {
        Artisan::call('migrate', ['--database' => 'catalog_upgrade', '--path' => 'database/migrations/'.$file, '--force' => true]);
    }
    $db = DB::connection('catalog_upgrade');
    $db->table('users')->insert(['id' => 1, 'name' => 'Existing Test User', 'email' => 'upgrade-test@example.test', 'password' => 'TEST-ONLY']);
    $db->table('organizations')->insert(['id' => 1, 'name' => 'Existing Test Organization']);
    $db->table('products')->insert(['id' => 1, 'organization_id' => 1, 'name' => 'Existing Test Product', 'sku' => 'OLD', 'unit' => 'kg']);
    $db->table('suppliers')->insert(['id' => 1, 'organization_id' => 1, 'name' => 'Existing Test Supplier']);
    $db->table('offers')->insert(['id' => 1, 'organization_id' => 1, 'product_id' => 1, 'supplier_id' => 1, 'price' => '123.45', 'unit' => 'kg', 'package_quantity' => '1', 'minimum_packages' => 1, 'observed_at' => '2026-01-01', 'provenance' => 'manual', 'source_reference' => 'TEST-ONLY', 'fingerprint' => str_repeat('a', 64)]);
    $migration = collect(glob(database_path('migrations/*extend_phase_two_catalog.php')))->sole();
    Artisan::call('migrate', ['--database' => 'catalog_upgrade', '--path' => $migration, '--realpath' => true, '--force' => true]);
    expect($db->table('offers')->where('id', 1)->value('source_reference'))->toBe('TEST-ONLY');
    expect($db->table('suppliers')->where('id', 1)->value('verification_status'))->toBe('unverified');
    expect($db->table('products')->where('id', 1)->value('sku'))->toBe('OLD');
    expect($db->table('products')->where('id', 1)->value('barcode'))->toBeNull();
    DB::purge('catalog_upgrade');
});
