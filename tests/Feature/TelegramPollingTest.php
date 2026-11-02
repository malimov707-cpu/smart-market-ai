<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['services.telegram.bot_token' => '123:abc']);
});

it('processes local Telegram updates and acknowledges them without a webhook', function () {
    [$user] = marketIdentity();
    $linkToken = str_repeat('a', 48);
    $user->forceFill(['telegram_link_token' => $linkToken])->save();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.org/bot123:abc/getUpdates' => Http::sequence()
            ->push(['ok' => true, 'result' => [[
                'update_id' => 41,
                'message' => ['chat' => ['id' => 777], 'text' => '/start '.$linkToken],
            ]]])
            ->push(['ok' => true, 'result' => []]),
        'https://api.telegram.org/bot123:abc/sendMessage' => Http::response(['ok' => true]),
    ]);

    $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

    expect($user->refresh()->telegram_chat_id)->toBe('777');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getUpdates')
        && ($request->data()['offset'] ?? null) === 42);
});

it('reports when polling cannot run because a webhook is configured', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Conflict'], 409),
    ]);

    $this->artisan('telegram:poll', ['--once' => true])
        ->expectsOutputToContain('webhook')
        ->assertFailed();
});

it('does not expose the bot token when a one-time poll times out', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.org/bot123:abc/getUpdates' => Http::failedConnection(),
    ]);

    $this->artisan('telegram:poll', ['--once' => true])
        ->expectsOutputToContain('texnik tafsilotlar yashirildi')
        ->doesntExpectOutputToContain('123:abc')
        ->assertFailed();
});

it('does not contact Telegram when the bot token is missing', function () {
    config(['services.telegram.bot_token' => null]);
    Http::preventStrayRequests();
    Http::fake([]);

    $this->artisan('telegram:poll', ['--once' => true])
        ->expectsOutputToContain('TELEGRAM_BOT_TOKEN sozlanmagan.')
        ->assertFailed();

    Http::assertNothingSent();
});
