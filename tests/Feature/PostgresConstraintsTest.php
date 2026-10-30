<?php

use App\Models\CatalogFile;
use App\Models\Offer;
use App\Models\SupplierReview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('enforces financial and expiry checks in PostgreSQL itself', function (array $attributes) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a real PostgreSQL database.');
    }
    expect(fn () => Offer::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'nonpositive price' => [['price' => '0']],
    'nonpositive package' => [['package_quantity' => '0']],
    'nonpositive MOQ' => [['minimum_packages' => 0]],
    'negative delivery' => [['delivery_fee' => '-1']],
    'expiry before observation' => [['observed_at' => '2026-01-02', 'expires_at' => '2026-01-01']],
]);

it('enforces phase two package ownership and rating constraints in PostgreSQL', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a real PostgreSQL database.');
    }
    [$user,$org] = marketIdentity();
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $action = match ($case) {
        'package' => fn () => $offer->product->update(['package_quantity' => null, 'package_unit' => 'kg']),
        'self-parent' => fn () => $offer->product->update(['parent_product_id' => $offer->product_id]),
        'file-owner' => fn () => CatalogFile::create(['organization_id' => $org->id, 'uploaded_by' => $user->id, 'name' => 'test', 'path' => 'test-only', 'mime' => 'image/png', 'size' => 1]),
        'rating' => fn () => SupplierReview::create(['organization_id' => $org->id, 'supplier_id' => $offer->supplier_id, 'user_id' => $user->id, 'offer_id' => $offer->id, 'rating' => 0, 'body' => 'Test-only bad rating']),
    };
    expect($action)->toThrow(QueryException::class);
})->with(['package', 'self-parent', 'file-owner', 'rating']);

it('enforces exchange rate positivity interval and independent approval in PostgreSQL', function (string $case) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires real PostgreSQL exchange-rate CHECK constraints.');
    }
    [$user, $org] = marketIdentity();
    $data = ['organization_id' => $org->id, 'currency' => 'USD', 'rate' => '100.5', 'source' => 'TEST ONLY', 'source_reference' => 'TEST ONLY',
        'effective_at' => now()->subHour(), 'expires_at' => now()->addHour(), 'submitted_by' => $user->id];
    $data = match ($case) {
        'positive' => [...$data, 'rate' => '0'],
        'interval' => [...$data, 'expires_at' => now()->subDay()],
        'independent' => [...$data, 'approved_by' => $user->id, 'approved_at' => now()],
    };
    expect(fn () => DB::table('exchange_rates')->insert($data))->toThrow(QueryException::class);
})->with(['positive', 'interval', 'independent']);
