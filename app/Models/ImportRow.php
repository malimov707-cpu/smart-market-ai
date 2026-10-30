<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'import_batch_id', 'row_number', 'raw', 'extraction', 'overrides'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'overrides' => 'array', 'data' => 'array', 'errors' => 'array', 'extraction' => 'array', 'match' => 'array'];
    }
}
