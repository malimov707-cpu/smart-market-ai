<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountMail;
use App\Services\AccountSecurity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Recovery', ['token' => null, 'email' => '']);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        AccountMail::ensureAvailable();
        try {
            Password::sendResetLink($data);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['email' => 'Xatni yuborib bo‘lmadi. Keyinroq qayta urinib ko‘ring.']);
        }

        return back()->with('success', 'Agar email ro‘yxatdan o‘tgan bo‘lsa, tiklash havolasi yuboriladi.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Recovery', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            app(AccountSecurity::class)->revoke($user, 'password.reset');
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'Tiklash havolasi yaroqsiz yoki muddati tugagan.']);
        }

        return to_route('login')->with('success', 'Parol yangilandi. Endi hisobga kiring.');
    }
}
