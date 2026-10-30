<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImport extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'headers', 'rows', 'mapping', 'summary', 'completed_at', 'expires_at'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'rows' => 'array', 'mapping' => 'array', 'summary' => 'array', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
