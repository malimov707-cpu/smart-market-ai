<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'city'];

    public static function preferred(): ?self
    {
        return static::query()->orderByRaw("CASE WHEN name = 'FayzTools' THEN 0 ELSE 1 END, id")->first();
    }

    public static function allIds(): Builder
    {
        return static::query()->select('id')->orderByRaw("CASE WHEN name = 'FayzTools' THEN 0 ELSE 1 END, id");
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }
}
