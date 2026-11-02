<?php

use App\Http\Controllers\PriceWatchController;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\PriceTrendHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

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

    expect(AppNotification::where('user_id', $user->id)->where('event_type', 'market_watch_subscribed')->count())->toBe(1);
});

it('refuses watchers for unverified accounts', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma'])->assertRedirect(route('verification.notice'));
    expect(AppNotification::count())->toBe(0);
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
        ->and($hit->url)->toBe('/market?q=olma');
});

it('does not notify when the movement is below the threshold', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->post('/market/watch', ['query' => 'olma', 'change_percent' => '50']);
    recordTwoDays();

    expect(PriceWatchController::dispatchChanges())->toBe(0);
});
