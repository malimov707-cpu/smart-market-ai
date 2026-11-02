<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        'name' => 'New Customer', 'email' => 'new-customer@example.test',
        'password' => 'RegistrationPassword123', 'password_confirmation' => 'RegistrationPassword123',
        'is_platform_admin' => true, 'email_verified_at' => now()->toISOString(),
    ])->assertRedirect('/market');
    $user = User::where('email', 'new-customer@example.test')->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    expect($user->is_platform_admin)->toBeFalse();
    expect($user->organizations()->exists())->toBeFalse();
    expect(session('organization_id'))->toBeNull();
    $this->get('/market')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PublicMarket')
        ->where('verification.verified', false)->where('verification.mailAvailable', false)
        ->missing('organization')->missing('organizations'));
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
})->with(['/dashboard', '/products', '/ai', '/admin']);

it('keeps image analysis and AI chat blocked for unverified users without external requests', function () {
    $user = User::factory()->unverified()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user)->postJson('/market/scan')->assertForbidden();
    $this->postJson('/ai/chat', ['message' => 'Hello'])->assertForbidden();
    Http::assertNothingSent();
});

it('allows unverified platform admins to access admin pages and image scanning', function () {
    $admin = User::factory()->unverified()->create(['is_platform_admin' => true, 'password' => 'AdminPassword123456']);
    config(['services.openrouter.key' => '']);

    $this->post('/login', ['email' => $admin->email, 'password' => 'AdminPassword123456'])->assertRedirect('/admin');
    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->get('/market')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PublicMarket')
        ->where('verification.verified', false)->where('verification.required', false)->where('verification.canUse', true));
    $this->postJson('/market/scan', ['image' => UploadedFile::fake()->image('product.jpg')])
        ->assertStatus(503)->assertJsonPath('message', 'OpenRouter API kaliti hali sozlanmagan. Administrator serverga kalitni qo‘shishi kerak.');
    Http::assertNothingSent();
});

it('clears a legacy organization selection at login', function () {
    [$user, $organization] = marketIdentity();
    $user->forceFill(['password' => 'RegistrationPassword123'])->save();
    $foreign = Organization::factory()->create();
    $this->withSession(['organization_id' => $foreign->id])->post('/login', [
        'email' => $user->email, 'password' => 'RegistrationPassword123',
    ])->assertRedirect('/admin');
    expect(session('organization_id'))->toBeNull();
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('organization')->missing('organizations'));
    $this->post('/organization', ['organization_id' => $foreign->id])->assertNotFound();
});

it('refuses suspended accounts on the price panel', function () {
    [$user] = marketIdentity();
    $user->forceFill(['is_suspended' => true])->save();
    $this->actingAs($user)->get('/market')->assertRedirect('/login');
    $this->assertGuest();
});

it('opens the verified consumer panel without any organization', function () {
    $user = User::factory()->create(['password' => 'RegistrationPassword123']);
    $this->withSession(['url.intended' => '/admin'])->post('/login', ['email' => $user->email, 'password' => 'RegistrationPassword123'])->assertRedirect('/market');
    $this->get('/market')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PublicMarket')->where('auth.is_platform_admin', false)->missing('organization')->missing('organizations'));
    $this->get('/account')->assertOk();
    $this->postJson('/market/scan')->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->get('/')->assertRedirect('/market');
    $this->get('/login')->assertRedirect('/market');
});

it('bootstraps a hidden storage partition for a platform admin on an empty database', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();

    expect(Organization::query()->count())->toBe(1);
    expect(Organization::query()->sole()->name)->toBe('Platform storage');
});

it('refuses management access even for a consumer with a legacy owner membership', function (string $path) {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user)->get($path)->assertForbidden();
    Http::assertNothingSent();
})->with(['/admin', '/dashboard', '/products', '/suppliers', '/import-center', '/analytics', '/notifications', '/ai']);

it('refuses management mutations from an ordinary user', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/products', ['name' => 'Unauthorized'])->assertForbidden();
    $this->post('/admin/users/'.$user->id, ['action' => 'restore'])->assertForbidden();
    $this->assertDatabaseCount('products', 0);
});
