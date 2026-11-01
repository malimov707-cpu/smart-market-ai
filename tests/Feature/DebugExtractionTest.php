<?php

use App\Services\HttpDocumentExtractor;
use App\Services\ImportFailure;
use Illuminate\Support\Facades\Http;

it('rejects a successful HTTP response containing invalid JSON instead of returning fake extraction', function () {
    config(['imports.provider' => 'http', 'imports.model' => 'TEST', 'imports.key' => 'TEST', 'imports.endpoint' => 'https://extract.example.test/v1']);
    Http::preventStrayRequests();
    Http::fake(['https://extract.example.test/v1' => Http::response('invalid-json', 200)]);
    expect(fn () => app(HttpDocumentExtractor::class)->extract('TEST', 'application/pdf', 'TEST'))
        ->toThrow(ImportFailure::class, 'provider_invalid_json');
    Http::assertSentCount(1);
});
