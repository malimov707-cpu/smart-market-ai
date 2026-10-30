<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;

    protected $attributes = ['status' => 'uploaded', 'stage' => 'prepare', 'generation' => 0, 'revision' => 0, 'row_count' => 0, 'processed_count' => 0, 'error_count' => 0, 'attempts' => 0, 'sheet' => 0, 'scan_status' => 'unavailable', 'duplicate_policy' => 'skip', 'error_policy' => 'atomic'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'user_id', 'type', 'format', 'file_name', 'file_path', 'file_mime', 'file_size', 'file_hash', 'idempotency_key', 'sheet', 'header_row', 'timezone', 'expires_at'];

    protected $hidden = ['file_path', 'lease_token'];

    protected function casts(): array
    {
        return ['sheets' => 'array', 'headers' => 'array', 'mapping' => 'array', 'summary' => 'array', 'expires_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImportEvent::class);
    }
}
