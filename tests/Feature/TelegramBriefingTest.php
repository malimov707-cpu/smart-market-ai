<?php

use App\Models\User;
use App\Services\MarketBriefing;
use App\Services\PriceTrendHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false, 'services.telegram.bot_token' => '123:abc',
        'services.telegram.username' => 'smart_market_aibot', 'services.telegram.webhook_secret' => 's3cret']);
});

function webhookUpdate(string $text, int $chatId = 777): array
{
    return ['message' => ['chat' => ['id' => $chatId], 'text' => $text]];
}

it('links an account through the start deep link and answers brief', function () {
    [$user] = marketIdentity();
    $user->forceFill(['telegram_link_token' => str_repeat('a', 48)])->save();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $response = $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/start '.str_repeat('a', 48)));
    $response->assertOk();
    expect($user->refresh()->telegram_chat_id)->toBe('777');
    expect($user->telegram_link_token)->toBeNull();
    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/start '.str_repeat('a', 48), 888))->assertOk();
    expect($user->refresh()->telegram_chat_id)->toBe('777');

    $trends = app(PriceTrendHistory::class);
    $row = fn (string $price): array => ['price' => $price, 'name' => 'Olma', 'currency' => 'UZS', 'source_name' => 'Korzinka', 'source_url' => 'https://x', 'image' => null, 'unit_label' => '1 kg', 'checked_at' => null];
    $trends->record('olma', [$row('12000')], CarbonImmutable::parse(now()->subDay()));
    $trends->record('olma', [$row('11400')], CarbonImmutable::parse(now()));

    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/brief'))->assertOk();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage') && str_contains((string) ($request->data()['text'] ?? ''), 'Smart Market AI'));
});

it('provides help and account status through bot-targeted commands', function () {
    [$user] = marketIdentity();
    $user->forceFill(['telegram_chat_id' => '777', 'telegram_briefing' => false])->save();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/help@smart_market_aibot'))->assertOk();
    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/status@smart_market_aibot'))->assertOk();
    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/unknown'))->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) ($request->data()['text'] ?? ''), '/brief — bozor narxlari xulosasi'));
    Http::assertSent(fn ($request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'O‘chirilgan'));
    Http::assertSent(fn ($request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'Mavjud buyruqlar: /help'));
    Http::assertSentCount(3);
});

it('rejects wrong webhook secrets and ignores unrelated updates', function () {
    Http::fake();
    $this->postJson('/webhook/telegram/wrong', webhookUpdate('/start'))->assertNotFound();
    config(['services.telegram.bot_token' => null]);
    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/start'))->assertStatus(503);
    Http::assertNothingSent();
});

it('stops the briefing on stop command', function () {
    [$user] = marketIdentity();
    $user->forceFill(['telegram_chat_id' => '777'])->save();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->postJson('/webhook/telegram/s3cret', webhookUpdate('/stop'))->assertOk();
    expect($user->refresh()->telegram_chat_id)->toBeNull();
});

it('sends the daily briefing to linked users who kept it enabled', function () {
    [$user] = marketIdentity();
    $user->forceFill(['telegram_chat_id' => '555'])->save();
    User::factory()->create(['telegram_chat_id' => '666', 'telegram_briefing' => false]);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->artisan('telegram:briefing')->assertSuccessful();
    Http::assertSent(fn ($request): bool => ($request->data()['chat_id'] ?? null) === '555');
    Http::assertSentCount(1);
});

it('reports failed Telegram deliveries instead of a successful briefing run', function () {
    [$user] = marketIdentity();
    $user->forceFill(['telegram_chat_id' => '555'])->save();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

    $this->artisan('telegram:briefing')->assertFailed()
        ->expectsOutputToContain('muvaffaqiyatsiz: 1');
});

it('briefing content is honest about missing data', function () {
    $brief = app(MarketBriefing::class)->forUser(User::factory()->create());
    expect($brief)->toContain('barqaror bozor');
});

it('account page exposes the telegram link and toggle works', function () {
    [$user] = marketIdentity();
    $this->actingAs($user)->get('/account')->assertOk()->assertInertia(
        fn ($page) => $page->component('Account')->where('telegram.configured', true)
            ->where('telegram.link', fn (string $link): bool => str_starts_with($link, 'https://t.me/smart_market_aibot?start='))
    );
    $token = $user->refresh()->telegram_link_token;
    expect($token)->not->toBeNull();

    $this->actingAs($user)->post('/account/telegram/toggle', ['briefing' => false])->assertRedirect();
    expect($user->refresh()->telegram_briefing)->toBeFalse();
});
