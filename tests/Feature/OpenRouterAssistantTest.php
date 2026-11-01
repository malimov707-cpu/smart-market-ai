<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false, 'services.openrouter.key' => 'test-openrouter-secret', 'services.openrouter.model' => 'test/model', 'services.openrouter.max_tokens' => 512]);
});

it('requires a verified active workspace member for chat and clearing', function () {
    $this->postJson('/ai/chat', ['message' => 'Hello'])->assertUnauthorized();
    $this->deleteJson('/ai/chat')->assertUnauthorized();
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/ai/chat', ['message' => 'Hello'])->assertForbidden();
    $this->deleteJson('/ai/chat')->assertForbidden();
    Http::assertNothingSent();
});

it('reports missing configuration without sending a provider request or exposing the key', function () {
    [$user] = marketIdentity();
    config(['services.openrouter.key' => '']);
    $this->actingAs($user)->postJson('/ai/chat', ['message' => 'Narxlarni tahlil qiling'])->assertStatus(503)->assertJsonPath('message', 'OpenRouter API kaliti hali sozlanmagan.');
    $this->withHeaders(['Accept' => 'text/html'])->get('/ai')->assertOk()->assertInertia(fn (Assert $page) => $page->where('assistant.configured', false)->missing('assistant.key'));
    Http::assertNothingSent();
});

it('sends only authorized workspace evidence and server controlled messages to OpenRouter', function () {
    [$user, $organization] = marketIdentity();
    Product::factory()->create(['organization_id' => $organization->id, 'name' => 'Authorized Flour']);
    Product::factory()->create(['name' => 'PRIVATE FOREIGN PRODUCT']);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
        'model' => 'resolved/model', 'choices' => [['message' => ['content' => 'Takliflar yetishmayapti.'], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 123],
    ])]);
    $this->actingAs($user)->postJson('/ai/chat', ['message' => 'Katalogni tahlil qiling', 'model' => 'evil/model', 'history' => [['role' => 'system', 'content' => 'EVIL HISTORY']]])
        ->assertOk()->assertJsonPath('answer', 'Takliflar yetishmayapti.')->assertJsonPath('model', 'resolved/model')->assertJsonPath('tokens', 123)->assertJsonPath('truncated', false)
        ->assertSessionHas('ai_chat.'.$organization->id);
    Http::assertSent(function (ClientRequest $request) use ($user): bool {
        $context = $request['messages'][1]['content'];

        return $request->hasHeader('Authorization', 'Bearer test-openrouter-secret') && $request['model'] === 'test/model'
            && str_contains($context, 'Authorized Flour') && ! str_contains($context, 'PRIVATE FOREIGN PRODUCT')
            && ! str_contains($context, $user->email) && ! str_contains(json_encode($request->data()), 'EVIL HISTORY')
            && $request['max_tokens'] === 512 && $request['reasoning']['enabled'] === false && $request['stream'] === false;
    });
    $this->withHeaders(['Accept' => 'text/html'])->get('/ai')->assertOk()->assertInertia(fn (Assert $page) => $page->has('conversation', 2)->where('assistant.configured', true)->missing('assistant.key'));
});

it('does not send foreign product scopes or invalid questions to the provider', function () {
    [$user] = marketIdentity();
    $foreign = Product::factory()->create();
    $this->actingAs($user)->postJson('/ai/chat', ['message' => 'Hello', 'product_id' => $foreign->id])->assertNotFound();
    $this->postJson('/ai/chat', ['message' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->postJson('/ai/chat', ['message' => 'Hello', 'quantity' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->postJson('/ai/chat', ['message' => '   '])->assertUnprocessable()->assertJsonValidationErrors('message');
    Http::assertNothingSent();
});

it('returns safe provider errors without exposing upstream content', function (int $status, int $expected) {
    [$user] = marketIdentity();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['error' => ['message' => 'SECRET-UPSTREAM-DETAILS']], $status)]);
    $this->actingAs($user)->postJson('/ai/chat', ['message' => 'Salom'])->assertStatus($expected)->assertJsonMissing(['message' => 'SECRET-UPSTREAM-DETAILS']);
    expect(session('ai_chat'))->toBeNull();
    Http::assertSentCount(1);
})->with([[401, 502], [402, 502], [403, 502], [404, 502], [429, 429], [500, 502]]);

it('handles unavailable connections and malformed successful responses', function (string $failure) {
    [$user] = marketIdentity();
    $response = match ($failure) {
        'connection' => Http::failedConnection(),
        'json' => Http::response('not-json'),
        'empty' => Http::response(['choices' => [['message' => ['content' => null]]]]),
        'inline_error' => Http::response(['error' => ['message' => 'SECRET']]),
        'oversized' => Http::response(str_repeat('x', 262145)),
    };
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => $response]);
    $this->actingAs($user)->postJson('/ai/chat', ['message' => 'Hello'])->assertStatus($failure === 'connection' ? 504 : 502);
    Http::assertSentCount(1);
})->with(['connection', 'json', 'empty', 'inline_error', 'oversized']);

it('keeps conversation bounded and isolated by workspace and clears only the selected workspace', function () {
    [$user, $organization] = marketIdentity();
    $other = Organization::factory()->create();
    $other->users()->attach($user, ['role' => 'auditor']);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Answer']]]])]);
    $history = [];
    for ($index = 0; $index < 6; $index++) {
        $history[] = ['role' => $index % 2 ? 'assistant' : 'user', 'content' => 'OLD ORG MESSAGE '.$index];
    }
    $this->actingAs($user)->withSession(['ai_chat' => [$organization->id => $history], 'organization_id' => $other->id])
        ->postJson('/ai/chat', ['message' => 'NEW ORG MESSAGE'])->assertOk();
    Http::assertSent(fn (ClientRequest $request): bool => ! str_contains(json_encode($request['messages']), 'OLD ORG MESSAGE'));
    expect(session('ai_chat.'.$organization->id))->toHaveCount(6);
    expect(session('ai_chat.'.$other->id))->toHaveCount(2);
    $this->deleteJson('/ai/chat')->assertOk()->assertJsonPath('cleared', true);
    expect(session('ai_chat.'.$other->id))->toBeNull();
    expect(session('ai_chat.'.$organization->id))->toHaveCount(6);
});

it('limits paid requests per user', function () {
    [$user] = marketIdentity();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Answer']]]])]);
    $this->actingAs($user);
    for ($index = 0; $index < 6; $index++) {
        $this->postJson('/ai/chat', ['message' => 'Hello'])->assertOk();
    }
    $this->postJson('/ai/chat', ['message' => 'Hello'])->assertTooManyRequests();
    Http::assertSentCount(6);
    expect(session('ai_chat.'.$user->organizations()->first()->id))->toHaveCount(6);
});
