<?php

use App\Jobs\CommitImport;
use App\Jobs\PrepareImport;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompareOffers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('redirects unauthenticated users from every protected screen', function (string $page) {
    $this->get('/'.$page)->assertRedirect('/login');
})->with(['dashboard', 'products', 'suppliers', 'offers', 'comparison', 'imports', 'ai', 'imports/template']);

it('registers an ordinary user without creating an organization or accepting a supplied role', function () {
    $this->post('/register', [
        'name' => 'Test Owner', 'organization' => 'Test Business', 'email' => 'owner@example.test',
        'password' => 'strongPassword123', 'password_confirmation' => 'strongPassword123', 'role' => 'platform_admin',
    ])->assertRedirect('/market');
    $this->assertAuthenticated();
    $this->assertDatabaseCount('organization_user', 0);
    $this->assertDatabaseCount('organizations', 0);
    expect(User::where('email', 'owner@example.test')->sole()->is_platform_admin)->toBeFalse();
});

it('refuses a weak password and stores no organization', function () {
    $this->post('/register', ['name' => 'Owner', 'organization' => 'Test', 'email' => 'test@example.test',
        'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->assertDatabaseCount('organizations', 0);
});

it('logs in and logs out with invalid credentials rejected', function () {
    $user = User::factory()->create(['password' => 'Password123456']);
    $this->post('/login', ['email' => $user->email, 'password' => 'bad'])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post('/login', ['email' => $user->email, 'password' => 'Password123456'])->assertRedirect('/market');
    $this->assertAuthenticatedAs($user);
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('rate limits repeated login attempts', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->post('/login', ['email' => 'absent@example.test', 'password' => 'wrong']);
    }
    $this->post('/login', ['email' => 'absent@example.test', 'password' => 'wrong'])->assertTooManyRequests();
});

it('prefers FayzTools as the default organization when present', function () {
    $fayz = Organization::factory()->create(['name' => 'FayzTools']);
    $other = Organization::factory()->create(['name' => 'Other']);

    expect(Organization::preferred()?->id)->toBe($fayz->id)
        ->and(Organization::allIds()->first()->id)->toBe($fayz->id)
        ->and(Organization::allIds()->pluck('id')->all())->toContain($other->id);
});

it('lists products from every legacy organization for platform administrators', function () {
    [$user, $organization] = marketIdentity();
    $first = Product::factory()->create(['organization_id' => $organization->id, 'name' => 'A Product']);
    $second = Product::factory()->create(['name' => 'B Product']);
    $this->actingAs($user)->get('/products')->assertInertia(fn (Assert $page) => $page
        ->component('Workspace')->has('products.data', 2)->where('products.data.0.id', min($first->id, $second->id)));
});

it('allows a platform administrator without organization membership to open the global catalog', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $organizations = Organization::factory()->count(2)->create();
    Product::factory()->create(['organization_id' => $organizations[0]->id]);
    $second = Product::factory()->create(['organization_id' => $organizations[1]->id]);

    $this->actingAs($admin)->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Workspace')->has('products.data', 2));
    $this->get('/products/'.$second->id)->assertOk();
});

it('does not allow legacy organization membership to switch the active workspace', function () {
    [$user, $organization] = marketIdentity();
    $other = Organization::factory()->create();
    $other->users()->attach($user, ['role' => 'auditor']);
    $this->actingAs($user)->post('/organization', ['organization_id' => $other->id])->assertNotFound();
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('organization')->missing('organizations'));
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('organization_user', ['organization_id' => $other->id, 'user_id' => $user->id]);
});

it('allows management only to platform administrators regardless of legacy memberships', function (bool $isPlatformAdmin) {
    [$user, $organization] = marketIdentity('owner', isPlatformAdmin: $isPlatformAdmin);
    expect($user->can('manage', $organization))->toBe($isPlatformAdmin);
    expect($user->can('catalog', $organization))->toBe($isPlatformAdmin);
    expect($user->can('administer', $organization))->toBe($isPlatformAdmin);
    expect(User::factory()->create()->can('manage', $organization))->toBeFalse();
})->with([true, false]);

it('prevents a consumer with a legacy owner membership from writing records and importing', function () {
    [$user] = marketIdentity('owner', isPlatformAdmin: false);
    $this->actingAs($user)->post('/products', ['name' => 'Hidden'])->assertForbidden();
    $this->post('/suppliers', ['name' => 'Hidden'])->assertForbidden();
    $this->post('/offers', [])->assertForbidden();
    $this->post('/imports', [])->assertForbidden();
    $this->assertDatabaseCount('products', 0);
});

