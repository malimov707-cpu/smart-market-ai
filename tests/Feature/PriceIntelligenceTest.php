<?php

use App\Models\CatalogFile;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceReview;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierVerification;
use App\Models\User;
use App\Services\CompareOffers;
use App\Services\ExchangeRates;
use App\Services\PriceHistory;
use App\Services\RecordOffer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

function priceEvidence(Offer $offer, User $uploader): CatalogFile
{
    return CatalogFile::create(['organization_id' => $offer->organization_id, 'supplier_id' => $offer->supplier_id,
        'uploaded_by' => $uploader->id, 'name' => 'TEST price evidence.pdf', 'path' => 'TEST-ONLY', 'mime' => 'application/pdf', 'size' => 10]);
}

it('selects newer expensive quotes and deterministic recorded time and id ties without reviving expired prices', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00Z'));
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $first = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '1', 'observed_at' => now()->subHours(2)]);
    $latest = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '900', 'observed_at' => now()->subHour(), 'expires_at' => now()]);
    $tie = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $first->product_id, 'supplier_id' => $first->supplier_id,
        'price' => '950', 'observed_at' => $latest->observed_at, 'expires_at' => now(), 'created_at' => now()]);
    $rows = app(CompareOffers::class)->compare($first->product, '1', CarbonImmutable::now(), $user);
    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray(['id' => $tie->id, 'price' => '950.00', 'expired' => true, 'eligible' => false, 'cheapest_complete' => false]);
});

it('uses both observed and recorded cutoffs across timezone boundaries and append only corrections', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T01:00:00Z'));
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $original = app(RecordOffer::class)->execute($user, $org, [...$input, 'observed_at' => '2026-10-30T05:30:00+05:00']);
    $cutoff = CarbonImmutable::now();
    $this->travel(1)->hours();
    $correction = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '999', 'observed_at' => $original->observed_at,
        'correction_of_id' => $original->id, 'correction_reason' => 'TEST invoice decimal correction']);
    Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $original->product_id, 'supplier_id' => $original->supplier_id, 'observed_at' => now()->addDay()]);
    $engine = app(CompareOffers::class);
    expect($engine->compare($original->product, '1', $cutoff, $user)[0]['id'])->toBe($original->id);
    expect($engine->compare($original->product, '1', CarbonImmutable::now(), $user)[0]['id'])->toBe($correction->id);
    expect($original->fresh()->price)->toBe('10000.00');
    $this->actingAs($user)->get('/products/'.$original->product_id.'/price-history?'.http_build_query(['from' => '2026-10-30T00:30:00.100000Z']))
        ->assertInertia(fn (Assert $page) => $page->has('observations.data', 0));

    $this->actingAs($user)->get('/compare?'.http_build_query(['product_id' => $original->product_id, 'quantity' => '1', 'as_of' => '2026-10-30T06:00:00+05:00']))
        ->assertInertia(fn (Assert $page) => $page->where('results.0.id', $original->id));
});

it('bounds large histories and keeps supplier comparisons tenant isolated', function () {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $offer = app(RecordOffer::class)->execute($user, $org, $input);
    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $rows[] = ['organization_id' => $org->id, 'product_id' => $offer->product_id, 'supplier_id' => $offer->supplier_id,
            'price' => '1', 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '1', 'minimum_packages' => 1,
            'observed_at' => now()->subDays(10), 'created_at' => now()->subDays(10), 'provenance' => 'manual', 'source_reference' => 'TEST history', 'fingerprint' => hash('sha256', (string) $i)];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('offers')->insert($chunk);
    }
    $another = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $offer->product_id]);
    DB::enableQueryLog();
    $results = app(CompareOffers::class)->compare($offer->product, '1', CarbonImmutable::now(), $user);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();
    expect($results)->toHaveCount(2);
    expect(array_column($results, 'id'))->toContain($offer->id, $another->id);
    expect(count($queries))->toBeLessThanOrEqual(12);
    [$stranger] = marketIdentity();
    $this->actingAs($stranger)->get('/products/'.$offer->product_id.'/price-history')->assertNotFound();
    $this->get('/prices/'.$offer->id)->assertNotFound();
});

