<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        if ($user && (! $user->is_active || $user->security_version !== (int) $request->session()->get('security_version', 0))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('success', 'Sessiya bekor qilingan. Hisobga qayta kiring.');
        }
        if ($user) {
            Auth::setUser($user);
        }

        return $next($request);
    }
}
