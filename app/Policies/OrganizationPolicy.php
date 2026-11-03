<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, ['owner', 'manager', 'buyer', 'analyst', 'auditor']);
    }

    public function manage(User $user, Organization $organization): bool
    {
        return $this->isActiveAndVerified($user) && $user->is_platform_admin;
    }

    public function catalog(User $user, Organization $organization): bool
    {
        return $this->isActiveAndVerified($user) && $user->is_platform_admin;
    }

    public function administer(User $user, Organization $organization): bool
    {
        return $this->isActiveAndVerified($user) && $user->is_platform_admin;
    }

    private function hasRole(User $user, Organization $organization, array $roles): bool
    {
        if ($user->is_platform_admin) {
            return User::query()->whereKey($user->id)->where('is_active', true)->exists();
        }

        return $this->isActiveAndVerified($user)
            && $user->organizations()->where('organizations.id', $organization->id)->wherePivotIn('role', $roles)->exists();
    }

    private function isActiveAndVerified(User $user): bool
    {
        return User::whereKey($user->id)->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }
}
