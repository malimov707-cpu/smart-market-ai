<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogFile extends Model
{
    protected $fillable = ['import_batch_id', 'organization_id', 'product_id', 'supplier_id', 'uploaded_by', 'name', 'path', 'mime', 'size', 'retired_at'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }
}
