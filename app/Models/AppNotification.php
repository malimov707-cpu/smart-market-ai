<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'event_type', 'alert_rule_id', 'dedupe_key', 'title', 'body', 'metadata', 'url', 'email_status', 'read_at'];

    public static function priceWatchQueryKey(string $query): string
    {
        return md5(mb_strtolower(trim($query)));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'read_at' => 'immutable_datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
