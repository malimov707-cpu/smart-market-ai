<?php

use App\Http\Controllers\PriceWatchController;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\KorzinkaCatalog;
use App\Services\PriceTrendHistory;
use App\Services\StatUzAveragePrices;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function watchRow(string $price): array
{
    return ['price' => $price, 'name' => 'Olma', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null];
}

function recordTwoDays(): void
{
    $trends = app(PriceTrendHistory::class);
    $trends->record('olma', [watchRow('12000')], CarbonImmutable::parse('2026-10-30'));
    $trends->record('olma', [watchRow('11000')], CarbonImmutable::parse('2026-10-31'));
}

it('lets a verified user watch a market query and stores the subscription once', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/market/watch', ['query' => 'Olma', 'change_percent' => '5'])
        ->assertRedirect()->assertSessionHas('success');
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma'])
        ->assertRedirect();

    $subscription = AppNotification::where('user_id', $user->id)->where('event_type', 'market_watch_subscribed')->sole();
    expect($subscription->metadata['change_percent'])->toBe('5');
});

it('updates the threshold for an existing case-insensitive query subscription', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/market/watch', ['query' => 'Olma', 'change_percent' => '5'])->assertRedirect();

    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '10'])
        ->assertRedirect()->assertSessionHas('success');

    $subscription = AppNotification::where('user_id', $user->id)->where('event_type', 'market_watch_subscribed')->sole();
    expect($subscription->metadata['query'])->toBe('olma')
        ->and($subscription->metadata['change_percent'])->toBe('10')
        ->and($subscription->metadata['channels'])->toBe(['app']);

    recordTwoDays();
    expect(PriceWatchController::dispatchChanges())->toBe(0);

    $this->actingAs($user)->post('/market/watch', ['query' => 'OLMA', 'change_percent' => '5', 'email' => '1'])
        ->assertRedirect();
    expect(PriceWatchController::dispatchChanges())->toBe(1)
        ->and(AppNotification::where('user_id', $user->id)->where('event_type', 'market_price_moved')->count())->toBe(1);
});

it('rejects price watch thresholds outside the supported range', function (string $threshold) {
    [$user] = marketIdentity();

    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => $threshold])
        ->assertSessionHasErrors('change_percent');

    expect(AppNotification::where('user_id', $user->id)->where('event_type', 'market_watch_subscribed')->exists())->toBeFalse();
})->with(['0', '91', 'not-a-number']);

it('shows the saved threshold for the matching case-insensitive market query', function () {
    [$user] = marketIdentity();
    config(['inertia.ssr.enabled' => false]);
    AppNotification::create([
        'user_id' => $user->id,
        'organization_id' => null,
        'event_type' => 'market_watch_subscribed',
        'dedupe_key' => 'watch-sub:'.md5('olma'),
        'title' => 'Kuzatuvga olindi: Olma',
        'body' => 'Test subscription',
        'metadata' => ['query' => 'Olma', 'change_percent' => '10', 'channels' => ['app']],
        'email_status' => 'not_requested',
    ]);
    Cache::forever(KorzinkaCatalog::CACHE_KEY, ['checked_at' => now()->toISOString(), 'products' => []]);
    Cache::forever(StatUzAveragePrices::CACHE_KEY, [
        'checked_at' => now()->toISOString(), 'stale' => false, 'sync_failed' => false,
        'partial' => false, 'data_period' => '2026-M09', 'products' => [],
    ]);
    Http::preventStrayRequests();

    $this->actingAs($user)->get('/market?q=olma')->assertOk()->assertInertia(fn ($page) => $page->component('PublicMarket')
        ->where('watch.watching', true)->where('watch.threshold', '10')->where('watch.channels', ['app']));
});

it('sends price movement email only when the verified subscriber opts in', function () {
    [$user] = marketIdentity();
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp',
        'mail.mailers.smtp.host' => 'mail.test', 'mail.from.address' => 'market@example.test']);
    $mailer = Mockery::mock();
    $mailer->shouldReceive('raw')->once()->andReturn(new stdClass);
    Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);

    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '5', 'email' => '1'])->assertRedirect();
    recordTwoDays();

    expect(PriceWatchController::dispatchChanges())->toBe(1)
        ->and(AppNotification::where('user_id', $user->id)->where('event_type', 'market_price_moved')->sole()->email_status)->toBe('sent');
});

it('shows and marks global market notifications as read in the user inbox', function () {
    [$user] = marketIdentity();
    config(['inertia.ssr.enabled' => false]);
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '5'])->assertRedirect();
    recordTwoDays();
    PriceWatchController::dispatchChanges();
    $notification = AppNotification::where('user_id', $user->id)->where('event_type', 'market_price_moved')->sole();

    $this->actingAs($user)->get('/notifications')->assertOk()->assertInertia(fn ($page) => $page->component('Notifications')
        ->where('notifications.data.0.id', $notification->id)->where('unreadCount', 2));
    $this->actingAs($user)->post('/notifications/'.$notification->id.'/read')->assertRedirect();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('refuses watchers for unverified accounts', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma'])->assertRedirect(route('verification.notice'));
    expect(AppNotification::count())->toBe(0);
});

it('allows an unverified platform admin to use in-app price watching', function () {
    $user = User::factory()->unverified()->create(['is_platform_admin' => true]);
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '5', 'email' => '0'])
        ->assertRedirect()->assertSessionHas('success');
    recordTwoDays();

    expect(PriceWatchController::dispatchChanges())->toBe(1)
        ->and(AppNotification::where('user_id', $user->id)->where('event_type', 'market_price_moved')->exists())->toBeTrue();
});

it('dispatches a movement notification once per day when the watched price moves', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '5']);
    recordTwoDays();
    $first = PriceWatchController::dispatchChanges();
    $second = PriceWatchController::dispatchChanges();

    expect($first)->toBe(1)->and($second)->toBe(0);
    $hit = AppNotification::where('user_id', $user->id)->where('event_type', 'market_price_moved')->first();
    expect($hit)->not->toBeNull()
        ->and($hit->title)->toContain('▼')
        ->and($hit->url)->toBe('/market?q=olma')
        ->and($hit->email_status)->toBe('not_requested');
});

it('does not notify when the movement is below the threshold', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '50']);
    recordTwoDays();

    expect(PriceWatchController::dispatchChanges())->toBe(0);
});
