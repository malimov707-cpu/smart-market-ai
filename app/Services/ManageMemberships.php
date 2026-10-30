<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageMemberships
{
    public const ROLES = ['owner', 'manager', 'buyer', 'analyst', 'auditor'];

    public function invite(User $actor, Organization $organization, string $email, string $role): array
    {
        $data = Validator::make(['email' => strtolower(trim($email)), 'role' => $role], [
            'email' => ['required', 'email', 'max:254'], 'role' => ['required', Rule::in(self::ROLES)],
        ])->validate();

        return DB::transaction(function () use ($actor, $organization, $data): array {
            $organization = $this->lock($actor, $organization);
            if ($organization->users()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                throw ValidationException::withMessages(['email' => 'Bu foydalanuvchi tashkilot a’zosi.']);
            }
            Invitation::where('organization_id', $organization->id)->where('pending_email', $data['email'])
                ->where('expires_at', '<=', now())->update(['pending_email' => null, 'revoked_at' => now()]);
            if (Invitation::where('organization_id', $organization->id)->where('pending_email', $data['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'Amaldagi taklifnoma mavjud. Avval uni bekor qiling.']);
            }
            $token = Str::random(64);
            $invitation = Invitation::create([
                'organization_id' => $organization->id, 'invited_by' => $actor->id,
                ...$data, 'pending_email' => $data['email'], 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);
            $this->audit($actor, $organization, 'invitation.created', 'invitation', $invitation->id);

            return [$invitation, $token];
        });
    }

    public function accept(User $user, string $token): Organization
    {
        $candidate = Invitation::where('token_hash', hash('sha256', $token))->firstOrFail();

        return DB::transaction(function () use ($user, $candidate): Organization {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $organization = Organization::whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = Invitation::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->hasVerifiedEmail() && strcasecmp($user->email, $invitation->email) === 0, 403);
            abort_unless($invitation->isPending(), 410, 'Taklifnoma muddati tugagan yoki bekor qilingan.');
            abort_unless($invitation->inviter?->can('administer', $organization), 410, 'Taklif yuboruvchining ruxsati bekor qilingan.');
            if (! $organization->users()->where('users.id', $user->id)->exists()) {
                $organization->users()->attach($user, ['role' => $invitation->role]);
            }
            $invitation->update(['accepted_at' => now(), 'pending_email' => null]);
            $this->audit($user, $organization, 'invitation.accepted', 'invitation', $invitation->id);

            return $organization;
        });
    }

    public function revoke(User $actor, Organization $organization, int $id): void
    {
        DB::transaction(function () use ($actor, $organization, $id): void {
            $organization = $this->lock($actor, $organization);
            $invitation = Invitation::where('organization_id', $organization->id)->findOrFail($id);
            if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
                $invitation->update(['revoked_at' => now(), 'pending_email' => null]);
                $this->audit($actor, $organization, 'invitation.revoked', 'invitation', $invitation->id);
            }
        });
    }

    public function changeRole(User $actor, Organization $organization, int $member, ?string $role): void
    {
        if ($role !== null) {
            Validator::make(['role' => $role], ['role' => ['required', Rule::in(self::ROLES)]])->validate();
        }
        DB::transaction(function () use ($actor, $organization, $member, $role): void {
            User::whereKey($member)->lockForUpdate()->firstOrFail();
            $organization = $this->lock($actor, $organization);
            $target = $organization->users()->where('users.id', $member)->firstOrFail();
            if ($target->pivot->role === 'owner' && $role !== 'owner') {
                $this->requireOtherOwner($organization, $member);
            }
            if ($role === null) {
                $organization->users()->detach($member);
            } else {
                $organization->users()->updateExistingPivot($member, ['role' => $role]);
            }
            $this->audit($actor, $organization, $role === null ? 'member.removed' : 'member.role_changed', 'user', $member, ['before' => $target->pivot->role, 'after' => $role]);
        });
    }

    public function requireOtherOwner(Organization $organization, int $excluded): void
    {
        if (! $organization->users()->wherePivot('role', 'owner')->where('users.id', '!=', $excluded)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['membership' => 'Tashkilotda kamida bitta faol egasi qolishi kerak. Avval boshqa egani tayinlang.']);
        }
    }

    private function lock(User $actor, Organization $organization): Organization
    {
        $organization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('administer', $organization);

        return $organization;
    }

    private function audit(User $actor, Organization $organization, string $action, string $type, int $id, array $metadata = []): void
    {
        AuditLog::create(['organization_id' => $organization->id, 'user_id' => $actor->id,
            'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => $metadata]);
    }
}
