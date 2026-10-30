<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplierPolicy
{
    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('view', Organization::findOrFail($supplier->organization_id));
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('catalog', Organization::findOrFail($supplier->organization_id));
    }

    public function verify(User $user, Supplier $supplier): bool
    {
        return $user->can('administer', Organization::findOrFail($supplier->organization_id));
    }

    public function platformVerify(User $user, Supplier $supplier): bool
    {
        return $this->view($user, $supplier) && DB::table('platform_permissions')->where('user_id', $user->id)->where('permission', 'supplier.verify')->exists();
    }

    public function review(User $user, Supplier $supplier): bool
    {
        return $supplier->archived_at === null && $user->can('manage', Organization::findOrFail($supplier->organization_id));
    }
}