it('records supported source metadata without independently verifying a supplier price', function (string $source) {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $this->actingAs($user)->post('/offers', [...$input, 'source_type' => $source, 'source_identifier' => 'TEST real source identifier', 'task_reference' => 'TEST external task', 'verification' => 'verified'])->assertSessionHasNoErrors();
    $offer = Offer::sole();
    expect($offer->source_type)->toBe($source);
    expect($offer->submitted_by)->toBe($user->id);
    $offer->supplier->forceFill(['verification_status' => 'verified'])->save();
    expect(app(CompareOffers::class)->compare($offer->product, '1', CarbonImmutable::now(), $user)[0]['verification']['organization'])->toBe('unverified');
})->with(['manual', 'supplier', 'csv', 'excel', 'api', 'ocr']);

it('rejects missing provenance task evidence and foreign correction links', function () {
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $this->actingAs($user)->post('/offers', [...$input, 'source_type' => 'ocr'])->assertSessionHasErrors('source_identifier');
    $foreign = Offer::factory()->create();
    $this->post('/offers', [...$input, 'correction_of_id' => $foreign->id, 'correction_reason' => 'TEST correction reason'])->assertSessionHasErrors('correction_of_id');
    $this->post('/offers', [...$input, 'evidence_file_id' => 999999])->assertSessionHasErrors('evidence_file_id');
});

it('requires independent price reviewer and preserves evidence historical verification dispute and audit', function () {
    $this->freezeTime();
    [$author, $org] = marketIdentity();
    $input = offerInput($org);
    $offer = app(RecordOffer::class)->execute($author, $org, $input);
    $file = priceEvidence($offer, $author);
    $reviewer = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($reviewer, ['role' => 'owner']);
    $data = ['scope' => 'organization', 'status' => 'pending', 'reason' => 'TEST documented price evidence', 'evidence_file_id' => $file->id];
    $this->actingAs($author)->post('/prices/'.$offer->id.'/review', $data)->assertSessionHasNoErrors();
    $beforeReview = CarbonImmutable::now();
    $this->travel(1)->seconds();
    $this->post('/prices/'.$offer->id.'/review', [...$data, 'status' => 'verified'])->assertSessionHasErrors('status');
    $this->actingAs($reviewer)->post('/prices/'.$offer->id.'/review', [...$data, 'status' => 'verified'])->assertSessionHasNoErrors();
    $engine = app(CompareOffers::class);
    expect($engine->compare($offer->product, '1', $beforeReview, $reviewer)[0]['verification']['organization'])->toBe('pending');
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $reviewer)[0]['verification']['organization'])->toBe('verified');
    $this->post('/catalog/files/'.$file->id.'/retire')->assertSessionHasErrors('file');
    $this->post('/prices/'.$offer->id.'/review', [...$data, 'status' => 'disputed'])->assertSessionHasNoErrors();
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $reviewer)[0]['eligible'])->toBeFalse();
    $this->assertDatabaseCount('price_reviews', 3);
    $this->assertDatabaseHas('audit_logs', ['action' => 'price.verified']);
});

it('denies missing evidence unauthorized roles and platform price self grants', function () {
    [$owner, $org] = marketIdentity();
    $offer = app(RecordOffer::class)->execute($owner, $org, offerInput($org));
    $data = ['scope' => 'organization', 'status' => 'pending', 'reason' => 'TEST evidence justification', 'evidence_file_id' => 999999];
    $this->actingAs($owner)->post('/prices/'.$offer->id.'/review', $data)->assertSessionHasErrors('evidence_file_id');
    $file = priceEvidence($offer, $owner);
    $data['evidence_file_id'] = $file->id;
    $this->post('/prices/'.$offer->id.'/review', [...$data, 'scope' => 'platform'])->assertForbidden();
    $auditor = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($auditor, ['role' => 'auditor']);
    $this->actingAs($auditor)->post('/prices/'.$offer->id.'/review', $data)->assertForbidden();
    $this->post('/exchange-rates', [])->assertForbidden();
    $this->get('/prices/'.$offer->id)->assertOk();
});

