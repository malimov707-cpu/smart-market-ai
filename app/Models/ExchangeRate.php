<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'currency', 'rate', 'source', 'source_reference', 'effective_at', 'expires_at', 'submitted_by'];

    protected $hidden = ['rate_exact'];

    protected function rate(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes): string => isset($attributes['rate_exact']) ? bcadd($attributes['rate_exact'], '0', 12) : $this->asDecimal($value, 12),
            set: fn ($value): array => $this->getConnection()->getDriverName() === 'sqlite'
                ? ['rate' => $value, 'rate_exact' => bcadd((string) $value, '0', 12)] : ['rate' => $value],
        );
    }

    protected function casts(): array
    {
        return ['rate' => 'decimal:12', 'effective_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
    }
}
