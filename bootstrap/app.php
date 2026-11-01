<?php

use App\Http\Middleware\CatalogSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestContext::class);
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->redirectUsersTo(fn (Request $request): string => route($request->user()?->is_platform_admin ? 'admin' : ($request->user()?->hasVerifiedEmail() ? 'dashboard' : 'market')));
        $middleware->web(append: [HandleInertiaRequests::class], replace: [StartSession::class => CatalogSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