it('uses independently approved historical exchange rates with exact half up rounding and source attribution', function () {
    $this->freezeTime();
    [$owner, $org] = marketIdentity();
    $input = offerInput($org);
    $offer = app(RecordOffer::class)->execute($owner, $org, [...$input, 'currency' => 'USD', 'price' => '1.01', 'delivery_fee' => '0.00']);
    $reviewer = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($reviewer, ['role' => 'owner']);
    $data = ['currency' => 'USD', 'rate' => '100.5', 'source' => 'TEST ONLY source', 'source_reference' => 'TEST ONLY bulletin', 'effective_at' => now()->subHour()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String()];
    $service = app(ExchangeRates::class);
    $rate = $service->record($owner, $org, $data);
    $cutoff = CarbonImmutable::now();
    $this->actingAs($owner)->post('/exchange-rates/'.$rate->id.'/approve')->assertSessionHasErrors('rate');
    $this->travel(1)->seconds();
    $service->approve($reviewer, $org, $rate->id);
    $engine = app(CompareOffers::class);
    expect($engine->compare($offer->product, '1', $cutoff, $owner)[0]['known_total'])->toBeNull();
    $row = $engine->compare($offer->product, '1', CarbonImmutable::now(), $owner)[0];
    expect($row)->toMatchArray(['known_total' => '101.51', 'eligible' => true]);
    expect($row['exchange_rate']['source_reference'])->toBe('TEST ONLY bulletin');
    $new = $service->record($owner, $org, [...$data, 'rate' => '200']);
    $service->approve($reviewer, $org, $new->id);
    expect($engine->compare($offer->product, '1', $cutoff, $owner)[0]['known_total'])->toBeNull();
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $owner)[0]['known_total'])->toBe('202.00');
});

it('excludes missing expired and foreign tenant exchange rates and validates bad currencies and decimals', function () {
    $this->freezeTime();
    [$owner, $org] = marketIdentity();
    $offer = app(RecordOffer::class)->execute($owner, $org, [...offerInput($org), 'currency' => 'EUR']);
    $engine = app(CompareOffers::class);
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $owner)[0])->toMatchArray(['known_total' => null, 'eligible' => false, 'cheapest_complete' => false]);
    $this->actingAs($owner)->post('/exchange-rates', ['currency' => 'XXX', 'rate' => '-1'])->assertSessionHasErrors(['currency', 'rate']);
    $this->post('/offers', [...offerInput($org), 'currency' => 'XXX'])->assertSessionHasErrors('currency');
    $other = ExchangeRate::create(['organization_id' => Organization::factory()->create()->id, 'currency' => 'EUR', 'rate' => '100', 'source' => 'TEST ONLY', 'source_reference' => 'TEST ONLY', 'effective_at' => now()->subHour(), 'expires_at' => now()->addHour(), 'submitted_by' => $owner->id]);
    $this->post('/exchange-rates/'.$other->id.'/approve')->assertNotFound();
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $owner)[0]['known_total'])->toBeNull();
});

it('distinguishes nominal unit and complete landed cost rankings without claiming unknown delivery cheapest', function () {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $nominal = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '1', 'package_quantity' => '0.001', 'delivery_fee' => '0']);
    $unit = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $nominal->product_id, 'price' => '10', 'package_quantity' => '100', 'delivery_fee' => null]);
    $complete = Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $nominal->product_id, 'price' => '5', 'delivery_fee' => '1']);
    $rows = collect(app(CompareOffers::class)->compare($nominal->product, '1', CarbonImmutable::now(), $user))->keyBy('id');
    expect($rows[$nominal->id]['lowest_nominal'])->toBeTrue();
    expect($rows[$unit->id])->toMatchArray(['lowest_normalized' => true, 'cheapest_complete' => false, 'complete' => false]);
    expect($rows[$complete->id])->toMatchArray(['cheapest_complete' => true, 'known_total' => '6.00']);
});

