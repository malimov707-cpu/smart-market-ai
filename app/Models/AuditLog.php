<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    use HasFactory;

    protected $fillable = ['organization_id', 'user_id', 'action', 'subject_type', 'subject_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public const UPDATED_AT = null;
}