it('creates products with the server tenant and records an audit trail', function () {
    [$user, $organization] = marketIdentity();
    $this->actingAs($user)->post('/products', ['name' => 'Test Flour Grade A', 'sku' => 'TEST-1',
        'unit' => 'kg', 'organization_id' => 999, 'archived_at' => now()])->assertRedirect();
    $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'sku' => 'TEST-1', 'archived_at' => null]);
    $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'product.created']);
    $this->post('/products', ['name' => 'Duplicate', 'sku' => 'TEST-1', 'unit' => 'kg'])->assertSessionHasErrors('sku');
});

it('creates suppliers without accepting verification claims', function () {
    [$user, $organization] = marketIdentity();
    $this->actingAs($user)->post('/suppliers', ['name' => 'Real Supplier', 'city' => 'Toshkent', 'verified' => true])->assertRedirect();
    $this->assertDatabaseHas('suppliers', ['organization_id' => $organization->id, 'name' => 'Real Supplier']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.created']);
});

it('rejects cross-tenant offer references', function (string $field) {
    [$user, $organization] = marketIdentity();
    $input = offerInput($organization);
    $input[$field] = $field === 'product_id' ? Product::factory()->create()->id : Supplier::factory()->create()->id;
    $this->actingAs($user)->post('/offers', $input)->assertSessionHasErrors($field);
    $this->assertDatabaseCount('offers', 0);
})->with(['product_id', 'supplier_id']);

it('archives products across legacy organization partitions', function () {
    [$user, $organization] = marketIdentity();
    $own = Product::factory()->create(['organization_id' => $organization->id]);
    $other = Product::factory()->create();
    $this->actingAs($user)->post('/products/'.$other->id.'/archive')->assertRedirect();
    expect($other->fresh()->archived_at)->not->toBeNull();
    $this->post('/products/'.$own->id.'/archive')->assertRedirect();
    expect($own->fresh()->archived_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.archived']);
});

it('records offers idempotently with explicit provenance', function () {
    $this->freezeTime();
    [$user, $organization] = marketIdentity();
    $input = offerInput($organization);
    $this->actingAs($user)->post('/offers', [...$input, 'provenance' => 'verified'])->assertRedirect();
    $this->post('/offers', $input)->assertRedirect();
    $this->assertDatabaseCount('offers', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('offers', ['provenance' => 'manual', 'price' => '10000.00']);
});

it('validates invalid offer boundaries', function (string $field, mixed $value) {
    $this->freezeTime();
    [$user, $organization] = marketIdentity();
    $input = offerInput($organization);
    $input[$field] = $value;
    $this->actingAs($user)->post('/offers', $input)->assertSessionHasErrors($field);
    $this->assertDatabaseCount('offers', 0);
})->with([
    ['price', '0'], ['price', '1.001'], ['package_quantity', '0'], ['minimum_packages', 0],
    ['currency', 'XXX'], ['observed_at', '2099-01-01'], ['expires_at', '2001-01-01'], ['source_reference', ''],
]);

it('calculates grams packages and minimum orders with exact money', function () {
    $this->freezeTime();
    $offer = Offer::factory()->create(['unit' => 'g', 'package_quantity' => '250', 'price' => '123.45', 'minimum_packages' => 5, 'delivery_fee' => '100.01']);
    $rows = app(CompareOffers::class)->compare($offer->product, '1.001', CarbonImmutable::now(), comparisonUser($offer));
    expect($rows[0])->toMatchArray(['packages' => '5', 'supplied_quantity' => '1.250000', 'subtotal' => '617.25', 'known_total' => '717.26', 'eligible' => true]);
});

it('keeps large decimal values precise', function () {
    $this->freezeTime();
    $offer = Offer::factory()->create(['price' => '123456789.01', 'delivery_fee' => '0.01']);
    $rows = app(CompareOffers::class)->compare($offer->product, '1000000', CarbonImmutable::now(), comparisonUser($offer));
    expect($rows[0]['known_total'])->toBe('123456789010000.01');
});

it('ranks complete current costs ahead of missing delivery stale and incompatible records', function () {
    $this->freezeTime();
    $current = Offer::factory()->create(['price' => '30.00']);
    $attrs = ['organization_id' => $current->organization_id, 'product_id' => $current->product_id];
    $unknown = Offer::factory()->create([...$attrs, 'price' => '1.00', 'delivery_fee' => null]);
    $old = Offer::factory()->create([...$attrs, 'price' => '0.50', 'observed_at' => now()->subDays(8)]);
    $incompatible = Offer::factory()->create([...$attrs, 'unit' => 'l']);
    $expired = Offer::factory()->create([...$attrs, 'observed_at' => now()->subHour(), 'expires_at' => now()]);
    $rows = collect(app(CompareOffers::class)->compare($current->product, '1', CarbonImmutable::now(), comparisonUser($current)))->keyBy('id');
    expect($rows->keys()->take(2)->all())->toBe([$current->id, $unknown->id]);
    expect($rows[$unknown->id])->toMatchArray(['delivery_known' => false, 'eligible' => true, 'known_total' => '1.00']);
    expect($rows[$old->id]['eligible'])->toBeFalse();
    expect($rows[$incompatible->id]['known_total'])->toBeNull();
    expect($rows[$expired->id]['eligible'])->toBeFalse();
});

it('allows global administrators to compare products from any legacy organization', function () {
    [$user] = marketIdentity();
    $other = Product::factory()->create();
    $this->actingAs($user)->get('/compare?product_id='.$other->id.'&quantity=20')->assertOk();
    $this->get('/compare?product_id='.$other->id.'&quantity=0')->assertSessionHasErrors('quantity');
});

it('imports valid CSV idempotently with audit records', function () {
    $this->freezeTime();
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class, CommitImport::class]);
    [$user, $organization] = marketIdentity();
    $input = offerInput($organization);
    $content = implode(',', array_keys($input))."\n".implode(',', $input)."\n";
    $this->actingAs($user)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('offers.csv', $content)])->assertSessionHasNoErrors()->assertRedirect();
    $this->assertDatabaseCount('offers', 0);
    $batch = prepareManaged(ImportBatch::sole());
    $this->post('/import-center/'.$batch->id.'/confirm', ['revision' => $batch->revision, 'confirm' => 1])->assertSessionHasNoErrors();
    commitManaged($batch);
    $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('offers.csv', $content)])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('offers', 1);
    expect(AuditLog::where('action', 'offer.recorded')->count())->toBe(1);
    $this->assertDatabaseHas('offers', ['provenance' => 'csv']);
});

