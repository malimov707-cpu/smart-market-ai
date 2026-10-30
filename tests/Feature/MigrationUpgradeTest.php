<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('preserves existing users and memberships while upgrading identity schema', function () {
    config(['database.connections.identity_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    $connection = DB::connection('identity_upgrade');
    $original = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/2026_10_29_203945_create_market_tables.php',
    ];
    foreach ($original as $path) {
        Artisan::call('migrate', ['--database' => 'identity_upgrade', '--path' => $path, '--force' => true]);
    }
    $connection->table('users')->insert(['id' => 1, 'name' => 'Existing Test Owner', 'email' => 'preserved@example.test', 'password' => 'test-fixture-only']);
    $connection->table('organizations')->insert(['id' => 1, 'name' => 'Existing Test Organization']);
    $connection->table('organization_user')->insert(['organization_id' => 1, 'user_id' => 1, 'role' => 'owner']);
    $path = collect(glob(database_path('migrations/*extend_identity_and_organization_management.php')))->sole();
    Artisan::call('migrate', ['--database' => 'identity_upgrade', '--path' => $path, '--realpath' => true, '--force' => true]);
    expect($connection->table('users')->where('id', 1)->value('name'))->toBe('Existing Test Owner');
    expect($connection->table('users')->where('id', 1)->value('is_active'))->toBe(1);
    expect($connection->table('organization_user')->where('user_id', 1)->value('role'))->toBe('owner');
    $connection->table('organization_user')->where('user_id', 1)->update(['role' => 'buyer']);
    expect($connection->table('organization_user')->where('user_id', 1)->value('role'))->toBe('buyer');
    DB::purge('identity_upgrade');
});
