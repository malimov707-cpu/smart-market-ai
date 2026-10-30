<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $user->can('view', Organization::findOrFail($product->organization_id));
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('catalog', Organization::findOrFail($product->organization_id));
    }
}
