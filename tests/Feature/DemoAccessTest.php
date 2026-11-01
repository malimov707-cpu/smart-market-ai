<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Vite;

uses(LazilyRefreshDatabase::class);

it('uses built assets in production even when a development server is configured', function () {
    config(['inertia.ssr.enabled' => false]);
    $hotFile = tempnam(sys_get_temp_dir(), 'smart-market-vite-');
    file_put_contents($hotFile, 'http://localhost:5173');
    Vite::useHotFile($hotFile);
    $this->app->instance('env', 'production');
    try {
        $this->app->getProvider(AppServiceProvider::class)->boot();
        $this->get('/login')->assertOk()->assertSee('/build/assets/', false)->assertDontSee('@vite/client', false)->assertDontSee('localhost:5173', false);
    } finally {
        unlink($hotFile);
        $this->app->instance('env', 'testing');
    }
});

it('accepts forwarded https only from a loopback proxy', function (string $remote, string $scheme) {
    $this->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders([
        'Host' => 'demo.example.test', 'X-Forwarded-Proto' => 'https',
    ])->get('http://demo.example.test/products')->assertRedirect($scheme.'://demo.example.test/login');
})->with([['127.0.0.1', 'https'], ['198.51.100.10', 'http']]);
