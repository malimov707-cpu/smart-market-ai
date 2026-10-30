<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierReview extends Model
{
    protected $fillable = ['organization_id', 'supplier_id', 'user_id', 'offer_id', 'rating', 'body', 'hidden_at'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime', 'rating' => 'integer'];
    }
}
