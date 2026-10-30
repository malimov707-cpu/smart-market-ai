<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertRule extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'name', 'event_type', 'scope_type', 'scope_id', 'threshold_kind', 'threshold_value', 'frequency', 'channels', 'enabled'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'enabled' => 'boolean', 'threshold_value' => 'decimal:4'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
