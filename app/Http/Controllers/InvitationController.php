<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationRequest;
use App\Models\Invitation;
use App\Models\SecurityEvent;
use App\Notifications\OrganizationInvitation;
use App\Services\AccountMail;
use App\Services\ManageMemberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InvitationController extends Controller
{
    public function store(OrganizationRequest $request, ManageMemberships $service): RedirectResponse
    {
        AccountMail::ensureAvailable();
        [$invitation, $token] = $service->invite($request->user(), $request->attributes->get('organization'), $request->validated('email'), $request->validated('role'));
        try {
            Notification::route('mail', $invitation->email)->notify(new OrganizationInvitation(
                $request->attributes->get('organization')->name, route('invitations.show', ['token' => $token]),
            ));
        } catch (Throwable) {
            $service->revoke($request->user(), $request->attributes->get('organization'), $invitation->id);
            SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'invitation.mail_failed']);
            throw ValidationException::withMessages(['email' => 'Xatni yuborib bo‘lmadi. Taklifnoma bekor qilindi; mail sozlamalarini tekshiring.']);
        }

        return back()->with('success', 'Taklifnoma mail transportiga topshirildi. Yetib borishi email xizmatiga bog‘liq.');
    }

    public function show(Request $request, string $token): Response
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless(strcasecmp($invitation->email, $request->user()->email) === 0, 403);
        abort_unless($invitation->isPending(), 410);

        return Inertia::render('Invitation', ['invitation' => [
            'organization' => $invitation->organization->name, 'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, string $token, ManageMemberships $service): RedirectResponse
    {
        $organization = $service->accept($request->user(), $token);
        $request->session()->put('organization_id', $organization->id);

        return to_route('dashboard')->with('success', 'Tashkilotga qo‘shildingiz.');
    }

    public function revoke(OrganizationRequest $request, int $invitation, ManageMemberships $service): RedirectResponse
    {
        $service->revoke($request->user(), $request->attributes->get('organization'), $invitation);

        return back()->with('success', 'Taklifnoma bekor qilindi.');
    }
}
