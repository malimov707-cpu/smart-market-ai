<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('sends email verification after registration and blocks an unverified workspace', function () {
    Notification::fake();
    $this->post('/register', ['name' => 'New Owner', 'organization' => 'Test Org', 'email' => 'new@example.test',
        'password' => 'Password123456', 'password_confirmation' => 'Password123456'])->assertRedirect('/market');
    $user = User::where('email', 'new@example.test')->sole();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/dashboard')->assertRedirect('/verify-email');
    $this->get('/account')->assertOk();
});

it('accepts only a valid verification signature for the authenticated account', function () {
    $this->freezeTime();
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get($url.'&tampered=1')->assertForbidden();
    $this->get($url)->assertRedirect('/market');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'action' => 'email.verified']);
});

it('refuses expired and wrong-account verification links', function () {
    $this->freezeTime();
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($other)->get($url)->assertForbidden();
    $this->travel(61)->minutes();
    $this->actingAs($user)->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends verification mail with throttling', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);
    for ($i = 0; $i < 3; $i++) {
        $this->post('/email/verification-notification')->assertRedirect();
    }
    $this->post('/email/verification-notification')->assertTooManyRequests();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('updates a profile without accepting active status or security version input', function () {
    $user = User::factory()->create(['password' => 'Password123456']);
    $this->actingAs($user)->post('/account/profile', ['name' => 'Updated', 'email' => $user->email, 'locale' => 'uz',
        'current_password' => 'Password123456', 'is_active' => false, 'security_version' => 999])->assertSessionHasNoErrors();
    expect($user->fresh())->toMatchArray(['name' => 'Updated', 'is_active' => true]);
    expect($user->fresh()->security_version)->toBe(0);
    $this->assertDatabaseHas('security_events', ['action' => 'profile.updated']);
});

it('invalidates verification and other sessions when email changes', function () {
    $user = User::factory()->create(['password' => 'Password123456']);
    $this->actingAs($user)->post('/account/profile', ['name' => $user->name, 'email' => 'changed@example.test',
        'locale' => 'ru', 'current_password' => 'Password123456'])->assertSessionHasNoErrors();
    expect($user->fresh())->toMatchArray(['email' => 'changed@example.test', 'email_verified_at' => null]);
    expect($user->fresh()->security_version)->toBe(1);
    $this->get('/products')->assertRedirect('/verify-email');
});

it('requires current password for profile changes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/account/profile', ['name' => 'Changed', 'email' => $user->email,
        'locale' => 'uz', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect($user->fresh()->name)->not->toBe('Changed');
});

it('changes a password and revokes other sessions while preserving the current session', function () {
    $user = User::factory()->create(['password' => 'Password123456']);
    DB::table('sessions')->insert(['id' => 'old-test-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($user)->post('/account/password', ['current_password' => 'Password123456',
        'password' => 'NewPassword123456', 'password_confirmation' => 'NewPassword123456'])->assertSessionHasNoErrors();
    expect(Hash::check('NewPassword123456', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->security_version)->toBe(1);
    $this->assertDatabaseMissing('sessions', ['id' => 'old-test-session']);
    $this->get('/account')->assertOk();
});

it('rejects stale session versions even for non-database session backends', function () {
    $user = User::factory()->create(['security_version' => 1]);
    $this->actingAs($user)->withSession(['security_version' => 0])->get('/account')->assertRedirect('/login');
    $this->assertGuest();
});

it('revokes all other sessions and keeps the requesting session usable', function () {
    $user = User::factory()->create(['password' => 'Password123456']);
    $this->actingAs($user)->post('/account/sessions/revoke', ['current_password' => 'Password123456'])->assertSessionHasNoErrors();
    expect($user->fresh()->security_version)->toBe(1);
    $this->get('/account')->assertOk();
    $this->assertDatabaseHas('security_events', ['action' => 'sessions.revoked']);
});

it('deactivates a non-owner account without deleting data and allows explicit credential reactivation', function () {
    [$user] = marketIdentity('auditor');
    $user->forceFill(['password' => 'Password123456'])->save();
    $this->actingAs($user)->post('/account/deactivate', ['current_password' => 'Password123456'])->assertRedirect('/login');
    expect($user->fresh()->is_active)->toBeFalse();
    $this->assertGuest();
    $this->post('/login', ['email' => $user->email, 'password' => 'Password123456'])->assertSessionHasErrors('email');
    $this->post('/account/reactivate', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->post('/account/reactivate', ['email' => $user->email, 'password' => 'Password123456'])->assertRedirect('/admin');
    expect($user->fresh()->is_active)->toBeTrue();
    $this->get('/dashboard')->assertOk();
});

it('prevents the sole active owner from deactivating', function () {
    [$user] = marketIdentity();
    $user->forceFill(['password' => 'Password123456'])->save();
    $this->actingAs($user)->post('/account/deactivate', ['current_password' => 'Password123456'])->assertSessionHasErrors('membership');
    expect($user->fresh()->is_active)->toBeTrue();
});

it('refuses existing sessions after the account is deactivated elsewhere', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    User::whereKey($user->id)->update(['is_active' => false]);
    $this->get('/account')->assertRedirect('/login');
    $this->assertGuest();
});

it('lists sessions without exposing their raw authentication ids', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'secret-session-id', 'user_id' => $user->id, 'payload' => 'secret-payload', 'last_activity' => time(), 'user_agent' => 'Test browser']);
    $this->actingAs($user)->get('/account')->assertInertia(fn (Assert $page) => $page
        ->component('Account')->has('sessions', 1)->where('sessions.0.key', hash_hmac('sha256', 'secret-session-id', config('app.key'))));
});

it('revokes only a session belonging to the current account', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'Password123456']);
    $other = User::factory()->create();
    foreach ([$user->id => 'own-session', $other->id => 'foreign-session'] as $id => $session) {
        DB::table('sessions')->insert(['id' => $session, 'user_id' => $id, 'payload' => '', 'last_activity' => time()]);
    }
    $foreignKey = hash_hmac('sha256', 'foreign-session', config('app.key'));
    $ownKey = hash_hmac('sha256', 'own-session', config('app.key'));
    $this->actingAs($user)->post('/account/sessions/'.$foreignKey.'/revoke', ['current_password' => 'Password123456'])->assertNotFound();
    $this->post('/account/sessions/'.$ownKey.'/revoke', ['current_password' => 'Password123456'])->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('sessions', ['id' => 'own-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
});

it('refuses verification and recovery through the log mailer without writing secret links', function () {
    config(['mail.default' => 'log']);
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post('/email/verification-notification')->assertSessionHasErrors('email');
    $this->post('/logout');
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');
    $this->post('/forgot-password', ['email' => 'absent@example.test'])->assertSessionHasErrors('email');
    Notification::assertNothingSent();
});

it('denies domain permissions when a stale user object has been deactivated or unverified', function () {
    [$user, $organization] = marketIdentity();
    User::whereKey($user->id)->update(['is_active' => false]);
    expect($user->can('manage', $organization))->toBeFalse();
    User::whereKey($user->id)->update(['is_active' => true, 'email_verified_at' => null]);
    expect($user->can('manage', $organization))->toBeFalse();
});

it('marks authenticated account pages private and prevents referrer leakage', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/account')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
