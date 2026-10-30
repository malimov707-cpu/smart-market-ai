<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = ['import_batch_id', 'organization_id', 'product_id', 'supplier_id', 'price', 'currency', 'unit', 'package_quantity', 'minimum_packages', 'delivery_fee', 'observed_at', 'expires_at', 'provenance', 'source_reference', 'fingerprint', 'recorded_at', 'source_type', 'source_identifier', 'task_reference', 'submitted_by', 'evidence_file_id', 'correction_of_id', 'correction_reason'];

    protected $hidden = ['price_exact', 'delivery_fee_exact'];

    protected function price(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes): string => isset($attributes['price_exact']) ? bcadd($attributes['price_exact'], '0', 2) : $this->asDecimal($value, 2),
            set: fn ($value): array => $this->getConnection()->getDriverName() === 'sqlite'
                ? ['price' => $value, 'price_exact' => bcadd((string) $value, '0', 2)] : ['price' => $value],
        );
    }

    protected function deliveryFee(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes): ?string => ($attributes['delivery_fee_exact'] ?? $value) === null ? null : (isset($attributes['delivery_fee_exact']) ? bcadd($attributes['delivery_fee_exact'], '0', 2) : $this->asDecimal($value, 2)),
            set: fn ($value): array => $this->getConnection()->getDriverName() === 'sqlite'
                ? ['delivery_fee' => $value, 'delivery_fee_exact' => $value === null ? null : bcadd((string) $value, '0', 2)] : ['delivery_fee' => $value],
        );
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'package_quantity' => 'decimal:3', 'delivery_fee' => 'decimal:2', 'observed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
