<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Services\CompareOffers;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

it('returns a health response with a generated correlation id and security headers', function () {
    $this->get('/up', ['X-Request-ID' => 'untrusted'])->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Request-ID');
});

it('enforces tenant-qualified offer foreign keys at the database boundary', function () {
    $offer = Offer::factory()->create();
    $foreign = Product::factory()->create();
    expect(fn () => $offer->update(['product_id' => $foreign->id]))->toThrow(QueryException::class);
});

it('refuses comparison through the service when membership is absent', function () {
    $offer = Offer::factory()->create();
    $user = User::factory()->create();
    expect(fn () => app(CompareOffers::class)->compare($offer->product, '1', CarbonImmutable::now(), $user))
        ->toThrow(HttpException::class);
});
