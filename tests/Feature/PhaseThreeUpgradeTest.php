<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('preserves pre phase three prices provenance and identifiers during an additive upgrade', function () {
    config(['database.connections.price_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    $migration = null;
    foreach (glob(database_path('migrations/*.php')) as $path) {
        if (str_contains($path, 'extend_phase_three_price_intelligence')) {
            $migration = $path;
            break;
        }
        Artisan::call('migrate', ['--database' => 'price_upgrade', '--path' => $path, '--realpath' => true, '--force' => true]);
    }
    $db = DB::connection('price_upgrade');
    $db->table('organizations')->insert(['id' => 1, 'name' => 'TEST existing organization']);
    $db->table('products')->insert(['id' => 1, 'organization_id' => 1, 'name' => 'TEST existing product', 'sku' => 'EXISTING', 'unit' => 'kg']);
    $db->table('suppliers')->insert(['id' => 1, 'organization_id' => 1, 'name' => 'TEST existing supplier']);
    $db->table('offers')->insert(['id' => 1, 'organization_id' => 1, 'product_id' => 1, 'supplier_id' => 1, 'price' => '123.45',
        'unit' => 'kg', 'observed_at' => '2026-10-01 00:00:00', 'created_at' => '2026-10-01 01:00:00', 'provenance' => 'csv', 'source_reference' => 'TEST original invoice', 'fingerprint' => str_repeat('a', 64)]);
    Artisan::call('migrate', ['--database' => 'price_upgrade', '--path' => $migration, '--realpath' => true, '--force' => true]);
    $row = $db->table('offers')->find(1);
    expect((string) $row->price)->toBe('123.45');
    expect($row->source_reference)->toBe('TEST original invoice');
    expect($row->provenance)->toBe('csv');
    expect($row->recorded_at)->toBeNull();
    expect($row->submitted_by)->toBeNull();
    expect($row->created_at)->toBe('2026-10-01 01:00:00');
    expect($db->select('PRAGMA foreign_key_check'))->toBe([]);
    DB::purge('price_upgrade');
});
