<?php

use App\Services\DocumentExtractor;
use App\Services\ImportFailure;
use App\Services\OperationsCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

it('reports configuration blockers without database writes or exposing secret values', function () {
    config(['app.debug' => true, 'app.key' => 'TEST-SECRET-KEY', 'database.connections.sqlite.password' => 'TEST-DB-PASSWORD',
        'mail.mailers.smtp.password' => 'TEST-SMTP-PASSWORD', 'imports.key' => 'TEST-AI-KEY']);
    $result = app(OperationsCheck::class)->configuration();
    expect($result['debug_disabled']['ok'])->toBeFalse();
    $encoded = json_encode($result);
    foreach (['TEST-SECRET-KEY', 'TEST-DB-PASSWORD', 'TEST-SMTP-PASSWORD', 'TEST-AI-KEY'] as $secret) {
        expect($encoded)->not->toContain($secret);
    }
    $this->artisan('ops:check')->assertFailed();
});

it('detects unsafe queue timeout and does not claim an active worker from a configured driver', function () {
    config(['queue.connections.database.retry_after' => 60, 'imports.queue_connection' => 'database']);
    $result = app(OperationsCheck::class)->configuration();
    expect($result['queue_timeout']['ok'])->toBeFalse();
    expect($result['persistent_import_queue']['detail'])->toContain('noma’lum');
});

it('does not connect or send mail in dry run or without an explicitly allowed recipient', function () {
    Mail::fake();
    $this->artisan('ops:smtp-probe')->assertSuccessful();
    $this->artisan('ops:smtp-probe', ['--send' => true, '--recipient' => 'unauthorized@example.test'])->assertFailed();
    Mail::assertNothingSent();
});

it('refuses log mailer and SMTP without mandatory TLS despite recipient authorization', function () {
    Mail::fake();
    config(['operations.smtp_test_recipients' => ['approved@example.test'], 'mail.default' => 'log']);
    $this->artisan('ops:smtp-probe', ['--send' => true, '--recipient' => 'approved@example.test'])->assertFailed();
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.scheme' => 'smtp', 'mail.mailers.smtp.require_tls' => false, 'mail.mailers.smtp.username' => 'TEST', 'mail.mailers.smtp.password' => 'TEST']);
    $this->artisan('ops:smtp-probe', ['--send' => true, '--recipient' => 'approved@example.test'])->assertFailed();
    Mail::assertNothingSent();
});

it('reports SMTP transport failure safely without leaking credentials or automatically retrying', function () {
    config(['operations.smtp_test_recipients' => ['approved@example.test'], 'mail.default' => 'smtp', 'mail.mailers.smtp.scheme' => 'smtp', 'mail.mailers.smtp.require_tls' => true,
        'mail.mailers.smtp.username' => 'TEST', 'mail.mailers.smtp.password' => 'TEST-SECRET', 'mail.mailers.smtp.url' => null]);
    Mail::shouldReceive('mailer')->once()->with('smtp')->andThrow(new RuntimeException('DO NOT PRINT TEST-SECRET'));
    $this->artisan('ops:smtp-probe', ['--send' => true, '--recipient' => 'approved@example.test'])->expectsOutputToContain('FAILED: SMTP connection/authentication/transport error.')->assertFailed();
});

it('handles provider outage rate limit timeout and invalid JSON without a database or live network', function (string $failure) {
    config(['imports.provider' => 'http', 'imports.model' => 'TEST', 'imports.endpoint' => 'https://ops-extract.example.test/v1', 'imports.key' => 'TEST']);
    Http::preventStrayRequests();
    Http::fake(['https://ops-extract.example.test/v1' => match ($failure) {
        'timeout' => Http::failedConnection(),'rate_limit' => Http::response([], 429),'outage' => Http::response([], 503),default => Http::response('not json', 200)
    }]);
    expect(fn () => app(DocumentExtractor::class)->extract('TEST document', 'application/pdf', 'TEST-id'))->toThrow(ImportFailure::class);
    Http::assertSentCount(1);
})->with(['timeout', 'rate_limit', 'outage', 'invalid_json']);

it('constructs an SMTP transport that requires TLS without connecting or authenticating', function () {
    config(['mail.mailers.smtp.scheme' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'TEST', 'mail.mailers.smtp.password' => 'TEST', 'mail.mailers.smtp.require_tls' => true, 'mail.mailers.smtp.url' => null]);
    Mail::purge('smtp');
    $transport = Mail::mailer('smtp')->getSymfonyTransport();
    expect($transport->isTlsRequired())->toBeTrue();
    expect($transport->isAutoTls())->toBeTrue();
});

it('keeps extraction unavailable with missing credentials instead of making a provider request', function () {
    config(['imports.provider' => 'http', 'imports.model' => 'TEST', 'imports.endpoint' => 'https://ops-extract.example.test/v1', 'imports.key' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://ops-extract.example.test/v1' => Http::response([])]);
    expect(fn () => app(DocumentExtractor::class)->extract('TEST', 'application/pdf', 'TEST'))
        ->toThrow(ImportFailure::class, 'provider_unavailable');
    Http::assertNothingSent();
});
