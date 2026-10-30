<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        Log::withContext(['request_id' => $id]);
        $start = hrtime(true);
        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $id);
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            if ($request->user() || $request->is('login', 'register', '*password*', 'invitations/*', '*verify*')) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }
            Log::info('http.completed', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(), 'status' => $response->getStatusCode(),
                'duration_ms' => round((hrtime(true) - $start) / 1000000, 2),
            ]);

            return $response;
        } finally {
            Log::withoutContext();
        }
    }
}