it('calculates normalized supplier and same cohort product changes and signals insufficient data', function () {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $old = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '5', 'package_quantity' => '0.5', 'observed_at' => now()->subDay()]);
    app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '12', 'package_quantity' => '1']);
    $changes = app(PriceHistory::class)->changes($old->product, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30), $user);
    expect($changes['suppliers'][0])->toMatchArray(['absolute_change' => '2.000000000000', 'percentage_change' => '20.0000', 'status' => 'available']);
    expect($changes['product'])->toMatchArray(['cohort_suppliers' => 1, 'absolute_change' => '2.000000000000']);
    $single = Offer::factory()->create(['organization_id' => $org->id]);
    expect(app(PriceHistory::class)->changes($single->product, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30), $user)['product']['status'])->toBe('insufficient_data');
    $this->actingAs($user)->get('/products/'.$old->product_id.'/price-history')->assertInertia(fn (Assert $page) => $page->component('PriceHistory')->has('observations.data', 2));
    $this->get('/products/'.$old->product_id.'/price-history?as_of=2099-01-01')->assertSessionHasErrors('as_of');
    $this->get('/compare?product_id='.$old->product_id.'&quantity=1&as_of=bad')->assertSessionHasErrors('as_of');
});

it('does not let a later write in the same second change an earlier as of result', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T10:00:00.100000Z'));
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $old = app(RecordOffer::class)->execute($user, $org, $input);
    $asOf = CarbonImmutable::now();
    $this->travelTo(CarbonImmutable::parse('2026-10-30T10:00:00.900000Z'));
    $new = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '20000']);
    $engine = app(CompareOffers::class);
    expect($engine->compare($old->product, '1', $asOf, $user)[0]['id'])->toBe($old->id);
    expect($engine->compare($old->product, '1', CarbonImmutable::now(), $user)[0]['id'])->toBe($new->id);
});

it('keeps historical supplier availability after later suspension archive and restore', function () {
    $this->freezeTime();
    [$owner, $org] = marketIdentity();
    $offer = app(RecordOffer::class)->execute($owner, $org, offerInput($org));
    $asOf = CarbonImmutable::now();
    $this->travel(1)->hours();
    $this->actingAs($owner)->post('/suppliers/'.$offer->supplier_id.'/archive')->assertSessionHasNoErrors();
    $engine = app(CompareOffers::class);
    expect($engine->compare($offer->product, '1', $asOf, $owner)[0]['eligible'])->toBeTrue();
    $archived = CarbonImmutable::now();
    expect($engine->compare($offer->product, '1', $archived, $owner)[0]['eligible'])->toBeFalse();
    $this->travel(1)->hours();
    $this->post('/suppliers/'.$offer->supplier_id.'/restore')->assertSessionHasNoErrors();
    expect($engine->compare($offer->product, '1', $archived, $owner)[0]['eligible'])->toBeFalse();
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $owner)[0]['eligible'])->toBeTrue();
    SupplierVerification::create(['organization_id' => $org->id, 'supplier_id' => $offer->supplier_id, 'actor_id' => $owner->id, 'scope' => 'organization', 'from_status' => 'verified', 'to_status' => 'suspended', 'reason' => 'TEST ONLY historical suspension', 'evidence_ids' => []]);
    expect($engine->compare($offer->product, '1', $asOf, $owner)[0]['eligible'])->toBeTrue();
    expect($engine->compare($offer->product, '1', CarbonImmutable::now(), $owner)[0]['eligible'])->toBeFalse();
});

