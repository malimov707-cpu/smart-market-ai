<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDispute extends Model
{
    protected $fillable = ['review_id', 'opened_by', 'reason', 'resolved_by', 'resolution', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
