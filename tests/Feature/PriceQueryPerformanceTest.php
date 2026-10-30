<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Services\CompareOffers;
use App\Services\LatestOffers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('measures real PostgreSQL plans and bounded hydration for twenty thousand observations', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Actual PostgreSQL EXPLAIN ANALYZE is required.');
    }
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $suppliers = Supplier::factory()->count(19)->create(['organization_id' => $org->id])->pluck('id')->push($input['supplier_id'])->all();
    $batch = [];
    for ($index = 0; $index < 20000; $index++) {
        $batch[] = ['organization_id' => $org->id, 'product_id' => $input['product_id'], 'supplier_id' => $suppliers[$index % 20],
            'price' => '100.25', 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '2', 'minimum_packages' => 1, 'delivery_fee' => '5.50',
            'observed_at' => now()->subSeconds($index), 'created_at' => now()->subSeconds($index), 'provenance' => 'manual', 'source_reference' => 'TEST ONLY performance evidence', 'fingerprint' => hash('sha256', 'performance-'.$index)];
        if (count($batch) === 200) {
            DB::table('offers')->insert($batch);
            $batch = [];
        }
    }
    DB::statement('ANALYZE offers');
    $product = Product::findOrFail($input['product_id']);
    $query = app(LatestOffers::class)->query($product, CarbonImmutable::now(), $user)->limit(501);
    $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
    DB::enableQueryLog();
    memory_reset_peak_usage();
    $memory = memory_get_usage();
    $started = hrtime(true);
    $results = app(CompareOffers::class)->compare($product, '20', CarbonImmutable::now(), $user);
    $elapsed = (hrtime(true) - $started) / 1000000;
    $peakDelta = memory_get_peak_usage() - $memory;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();
    expect($results)->toHaveCount(20);
    expect(count($queries))->toBeLessThanOrEqual(12);
    expect($peakDelta)->toBeLessThan(8 * 1024 * 1024);
    $artifact = ['environment' => 'Disposable PostgreSQL 17; one isolated test sample, not production load', 'observations' => 20000, 'suppliers' => 20,
        'hydrated_observations' => count($results), 'query_count' => count($queries), 'db_query_ms' => array_sum(array_column($queries, 'time')),
        'wall_ms' => $elapsed, 'peak_memory_delta_bytes' => $peakDelta, 'sql' => $query->toSql(),
        'plan' => json_decode($plan[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)];
    $directory = storage_path('framework/testing');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($directory.'/phase3-performance.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
});