it('never revives an older rate after the newest approved rate expires', function () {
    $this->freezeTime();
    [$owner, $org] = marketIdentity();
    $reviewer = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($reviewer, ['role' => 'owner']);
    $service = app(ExchangeRates::class);
    $data = ['currency' => 'USD', 'rate' => '0.000000000001', 'source' => 'TEST ONLY', 'source_reference' => 'TEST ONLY', 'effective_at' => now()->subHours(2), 'expires_at' => now()->addDays(2)];
    $first = $service->record($owner, $org, $data);
    $service->approve($reviewer, $org, $first->id);
    expect($service->convert('9999999999999999.99', 'USD', $service->at($org->id, CarbonImmutable::now()), CarbonImmutable::now()))->toBe('10000.00');
    $second = $service->record($owner, $org, [...$data, 'rate' => '100', 'effective_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
    $service->approve($reviewer, $org, $second->id);
    $this->travel(1)->hours();
    expect($service->convert('1', 'USD', $service->at($org->id, CarbonImmutable::now()), CarbonImmutable::now()))->toBeNull();
    $this->actingAs($owner)->post('/exchange-rates', [...$data, 'rate' => '1e5'])->assertSessionHasErrors('rate');
});

it('enforces price platform permission independently from supplier grants', function () {
    [$author, $org] = marketIdentity();
    $offer = app(RecordOffer::class)->execute($author, $org, offerInput($org));
    $reviewer = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($reviewer, ['role' => 'owner']);
    DB::table('platform_permissions')->insert(['user_id' => $reviewer->id, 'permission' => 'supplier.verify']);
    $file = priceEvidence($offer, $author);
    $data = ['scope' => 'platform', 'status' => 'pending', 'evidence_file_id' => $file->id, 'reason' => 'TEST ONLY platform price evidence'];
    $this->actingAs($reviewer)->post('/prices/'.$offer->id.'/review', $data)->assertForbidden();
    DB::table('platform_permissions')->insert(['user_id' => $author->id, 'permission' => 'price.verify']);
    DB::table('platform_permissions')->insert(['user_id' => $reviewer->id, 'permission' => 'price.verify']);
    $this->actingAs($author)->post('/prices/'.$offer->id.'/review', $data)->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post('/prices/'.$offer->id.'/review', [...$data, 'status' => 'verified'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('price_reviews', ['scope' => 'platform', 'status' => 'verified', 'actor_id' => $reviewer->id]);
});

it('chooses the previous valid compatible observation rather than a rejected intermediate quote', function () {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $old = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '10', 'observed_at' => now()->subDays(2)]);
    $bad = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '2', 'observed_at' => now()->subDay()]);
    $evidence = priceEvidence($bad, $user);
    PriceReview::create(['organization_id' => $org->id, 'offer_id' => $bad->id, 'actor_id' => $user->id, 'scope' => 'organization', 'status' => 'rejected', 'reason' => 'TEST ONLY invalid observation', 'evidence_file_id' => $evidence->id]);
    app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '12']);
    $result = app(PriceHistory::class)->changes($old->product, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30), $user);
    expect($result['suppliers'][0])->toMatchArray(['previous_id' => $old->id, 'absolute_change' => '2.000000000000', 'percentage_change' => '20.0000']);
    $rejected = app(RecordOffer::class)->execute($user, $org, [...$input, 'price' => '13', 'expires_at' => now()->addHour()]);
    PriceReview::create(['organization_id' => $org->id, 'offer_id' => $rejected->id, 'actor_id' => $user->id, 'scope' => 'organization', 'status' => 'rejected', 'reason' => 'TEST ONLY rejected before expiry', 'evidence_file_id' => $evidence->id]);
    $this->travel(2)->hours();
    expect(app(PriceHistory::class)->changes($old->product, CarbonImmutable::now(), CarbonImmutable::now()->subDays(30), $user)['suppliers'][0]['status'])->toBe('insufficient_data');

});

