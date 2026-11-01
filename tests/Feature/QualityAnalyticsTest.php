<?php

use App\Models\Offer;
use App\Models\PriceReview;
use App\Models\Supplier;
use App\Services\AnalyticsPrices;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('compares supplier specific latest observations and an explicitly matched cohort', function () {
    $this->freezeTime();
    [$user,$org] = marketIdentity();
    $old = Offer::factory()->create(['organization_id' => $org->id, 'price' => '10', 'observed_at' => now()->subDay()]);
    $new = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $old->product_id, 'supplier_id' => $old->supplier_id, 'price' => '12']);
    $b = Supplier::factory()->create(['organization_id' => $org->id]);
    Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $old->product_id, 'supplier_id' => $b->id, 'price' => '100', 'observed_at' => now()->subDay()]);
    Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $old->product_id, 'supplier_id' => $b->id, 'price' => '90']);
    $single = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $old->product_id, 'price' => '1']);
    $result = app(AnalyticsPrices::class)->product($old->product, $user, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30));
    expect($result['suppliers'][0])->toMatchArray(['latest_id' => $new->id, 'percentage_change' => '20.0000']);
    expect($result['suppliers'][1]['percentage_change'])->toBe('-10.0000');
    expect($result['cohort_supplier_ids'])->toBe([$old->supplier_id, $b->id]);
    expect($result['change_percent'])->toBe('20.0000');
    expect($result['lowest_eligible'])->toBe('1.000000000000');
    expect($result['highest_eligible'])->toBe('90.000000000000');
});

it('does not revive old quotes when newest is expired incompatible rejected or disputed', function (string $state) {
    $this->freezeTime();
    [$user,$org] = marketIdentity();
    $old = Offer::factory()->create(['organization_id' => $org->id, 'price' => '1', 'observed_at' => now()->subDay()]);
    $attrs = ['organization_id' => $org->id, 'product_id' => $old->product_id, 'supplier_id' => $old->supplier_id, 'price' => '9'];
    if ($state === 'expired') {
        $attrs['expires_at'] = now();
        $attrs['observed_at'] = now()->subHour();
    }
    if ($state === 'incompatible') {
        $attrs['unit'] = 'l';
    }
    $new = Offer::factory()->create($attrs);
    if (in_array($state, ['rejected', 'disputed'])) {
        $file = priceEvidence($new, $user);
        PriceReview::create(['organization_id' => $org->id, 'offer_id' => $new->id, 'actor_id' => $user->id, 'scope' => 'organization', 'status' => $state, 'reason' => 'TEST ONLY review', 'evidence_file_id' => $file->id]);
    }
    $result = app(AnalyticsPrices::class)->product($old->product, $user, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30));
    expect($result['lowest_eligible'])->toBeNull();
    expect($result['suppliers'][0]['latest_id'])->toBe($new->id);
    expect($result['status'])->toBe('insufficient_data');
})->with(['expired', 'incompatible', 'rejected', 'disputed']);

it('honors recording cutoff and correction timestamps and denies foreign filter identities', function () {
    $this->freezeTime();
    [$user,$org] = marketIdentity();
    $old = Offer::factory()->create(['organization_id' => $org->id, 'price' => '10']);
    $cutoff = CarbonImmutable::now();
    $this->travel(1)->hours();
    $new = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $old->product_id, 'supplier_id' => $old->supplier_id, 'observed_at' => $old->observed_at, 'price' => '20', 'correction_of_id' => $old->id, 'correction_reason' => 'TEST correction']);
    $result = app(AnalyticsPrices::class)->product($old->product, $user, $cutoff, $cutoff->subDays(30));
    expect($result['suppliers'][0]['latest_id'])->toBe($old->id);
    $result = app(AnalyticsPrices::class)->product($old->product, $user, CarbonImmutable::now(), $cutoff->subDays(30));
    expect($result['suppliers'][0]['latest_id'])->toBe($new->id);
    $foreign = Offer::factory()->create();
    $this->actingAs($user)->get('/analytics?product_id='.$foreign->product_id)->assertNotFound();
    $this->get('/analytics/export?supplier_id='.$foreign->supplier_id)->assertNotFound();
});

it('reports no percentage for a zero legacy baseline and does not divide by zero', function () {
    if (DB::getDriverName() === 'pgsql') {
        $this->markTestSkipped('PostgreSQL forbids zero prices; this exercises legacy SQLite data.');
    }
    $this->freezeTime();
    [$user,$org] = marketIdentity();
    $old = Offer::factory()->create(['organization_id' => $org->id, 'price' => '0', 'observed_at' => now()->subDay()]);
    Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $old->product_id, 'supplier_id' => $old->supplier_id, 'price' => '10']);
    $result = app(AnalyticsPrices::class)->product($old->product, $user, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30));
    expect($result['status'])->toBe('insufficient_data');
    expect($result['suppliers'][0]['percentage_change'])->toBeNull();
});

it('bounds analytics hydration and query counts independently of supplier history length', function () {
    $this->freezeTime();
    [$user,$org] = marketIdentity();
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $rows = [];
    for ($i = 0; $i < 2000; $i++) {
        $rows[] = ['organization_id' => $org->id, 'product_id' => $offer->product_id, 'supplier_id' => $offer->supplier_id, 'price' => '10', 'currency' => 'UZS', 'unit' => 'kg', 'observed_at' => now()->subDay(), 'created_at' => now()->subDay(), 'provenance' => 'manual', 'source_reference' => 'TEST performance', 'fingerprint' => hash('sha256', 'quality-'.$i)];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('offers')->insert($chunk);
    }
    $product = $offer->product;
    DB::enableQueryLog();
    memory_reset_peak_usage();
    $memory = memory_get_usage();
    $start = hrtime(true);
    $result = app(AnalyticsPrices::class)->product($product, $user, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30));
    $elapsed = (hrtime(true) - $start) / 1000000;
    $delta = memory_get_peak_usage() - $memory;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();
    expect($result['suppliers'])->toHaveCount(1);
    expect(count($queries))->toBeLessThanOrEqual(25);
    expect($delta)->toBeLessThan(8 * 1024 * 1024);
    file_put_contents(storage_path('framework/testing/quality-analytics-'.DB::getDriverName().'.json'), json_encode(['observations' => 2001, 'supplier_series' => 1, 'queries' => count($queries), 'db_ms' => array_sum(array_column($queries, 'time')), 'wall_ms' => $elapsed, 'peak_delta_bytes' => $delta], JSON_PRETTY_PRINT));
});
