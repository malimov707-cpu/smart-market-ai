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
        return $this->hasRole($user, $organization, ['owner', 'manager', 'buyer']);
    }

    public function catalog(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, ['owner', 'manager']);
    }

    public function administer(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, ['owner']);
    }

    private function hasRole(User $user, Organization $organization, array $roles): bool
    {
        return User::whereKey($user->id)->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            && $user->organizations()->where('organizations.id', $organization->id)->wherePivotIn('role', $roles)->exists();
    }
}
