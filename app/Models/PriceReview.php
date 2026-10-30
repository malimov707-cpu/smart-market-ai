<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceReview extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'offer_id', 'actor_id', 'scope', 'status', 'reason', 'evidence_file_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
