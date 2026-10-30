<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationRequest;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Services\ManageMemberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function show(Request $request): Response
    {
        $organization = $request->attributes->get('organization');
        $admin = $request->user()->can('administer', $organization);

        return Inertia::render('Organization', [
            'canAdminister' => $admin,
            'members' => $admin ? $organization->users()->orderBy('name')->paginate(20, ['users.id', 'name', 'email', 'is_active']) : null,
            'invitations' => $admin ? Invitation::where('organization_id', $organization->id)->latest('id')->paginate(20, ['id', 'email', 'role', 'expires_at', 'accepted_at', 'revoked_at'], 'invitation_page') : null,
        ]);
    }

    public function update(OrganizationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $organization = Organization::whereKey($request->attributes->get('organization')->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('administer', $organization);
            $organization->update($request->validated());
            AuditLog::create(['organization_id' => $organization->id, 'user_id' => $request->user()->id,
                'action' => 'organization.updated', 'subject_type' => 'organization', 'subject_id' => $organization->id]);
        });

        return back()->with('success', 'Tashkilot ma’lumotlari yangilandi.');
    }

    public function role(OrganizationRequest $request, int $member, ManageMemberships $service): RedirectResponse
    {
        $service->changeRole($request->user(), $request->attributes->get('organization'), $member, $request->validated('role'));

        return back()->with('success', 'A’zo roli yangilandi.');
    }

    public function remove(OrganizationRequest $request, int $member, ManageMemberships $service): RedirectResponse
    {
        $service->changeRole($request->user(), $request->attributes->get('organization'), $member, null);

        return back()->with('success', 'A’zolik bekor qilindi.');
    }
}
