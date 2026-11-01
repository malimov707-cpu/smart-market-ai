<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\Organization;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Auth', ['register' => $request->routeIs('register')]);
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $organization = Organization::create(['name' => $request->validated('organization')]);
            $organization->users()->attach($user, ['role' => 'owner']);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('security_version', $user->fresh()->security_version);
        try {
            event(new Registered($user));
        } catch (\Throwable) {
            SecurityEvent::create(['user_id' => $user->id, 'action' => 'verification.mail_failed']);
        }

        return to_route('dashboard');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt([...$credentials, 'is_active' => true, 'is_suspended' => false])) {
            throw ValidationException::withMessages(['email' => 'Email yoki parol noto‘g‘ri.']);
        }
        $request->session()->regenerate();

        $request->session()->put('security_version', $request->user()->security_version);
        SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'account.login']);

        return redirect()->intended(route($request->user()->is_platform_admin ? 'admin' : 'dashboard'));
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
