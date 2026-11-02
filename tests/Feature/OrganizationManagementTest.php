<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('shows only the user role in the consumer panel and preserves legacy organization records', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/market')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PublicMarket')->where('auth.is_platform_admin', false)
        ->missing('organization')->missing('organizations'));
    $this->assertModelExists($organization);
});

it('shows only the admin role in platform administration', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin')->where('auth.is_platform_admin', true)
        ->where('filters.tab', 'overview')->missing('organizations'));
});

it('does not expose organization membership or invitation endpoints', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/settings')->assertNotFound();
    $this->post('/settings', ['name' => 'Legacy', 'city' => 'Toshkent'])->assertNotFound();
    $this->post('/organization', ['organization_id' => 1])->assertNotFound();
    $this->get('/invitations/legacy-token')->assertNotFound();
    $this->post('/invitations/legacy-token/accept')->assertNotFound();
});
