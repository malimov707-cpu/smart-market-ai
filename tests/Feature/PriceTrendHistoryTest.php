<?php

use App\Http\Controllers\PriceWatchController;
use App\Models\AppNotification;
use App\Services\PriceTrendHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function trendRow(string $price): array
{
    return ['price' => $price, 'name' => 'Olma', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null];
}

it('records the cheapest price per day and builds a series', function () {
    $trends = new PriceTrendHistory;
    $trends->record('olma', [trendRow('12000'), trendRow('15900')], CarbonImmutable::parse('2026-10-30'));
    $trends->record('olma', [trendRow('11000')], CarbonImmutable::parse('2026-10-31'));
    // same day overwrite keeps the latest record only
    $trends->record('olma', [trendRow('10500')], CarbonImmutable::parse('2026-10-31'));

    $series = $trends->series('olma');
    expect($series)->toHaveCount(2)
        ->and($series[0])->toBe(['date' => '2026-10-30', 'price' => 12000.0])
        ->and($series[1]['price'])->toBe(10500.0);
    expect($trends->change('olma'))->toBe(-12.5);
});

it('ignores invalid prices and empty queries and caps history at 30 days', function () {
    $trends = new PriceTrendHistory;
    $trends->record('olma', [trendRow('CALL')], CarbonImmutable::parse('2026-10-30'));
    $trends->record('olma', [trendRow('12000')], CarbonImmutable::parse('2026-10-30'));
    $trends->record('  ', [trendRow('12000')]);

    expect($trends->series('olma'))->toBeArray()->toHaveCount(1);

    foreach (range(1, 35) as $day) {
        $trends->record('olma', [trendRow((string) (10000 + $day))], CarbonImmutable::parse('2026-09-01')->addDays($day)->addHours($day));
    }
    expect($trends->series('olma', 30))->toHaveCount(30);
});

it('detects a bulk deal window when the price drops well below its average', function () {
    $trends = new PriceTrendHistory;
    $row = fn (string $price): array => ['price' => $price, 'name' => 'Olma', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null];
    foreach ([['2026-10-28', '12000'], ['2026-10-29', '12000'], ['2026-10-30', '12000'], ['2026-10-31', '10000']] as [$day, $price]) {
        $trends->record('olma', [$row($price)], CarbonImmutable::parse($day));
    }

    $deal = $trends->deal('olma');
    expect($deal)->toBeArray()
        ->and($deal['percent'])->toBe(16.7)
        ->and($deal['price'])->toBe(10000.0)
        ->and($trends->deal('olma', 20))->toBeNull();
});

it('returns no deal without enough history or a rise', function () {
    $trends = new PriceTrendHistory;
    $row = fn (string $price): array => ['price' => $price, 'name' => 'Sut', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 l', 'checked_at' => null];
    expect($trends->deal('sut'))->toBeNull();

    foreach ([['2026-10-29', '12000'], ['2026-10-30', '12000']] as [$day, $price]) {
        $trends->record('sut', [$row($price)], CarbonImmutable::parse($day));
    }
    expect($trends->deal('sut'))->toBeNull();
});

it('creates the deal notification once per day and links the product page', function () {
    [$user] = marketIdentity();
    $user->forceFill(['telegram_chat_id' => '555'])->save();
    config(['services.telegram.bot_token' => null]);
    Cache::flush();
    $trends = new PriceTrendHistory;
    $row = fn (string $price): array => ['price' => $price, 'name' => 'Olma', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null];
    foreach ([['2026-10-28', '12000'], ['2026-10-29', '12000'], ['2026-10-30', '10000']] as [$day, $price]) {
        $trends->record('olma', [$row($price)], CarbonImmutable::parse($day));
    }
    $first = PriceWatchController::dispatchDeals(['olma']);
    $second = PriceWatchController::dispatchDeals(['olma']);

    expect($first)->toBe(1)->and($second)->toBe(0);
    $hit = AppNotification::where('event_type', 'market_deal_window')->where('user_id', $user->id)->first();
    expect($hit)->not->toBeNull()
        ->and($hit->title)->toContain('🔥')
        ->and($hit->url)->toBe('/market/product/olma');
});
