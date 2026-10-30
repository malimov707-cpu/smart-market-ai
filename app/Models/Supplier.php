<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $attributes = ['verification_status' => 'unverified', 'platform_verification_status' => 'unverified'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    protected $fillable = ['organization_id', 'name', 'contact', 'city', 'description', 'email', 'archived_at'];
}
