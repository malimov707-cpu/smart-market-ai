<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['organization_id', 'name', 'sku', 'unit', 'category', 'archived_at', 'description', 'barcode', 'aliases', 'parent_product_id', 'variant_attributes', 'package_quantity', 'package_unit'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'aliases' => 'array', 'variant_attributes' => 'array', 'package_quantity' => 'decimal:3'];
    }
}
