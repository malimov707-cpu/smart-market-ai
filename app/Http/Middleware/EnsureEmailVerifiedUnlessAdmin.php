<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerifiedUnlessAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->is_platform_admin || $user?->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Email manzilingizni tasdiqlang.');
        }

        return redirect()->guest(route('verification.notice'));
    }
}