it('rejects an entire CSV when a later row fails validation', function () {
    $this->freezeTime();
    Storage::fake('catalog');
    Bus::fake([PrepareImport::class]);
    [$user, $organization] = marketIdentity();
    $input = offerInput($organization);
    $content = implode(',', array_keys($input))."\n".implode(',', $input)."\n".implode(',', [...$input, 'price' => '-3'])."\n";
    $this->actingAs($user)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('offers.csv', $content)])->assertSessionHasNoErrors();
    $batch = prepareManaged(ImportBatch::sole());
    expect($batch->error_count)->toBe(1);
    $this->post('/import-center/'.$batch->id.'/confirm', ['revision' => $batch->revision, 'confirm' => 1])->assertSessionHasErrors('import');
    $this->assertDatabaseCount('offers', 0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.recorded']);
});

it('compares twenty real database products through authenticated browser routes', function () {
    $this->freezeTime();
    [$user, $organization] = marketIdentity();
    $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
    $this->actingAs($user);
    foreach (range(1, 20) as $index) {
        $this->post('/products', ['name' => 'Test product '.$index, 'sku' => 'TEST-BATCH-'.$index, 'unit' => 'kg'])->assertSessionHasNoErrors();
        $product = Product::where('organization_id', $organization->id)->where('sku', 'TEST-BATCH-'.$index)->sole();
        $this->post('/offers', ['product_id' => $product->id, 'supplier_id' => $supplier->id, 'price' => '100.25',
            'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '2.000', 'minimum_packages' => 1,
            'delivery_fee' => '5.50', 'observed_at' => now()->toIso8601String(), 'source_reference' => 'TEST-BATCH-EVIDENCE'])->assertSessionHasNoErrors();
        $this->get('/compare?product_id='.$product->id.'&quantity=20')->assertInertia(fn (Assert $page) => $page
            ->component('Comparison')->has('results', 1)->where('results.0.packages', '10')
            ->where('results.0.known_total', '1008.00')->where('results.0.eligible', true));
    }
    $this->assertDatabaseCount('products', 20);
    $this->assertDatabaseCount('offers', 20);
    $this->assertDatabaseCount('audit_logs', 40);
    $this->get('/ai')->assertInertia(fn (Assert $page) => $page->where('section', 'ai'));
});

it('buys the minimum order even when the request needs only one package', function () {
    $this->freezeTime();
    $offer = Offer::factory()->create(['minimum_packages' => 10, 'price' => '0.01', 'delivery_fee' => '0.01']);
    $rows = app(CompareOffers::class)->compare($offer->product, '0.001', CarbonImmutable::now(), comparisonUser($offer));
    expect($rows[0])->toMatchArray(['packages' => '10', 'supplied_quantity' => '10.000000', 'known_total' => '0.11']);
});
