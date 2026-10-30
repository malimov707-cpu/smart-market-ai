<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtractionAttempt extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'import_batch_id', 'request_key', 'provider', 'model', 'status'];

    protected function casts(): array
    {
        return ['cost' => 'decimal:8'];
    }
}
