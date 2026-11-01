<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Services\AccountMail;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VerificationController extends Controller
{
    public function notice(): Response
    {
        return Inertia::render('VerifyEmail', ['mailAvailable' => AccountMail::available()]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill();
            SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'email.verified']);
        }

        return to_route($request->user()->is_platform_admin ? 'admin' : 'market')->with('success', 'Email tasdiqlandi.');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            AccountMail::ensureAvailable();
            try {
                $request->user()->sendEmailVerificationNotification();
            } catch (\Throwable) {
                SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'verification.mail_failed']);
                throw ValidationException::withMessages(['email' => 'Xatni yuborib bo‘lmadi. Keyinroq qayta urinib ko‘ring.']);
            }
        }

        return back()->with('success', 'Tasdiqlash xati yuborildi.');
    }
}
