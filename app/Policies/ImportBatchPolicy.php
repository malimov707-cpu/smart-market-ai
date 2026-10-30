<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\User;

class ImportBatchPolicy
{
    public function view(User $user, ImportBatch $batch): bool
    {
        return $user->can('view', Organization::findOrFail($batch->organization_id));
    }

    public function update(User $user, ImportBatch $batch): bool
    {
        return $user->can($batch->type === 'offers' ? 'manage' : 'catalog', Organization::findOrFail($batch->organization_id));
    }
}
