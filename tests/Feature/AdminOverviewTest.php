<?php

use App\Models\CatalogFile;
use App\Models\Offer;
use App\Models\PriceReview;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('shows platform counts, measured activity and explicit unknown health states', function () {
    $this->travelTo('2026-11-03 12:00:00');
    [$owner, $organization] = marketIdentity('owner', false);
    $admin = User::factory()->create(['is_platform_admin' => true]);
    config()->set('services.asaxiy.catalog_enabled', false);
    Cache::put('public-market:korzinka:tashkent:v1', [
        'checked_at' => now()->subHour()->toISOString(),
        'products' => [],
    ]);
    Cache::put('public-market:construction:tezqur:v1', [
        'checked_at' => now()->subHour()->toISOString(),
        'products' => [],
    ]);
    Cache::put('public-market:construction:tezqur:v1:error', now()->toISOString());
    $supplier = Supplier::factory()->create([
        'organization_id' => $organization->id,
        'platform_verification_status' => 'pending',
    ]);
    $offer = Offer::factory()->create([
        'organization_id' => $organization->id,
        'supplier_id' => $supplier->id,
    ]);

    DB::table('audit_logs')->insert([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'action' => 'TEST admin overview activity',
        'subject_type' => 'TEST',
        'subject_id' => $offer->id,
        'created_at' => now(),
    ]);

    $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin')
        ->where('stats.users', 2)
        ->where('stats.organizations', 1)
        ->where('stats.active_organizations_30d', 1)
        ->where('stats.products', 1)
        ->where('stats.suppliers', 1)
        ->where('stats.pending_supplier_verifications', 1)
        ->where('stats.offers', 1)
        ->has('overviewData.trends.days', 14)
        ->where('overviewData.trends.series.users.13', 2)
        ->where('overviewData.trends.series.products.13', 1)
        ->where('overviewData.health.scheduler.status', 'unknown')
        ->where('overviewData.health.notifications.status', 'unavailable')
        ->where('overviewData.health.sources.0.status', 'cached')
        ->where('overviewData.health.sources.2.status', 'disabled')
        ->where('overviewData.health.sources.3.status', 'failed')
        ->where('overviewData.activity.0.action', 'TEST admin overview activity'));
});

it('counts only the latest unexpired platform review for each offer', function () {
    $this->travelTo('2026-11-03 12:00:00');
    [$owner, $organization] = marketIdentity('owner', false);
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $addReview = function (Offer $offer, string $scope, string $status, string $createdAt) use ($owner): void {
        $evidence = CatalogFile::create([
            'organization_id' => $offer->organization_id,
            'supplier_id' => $offer->supplier_id,
            'uploaded_by' => $owner->id,
            'name' => 'TEST price review evidence',
            'path' => 'TEST-ONLY',
            'mime' => 'application/pdf',
            'size' => 10,
        ]);

        PriceReview::create([
            'organization_id' => $offer->organization_id,
            'offer_id' => $offer->id,
            'actor_id' => $owner->id,
            'scope' => $scope,
            'status' => $status,
            'reason' => 'TEST admin overview review',
            'evidence_file_id' => $evidence->id,
            'created_at' => $createdAt,
        ]);
    };

    $latestPending = Offer::factory()->create(['organization_id' => $organization->id]);
    $addReview($latestPending, 'platform', 'verified', now()->subMinutes(2)->toDateTimeString());
    $addReview($latestPending, 'platform', 'pending', now()->subMinute()->toDateTimeString());

    $latestVerified = Offer::factory()->create(['organization_id' => $organization->id]);
    $addReview($latestVerified, 'platform', 'pending', now()->subMinutes(2)->toDateTimeString());
    $addReview($latestVerified, 'platform', 'verified', now()->subMinute()->toDateTimeString());

    $expired = Offer::factory()->create([
        'organization_id' => $organization->id,
        'expires_at' => now()->subSecond(),
    ]);
    $addReview($expired, 'platform', 'pending', now()->toDateTimeString());

    $organizationReview = Offer::factory()->create(['organization_id' => $organization->id]);
    $addReview($organizationReview, 'organization', 'pending', now()->toDateTimeString());

    $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stats.pending_price_reviews', 1));
});
