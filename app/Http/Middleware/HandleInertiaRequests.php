<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, \Closure $next): Response
    {
        $response = parent::handle($request, $next);
        if ($request->isMethod('GET') && $response->headers->has('X-Inertia') && $response->getStatusCode() === 200) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return $response;
    }

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => fn () => $request->user()?->only('id', 'name', 'email', 'is_platform_admin', 'email_verified_at'),
            'organization' => fn () => $request->attributes->get('organization'),
            'organizations' => fn () => $request->user()?->organizations()->get(['organizations.id', 'name']),
            'flash' => fn () => ['success' => $request->session()->get('success')],
        ];
    }
}