it('fails closed instead of labelling a truncated supplier set cheapest', function () {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $product = Product::factory()->create(['organization_id' => $org->id]);
    $suppliers = Supplier::factory()->count(501)->create(['organization_id' => $org->id]);
    $rows = $suppliers->map(fn ($supplier) => ['organization_id' => $org->id, 'supplier_id' => $supplier->id, 'product_id' => $product->id,
        'price' => '1', 'unit' => 'kg', 'currency' => 'UZS', 'observed_at' => now(), 'created_at' => now(), 'provenance' => 'manual', 'source_reference' => 'TEST ONLY', 'fingerprint' => hash('sha256', (string) $supplier->id)])->all();
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('offers')->insert($chunk);
    }
    $this->actingAs($user)->get('/compare?product_id='.$product->id.'&quantity=1')->assertSessionHasErrors('product_id');
});

it('keeps old content deduplication across source defaults and different submitting users', function () {
    $this->freezeTime();
    [$user, $org] = marketIdentity();
    $input = offerInput($org);
    $first = app(RecordOffer::class)->execute($user, $org, $input);
    $other = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($other, ['role' => 'buyer']);
    $same = app(RecordOffer::class)->execute($other, $org, [...$input, 'source_type' => 'manual', 'source_identifier' => null, 'task_reference' => null, 'correction_reason' => null]);
    expect($same->id)->toBe($first->id);
    expect($same->submitted_by)->toBe($user->id);
    $this->assertDatabaseCount('offers', 1);
});

it('rounds normalized prices after multiplication and division without losing large rate precision', function () {
    $rate = new ExchangeRate(['currency' => 'USD', 'rate' => '9999999999999999.000000000001', 'expires_at' => now()->addDay()]);
    $service = app(ExchangeRates::class);
    expect($service->unitPrice('1.00', '3.000000', 'USD', ['USD' => $rate], CarbonImmutable::now()))->toBe('3333333333333333.000000000000');
    expect($service->unitPrice('1.00', '3.000000', 'UZS', [], CarbonImmutable::now()))->toBe('0.333333333333');
    expect($service->unitPrice('1.00', '6.000000', 'UZS', [], CarbonImmutable::now()))->toBe('0.166666666667');
});

it('enforces read and submission permissions across every organization role', function (string $role, bool $canSubmit) {
    [$actor, $org] = marketIdentity($role);
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $file = priceEvidence($offer, $actor);
    $this->actingAs($actor)->get('/prices/'.$offer->id)->assertOk();
    $this->get('/products/'.$offer->product_id.'/price-history')->assertOk();
    $this->get('/exchange-rates')->assertOk();
    $response = $this->post('/prices/'.$offer->id.'/review', ['scope' => 'organization', 'status' => 'pending', 'reason' => 'TEST ONLY documented price submission', 'evidence_file_id' => $file->id]);
    if ($canSubmit) {
        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('price_reviews', ['offer_id' => $offer->id, 'actor_id' => $actor->id, 'status' => 'pending']);
    } else {
        $response->assertForbidden();
        $this->assertDatabaseCount('price_reviews', 0);
    }
})->with([['owner', true], ['manager', true], ['buyer', true], ['analyst', false], ['auditor', false]]);

it('rejects cross tenant price evidence and history supplier references and missing correction reasons', function () {
    [$actor, $org] = marketIdentity();
    $offer = app(RecordOffer::class)->execute($actor, $org, offerInput($org));
    $foreign = Offer::factory()->create();
    $file = priceEvidence($foreign, $actor);
    $this->actingAs($actor)->post('/prices/'.$offer->id.'/review', ['scope' => 'organization', 'status' => 'pending', 'reason' => 'TEST ONLY foreign evidence denied', 'evidence_file_id' => $file->id])->assertSessionHasErrors('evidence_file_id');
    $this->get('/products/'.$offer->product_id.'/price-history?supplier_id='.$foreign->supplier_id)->assertNotFound();
    $this->post('/offers', [...offerInput($org), 'correction_of_id' => $offer->id])->assertSessionHasErrors('correction_reason');
});

it('requires authentication on all price intelligence endpoints', function () {
    foreach (['/prices/1', '/products/1/price-history', '/exchange-rates'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
    foreach (['/prices/1/review', '/exchange-rates', '/exchange-rates/1/approve'] as $path) {
        $this->post($path)->assertRedirect('/login');
    }
});
