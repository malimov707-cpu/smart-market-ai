<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        return to_route($request->user()?->is_platform_admin ? 'admin' : 'market');
    }

    public function show(Request $request): Response
    {
        return Inertia::render('Auth', ['register' => $request->routeIs('register')]);
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('security_version', $user->fresh()->security_version);
        $request->session()->forget('organization_id');
        $request->session()->forget('url.intended');
        try {
            event(new Registered($user));
        } catch (\Throwable) {
            SecurityEvent::create(['user_id' => $user->id, 'action' => 'verification.mail_failed']);
        }

        return to_route('market')->with('success', 'Hisobingiz yaratildi. Endi bozor narxlarini qidirishingiz mumkin.');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt([...$credentials, 'is_active' => true, 'is_suspended' => false])) {
            throw ValidationException::withMessages(['email' => 'Email yoki parol noto‘g‘ri.']);
        }
        $request->session()->regenerate();

        $request->session()->put('security_version', $request->user()->security_version);
        $selected = $request->session()->get('organization_id');
        if ($selected && ! $request->user()->organizations()->whereKey($selected)->exists()) {
            $request->session()->forget('organization_id');
        }
        SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'account.login']);
        if (! $request->user()->hasVerifiedEmail() || ! $request->user()->is_platform_admin) {
            return to_route('market');
        }

        return redirect()->intended(route($request->user()->is_platform_admin ? 'admin' : 'market'));
    }

    public function logout(Request $request): RedirectResponse
    {
        SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'account.logout']);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }

    public function switchOrganization(Request $request): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'integer']]);
        $organization = $request->user()->organizations()->findOrFail($data['organization_id']);
        $request->session()->put('organization_id', $organization->id);

        return to_route('dashboard');
    }
}
