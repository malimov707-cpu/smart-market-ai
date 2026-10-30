<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportEvent extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'import_batch_id', 'actor_id', 'action', 'from_status', 'to_status', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
