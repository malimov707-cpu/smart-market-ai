<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('requires authentication and platform access on every admin endpoint', function () {
    $target = User::factory()->create();
    $this->get('/admin')->assertRedirect('/login');
    $this->post('/admin/users/'.$target->id, ['action' => 'suspend'])->assertRedirect('/login');
    [$owner] = marketIdentity(isPlatformAdmin: false);
    $this->actingAs($owner)->get('/admin')->assertForbidden();
    $this->post('/admin/users/'.$target->id, ['action' => 'suspend'])->assertForbidden();
    expect($target->fresh()->is_suspended)->toBeFalse();
});

it('renders global administration without organization data and filters users', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    [$owner] = marketIdentity(isPlatformAdmin: false);
    $this->actingAs($admin)->get('/admin?q='.urlencode($owner->email))
        ->assertInertia(fn (Assert $page) => $page->component('Admin')->where('stats.users', 2)
            ->has('users.data', 1)->where('users.data.0.email', $owner->email)
            ->missing('users.data.0.password')->missing('users.data.0.remember_token')->missing('organizations'));
});

it('suspends a user revokes sessions and records the administrator and target', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $target = User::factory()->create();
    $this->actingAs($admin)->post('/admin/users/'.$target->id, ['action' => 'suspend', 'is_platform_admin' => true])->assertSessionHasNoErrors();
    expect($target->fresh()->is_suspended)->toBeTrue();
    expect($target->fresh()->is_platform_admin)->toBeFalse();
    expect($target->fresh()->security_version)->toBe(1);
    $this->assertDatabaseHas('security_events', ['user_id' => $admin->id, 'action' => 'admin.suspend.user.'.$target->id]);
    $this->assertDatabaseHas('security_events', ['user_id' => $target->id, 'action' => 'admin.suspend.by.'.$admin->id]);
});

it('refuses suspended users on login reactivation and existing sessions', function () {
    $target = User::factory()->create(['is_suspended' => true, 'password' => 'TestPassword123456']);
    $this->post('/login', ['email' => $target->email, 'password' => 'TestPassword123456'])->assertSessionHasErrors('email');
    $target->forceFill(['is_active' => false])->save();
    $this->post('/account/reactivate', ['email' => $target->email, 'password' => 'TestPassword123456'])->assertSessionHasErrors('email');
    $target->forceFill(['is_active' => true])->save();
    $this->actingAs($target)->get('/account')->assertRedirect('/login');
});

it('restores a suspended user and allows normal login again', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $target = User::factory()->create(['is_suspended' => true, 'password' => 'TestPassword123456']);
    $this->actingAs($admin)->post('/admin/users/'.$target->id, ['action' => 'restore'])->assertSessionHasNoErrors();
    expect($target->fresh()->is_suspended)->toBeFalse();
    $this->post('/logout');
    $this->post('/login', ['email' => $target->email, 'password' => 'TestPassword123456'])->assertRedirect('/market');
});

it('revokes user sessions without suspending the account', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $target = User::factory()->create();
    $this->actingAs($admin)->post('/admin/users/'.$target->id, ['action' => 'revoke_sessions'])->assertSessionHasNoErrors();
    expect($target->fresh()->security_version)->toBe(1);
    expect($target->fresh()->is_suspended)->toBeFalse();
});

it('protects administrator accounts and rejects invalid operations', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $other = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin)->post('/admin/users/'.$admin->id, ['action' => 'suspend'])->assertSessionHasErrors('action');
    $this->post('/admin/users/'.$other->id, ['action' => 'suspend'])->assertSessionHasErrors('action');
    $this->post('/admin/users/'.$other->id, ['action' => 'grant_admin'])->assertSessionHasErrors('action');
    expect($admin->fresh()->is_suspended)->toBeFalse();
    expect($other->fresh()->is_suspended)->toBeFalse();
});

it('allows unverified active admins and still refuses inactive admins', function () {
    $admin = User::factory()->unverified()->create(['is_platform_admin' => true]);
    $this->actingAs($admin)->get('/admin')->assertOk();
    $admin->forceFill(['is_active' => false])->save();
    $this->get('/admin')->assertRedirect('/login');
});

it('grants platform access to existing active accounts through the console without verifying email', function () {
    $user = User::factory()->create();
    $this->artisan('admin:grant', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->is_platform_admin)->toBeTrue();
    $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'action' => 'admin.granted.cli']);
    $unverified = User::factory()->unverified()->create();
    $this->artisan('admin:grant', ['email' => $unverified->email])->assertSuccessful();
    expect($unverified->fresh()->is_platform_admin)->toBeTrue();
    $this->artisan('admin:grant', ['email' => 'missing@example.test'])->assertFailed();
});

it('redirects platform administrators to the admin panel after login', function () {
    $admin = User::factory()->create(['is_platform_admin' => true, 'password' => 'TestPassword123456']);
    $this->post('/login', ['email' => $admin->email, 'password' => 'TestPassword123456'])->assertRedirect('/admin');
});

it('provisions a new administrator without overwriting an existing account', function () {
    $this->artisan('admin:grant', ['email' => 'bootstrap@example.test', '--create' => true])->assertSuccessful();
    $admin = User::where('email', 'bootstrap@example.test')->sole();
    expect($admin->is_platform_admin)->toBeTrue();
    expect($admin->hasVerifiedEmail())->toBeFalse();
    $passwordHash = $admin->password;
    $this->artisan('admin:grant', ['email' => $admin->email, '--create' => true])->assertFailed();
    expect($admin->fresh()->password)->toBe($passwordHash);
});

it('does not expose organization management endpoints', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($admin)->get('/settings')->assertNotFound();
    $this->post('/organization', ['organization_id' => 1])->assertNotFound();
    $this->post('/admin/organizations', ['name' => 'Legacy', 'city' => 'Toshkent'])->assertNotFound();
});

it('filters account states without exposing unrelated matches', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $suspended = User::factory()->create(['is_suspended' => true]);
    User::factory()->create();
    $this->actingAs($admin)->get('/admin?tab=users&status=suspended')->assertInertia(fn (Assert $page) => $page
        ->has('users.data', 1)->where('users.data.0.id', $suspended->id)->where('filters.tab', 'users'));
    $this->get('/admin?status=unknown')->assertSessionHasErrors('status');
});
