<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\Organization;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\ManageMemberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $databaseSessions = config('session.driver') === 'database';
        $sessions = $databaseSessions ? DB::table('sessions')->where('user_id', $request->user()->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
            ->orderByDesc('last_activity')->limit(100)->get(['id', 'last_activity', 'user_agent'])
            ->map(fn (object $session): array => [
                'key' => hash_hmac('sha256', $session->id, config('app.key')),
                'current' => hash_equals($request->session()->getId(), $session->id),
                'last_activity' => $session->last_activity, 'user_agent' => $session->user_agent,
            ]) : [];

        return Inertia::render('Account', ['profile' => $request->user()->only('name', 'email', 'locale', 'email_verified_at'),
            'sessions' => $sessions, 'databaseSessions' => $databaseSessions]);
    }

    public function profile(AccountRequest $request, AccountSecurity $security): RedirectResponse
    {
        $user = $request->user();
        $changedEmail = $user->email !== $request->validated('email');
        DB::transaction(function () use ($request, $user, $changedEmail, $security): void {
            $user->forceFill($request->safe()->only(['name', 'email', 'locale']));
            if ($changedEmail) {
                $user->email_verified_at = null;
            }
            $user->save();
            if ($changedEmail) {
                $security->revoke($user, 'profile.email_changed');
            }
            SecurityEvent::create(['user_id' => $user->id, 'action' => 'profile.updated']);
        });
        $request->session()->put('security_version', $user->security_version);
        $request->session()->regenerate();

        return back()->with('success', $changedEmail ? 'Email yangilandi. Yangi emailni tasdiqlang; boshqa sessiyalar bekor qilindi.' : 'Profil yangilandi.');
    }

    public function password(AccountRequest $request, AccountSecurity $security): RedirectResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($request, $user, $security): void {
            $user->forceFill(['password' => $request->validated('password')])->save();
            $security->revoke($user, 'password.changed');
        });
        $request->session()->put('security_version', $user->security_version);
        $request->session()->regenerate();

        return back()->with('success', 'Parol yangilandi. Boshqa sessiyalar bekor qilindi.');
    }

    public function revokeSessions(AccountRequest $request, AccountSecurity $security): RedirectResponse
    {
        $security->revoke($request->user(), 'sessions.revoked');
        $request->session()->put('security_version', $request->user()->security_version);
        $request->session()->regenerate();

        return back()->with('success', 'Boshqa sessiyalar bekor qilindi.');
    }

    public function revokeSession(AccountRequest $request, string $key): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database', 409);
        $sessions = DB::table('sessions')->where('user_id', $request->user()->id)->get(['id']);
        $target = $sessions->first(fn (object $session): bool => hash_equals(hash_hmac('sha256', $session->id, config('app.key')), $key));
        abort_unless($target && ! hash_equals($request->session()->getId(), $target->id), 404);
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $target->id)->delete();
        SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'session.revoked']);

        return back()->with('success', 'Sessiya bekor qilindi.');
    }

    public function deactivate(AccountRequest $request, AccountSecurity $security, ManageMemberships $memberships): RedirectResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($user, $memberships, $security): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $owned = $user->organizations()->wherePivot('role', 'owner')->orderBy('organizations.id')->pluck('organizations.id');
            foreach ($owned as $id) {
                $organization = Organization::whereKey($id)->lockForUpdate()->firstOrFail();
                $memberships->requireOtherOwner($organization, $user->id);
            }
            $user->forceFill(['is_active' => false])->save();
            $security->revoke($user, 'account.deactivated');
        });
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('success', 'Hisob faolsizlantirildi. Ma’lumotlar saqlanib qoldi.');
    }

    public function reactivate(Request $request, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Hisobni qayta faollashtirib bo‘lmadi. Ma’lumotlarni tekshiring.']);
        }
        DB::transaction(function () use ($user, $security): void {
            $user->forceFill(['is_active' => true])->save();
            $security->revoke($user, 'account.reactivated');
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('security_version', $user->security_version);

        return to_route('dashboard')->with('success', 'Hisob qayta faollashtirildi.');
    }
}
