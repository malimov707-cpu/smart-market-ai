<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'inertia.ssr.enabled' => false,
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.telegram.bot_token' => '123:telegram-bot-token',
        'services.telegram.username' => 'smart_market_aibot',
    ]);
    Http::preventStrayRequests();
});

it('provides social authentication settings to both authentication pages', function () {
    $this->get('/register')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')
        ->where('register', true)->where('providers.google', true)->where('providers.googleRedirect', null)
        ->where('providers.telegram', true)->where('providers.telegramUsername', 'smart_market_aibot'));
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')
        ->where('register', false)->where('providers.google', true)->where('providers.googleRedirect', null)
        ->where('providers.telegram', true));
});

it('registers and signs in a Google account only after Google verifies its email', function () {
    $redirect = $this->get('/auth/google')->assertRedirect();
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $authorization);
    expect($authorization['code_challenge_method'])->toBe('S256');

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-user-42',
            'email' => 'google-user@example.test',
            'email_verified' => true,
            'name' => 'Google User',
        ]),
    ]);

    $this->get(route('auth.google.callback', ['state' => $authorization['state'], 'code' => 'auth-code']))
        ->assertRedirect('/market');
    $user = User::where('email', 'google-user@example.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user)->toMatchArray(['google_id' => 'google-user-42', 'is_platform_admin' => false]);
    expect($user->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'action' => 'account.login_google']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openidconnect.googleapis.com/v1/userinfo'
        && $request->hasHeader('Authorization', 'Bearer google-access-token'));
});

it('rejects a Google callback with an invalid state without contacting Google', function () {
    $this->get('/auth/google');
    Http::fake([]);

    $this->get(route('auth.google.callback', ['state' => 'wrong-state', 'code' => 'auth-code']))
        ->assertRedirect('/login')->assertSessionHasErrors('oauth');
    $this->assertGuest();
    Http::assertNothingSent();
});

it('does not register a Google profile with an unverified email', function () {
    $redirect = $this->get('/auth/google')->assertRedirect();
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $authorization);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-unverified',
            'email' => 'unverified@example.test',
            'email_verified' => false,
            'name' => 'Unverified User',
        ]),
    ]);

    $this->get(route('auth.google.callback', ['state' => $authorization['state'], 'code' => 'auth-code']))
        ->assertRedirect('/login')->assertSessionHasErrors('oauth');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'unverified@example.test']);
});

it('uses a matching verified Google email to sign in to the existing account', function () {
    $account = User::factory()->create(['email' => 'existing@example.test', 'is_platform_admin' => true]);
    $redirect = $this->get('/auth/google')->assertRedirect();
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $authorization);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-existing-account',
            'email' => 'existing@example.test',
            'email_verified' => true,
            'name' => 'Existing Account',
        ]),
    ]);

    $this->get(route('auth.google.callback', ['state' => $authorization['state'], 'code' => 'auth-code']))
        ->assertRedirect('/admin');
    $this->assertAuthenticatedAs($account);
    expect(User::count())->toBe(1);
    expect($account->fresh()->google_id)->toBe('google-existing-account');
});

it('verifies Telegram login signatures and creates a separate Telegram-only user', function () {
    $this->freezeTime();
    $payload = ['id' => '123456789', 'first_name' => 'Telegram', 'last_name' => 'User', 'auth_date' => (string) now()->timestamp];
    $state = 'telegram-login-state';
    $callback = telegramLoginUrl($payload, $state, '123:telegram-bot-token');
    Notification::fake();

    $this->withSession(['telegram_login_state' => $state])->get($callback)->assertRedirect('/market');
    $user = User::where('telegram_login_id', '123456789')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Telegram User');
    expect($user->email)->toBe('telegram-123456789@login.smart-market.invalid');
    expect($user->email_verified_at)->toBeNull();
    expect($user->is_platform_admin)->toBeFalse();
    $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'action' => 'account.login_telegram']);
    $this->post('/logout')->assertRedirect('/login');
    $this->get($callback)->assertRedirect('/login')->assertSessionHasErrors('oauth');
    $this->assertDatabaseCount('users', 1);
    Notification::assertNothingSent();
});

it('connects Telegram login to an account already linked with the bot', function () {
    $account = User::factory()->create(['telegram_chat_id' => '123456789']);
    $payload = ['id' => '123456789', 'first_name' => 'Telegram', 'auth_date' => (string) now()->timestamp];
    $state = 'telegram-login-state';

    $this->withSession(['telegram_login_state' => $state])
        ->get(telegramLoginUrl($payload, $state, '123:telegram-bot-token'))->assertRedirect('/market');
    $this->assertAuthenticatedAs($account);
    expect($account->fresh()->telegram_login_id)->toBe('123456789');
    expect(User::count())->toBe(1);
});

it('refuses social login for suspended accounts', function () {
    $account = User::factory()->create(['telegram_login_id' => '123456789', 'is_suspended' => true]);
    $payload = ['id' => '123456789', 'first_name' => 'Telegram', 'auth_date' => (string) now()->timestamp];
    $state = 'telegram-login-state';

    $this->withSession(['telegram_login_state' => $state])
        ->get(telegramLoginUrl($payload, $state, '123:telegram-bot-token'))
        ->assertRedirect('/login')->assertSessionHasErrors('oauth');
    $this->assertGuest();
    expect($account->fresh()->telegram_login_id)->toBe('123456789');
});

it('rejects expired Telegram login data and invalid signatures', function () {
    $this->freezeTime();
    $oldPayload = ['id' => '123456789', 'first_name' => 'Telegram', 'auth_date' => (string) now()->subMinutes(6)->timestamp];
    $invalidPayload = ['id' => '987654321', 'first_name' => 'Forged', 'auth_date' => (string) now()->timestamp];
    $state = 'telegram-login-state';

    $this->withSession(['telegram_login_state' => $state])
        ->get(telegramLoginUrl($oldPayload, $state, '123:telegram-bot-token'))
        ->assertRedirect('/login')->assertSessionHasErrors('oauth');
    $this->withSession(['telegram_login_state' => $state])
        ->get(route('auth.telegram.callback', [...$invalidPayload, 'state' => $state, 'hash' => str_repeat('0', 64)]))
        ->assertRedirect('/login')->assertSessionHasErrors('oauth');
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
    Http::assertNothingSent();
});

it('lets a Telegram-only user add a real email before using email-verified features', function () {
    $user = User::factory()->create([
        'email' => 'telegram-123456789@login.smart-market.invalid',
        'telegram_login_id' => '123456789',
    ]);
    Notification::fake();

    $this->actingAs($user)->post('/account/profile', [
        'name' => $user->name,
        'email' => 'real-email@example.test',
        'locale' => 'uz',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('real-email@example.test');
    expect($user->fresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

function telegramLoginSignature(array $payload, string $botToken): string
{
    ksort($payload, SORT_STRING);
    $checkString = collect($payload)->map(fn (string $value, string $key): string => $key.'='.$value)->implode("\n");

    return hash_hmac('sha256', $checkString, hash('sha256', $botToken, true));
}

function telegramLoginUrl(array $payload, string $state, string $botToken): string
{
    return route('auth.telegram.callback', [
        ...$payload,
        'state' => $state,
        'hash' => telegramLoginSignature($payload, $botToken),
    ]);
}
