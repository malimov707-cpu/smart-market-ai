<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierVerification extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'supplier_id', 'actor_id', 'scope', 'from_status', 'to_status', 'reason', 'evidence_ids'];

    protected function casts(): array
    {
        return ['evidence_ids' => 'array'];
    }
}
