<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
});

it('opens the price panel after registration even when verification email cannot be sent', function () {
    config(['mail.default' => 'log']);
    Notification::fake();
    $this->withSession(['organization_id' => 999999, 'url.intended' => '/admin'])->post('/register', [
        'name' => 'New Customer', 'organization' => 'New Workspace', 'email' => 'new-customer@example.test',
        'password' => 'RegistrationPassword123', 'password_confirmation' => 'RegistrationPassword123',
        'is_platform_admin' => true, 'email_verified_at' => now()->toISOString(),
    ])->assertRedirect('/market');
    $user = User::where('email', 'new-customer@example.test')->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    expect($user->is_platform_admin)->toBeFalse();
    $organization = $user->organizations()->sole();
    expect(session('organization_id'))->toBe($organization->id);
    $this->get('/market')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PublicMarket')
        ->where('verification.verified', false)->where('verification.mailAvailable', false)->where('organization.id', $organization->id));
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('lets an unverified customer return to the price panel after signing in', function () {
    $user = User::factory()->unverified()->create(['password' => 'RegistrationPassword123']);
    $organization = Organization::factory()->create();
    $organization->users()->attach($user, ['role' => 'owner']);
    $this->withSession(['organization_id' => 999999, 'url.intended' => '/products'])->post('/login', [
        'email' => $user->email, 'password' => 'RegistrationPassword123',
    ])->assertRedirect('/market');
    $this->get('/market')->assertOk();
    $this->get('/')->assertRedirect('/market');
    $this->get('/login')->assertRedirect('/market');
    $this->get('/register')->assertRedirect('/market');
});

it('keeps private screens behind email verification', function (string $path) {
    $user = User::factory()->unverified()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user)->get($path)->assertRedirect('/verify-email');
})->with(['/dashboard', '/products', '/settings', '/ai', '/admin']);

it('keeps image analysis and AI chat blocked before verification without external requests', function () {
    $user = User::factory()->unverified()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user)->postJson('/market/scan')->assertForbidden();
    $this->postJson('/ai/chat', ['message' => 'Hello'])->assertForbidden();
    Http::assertNothingSent();
});

it('resets a foreign organization selection at login but preserves membership authorization', function () {
    [$user, $organization] = marketIdentity();
    $user->forceFill(['password' => 'RegistrationPassword123'])->save();
    $foreign = Organization::factory()->create();
    $this->withSession(['organization_id' => $foreign->id])->post('/login', [
        'email' => $user->email, 'password' => 'RegistrationPassword123',
    ])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('organization.id', $organization->id));
    $this->post('/organization', ['organization_id' => $foreign->id])->assertNotFound();
});

it('refuses suspended accounts on the price panel', function () {
    [$user] = marketIdentity();
    $user->forceFill(['is_suspended' => true])->save();
    $this->actingAs($user)->get('/market')->assertRedirect('/login');
    $this->assertGuest();
});
