<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\ProductRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductCsv
{
    public const COLUMNS = ['name', 'sku', 'unit', 'category', 'description', 'barcode', 'package_quantity', 'package_unit', 'aliases', 'variant_attributes', 'parent_sku'];

    public function preview(ProductImport $batch): array
    {
        $results = [];
        $seen = [];
        $barcodes = [];
        $rawValues = function (string $field) use ($batch): array {
            $column = $batch->mapping[$field] ?? null;
            if ($column === null || $column === '') {
                return [];
            }

            return array_values(array_filter(array_column($batch->rows, (int) $column), fn ($value) => $value !== null && $value !== ''));
        };
        $skuCache = Product::where('organization_id', $batch->organization_id)->whereIn('sku', array_unique([...$rawValues('sku'), ...$rawValues('parent_sku')]))->get()->keyBy('sku');
        $barcodeCache = Product::where('organization_id', $batch->organization_id)->whereIn('barcode', array_unique($rawValues('barcode')))->get()->keyBy('barcode');
        foreach ($batch->rows as $index => $raw) {
            $data = [];
            foreach ($batch->mapping as $field => $column) {
                if (in_array($field, self::COLUMNS, true) && $column !== null && $column !== '') {
                    $data[$field] = $raw[(int) $column] ?? null;
                }
            }
            $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
            $jsonErrors = [];
            foreach (['aliases', 'variant_attributes'] as $jsonField) {
                if (array_key_exists($jsonField, $data)) {
                    try {
                        $data[$jsonField] = $data[$jsonField] === null ? [] : json_decode($data[$jsonField], true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $jsonErrors[] = $jsonField.' ustuni JSON array bo‘lishi kerak.';
                        $data[$jsonField] = [];
                    }
                }
            }
            if (array_key_exists('parent_sku', $data)) {
                if ($data['parent_sku']) {
                    $parent = $skuCache->get($data['parent_sku']);
                    if (! $parent || $parent->archived_at || $parent->parent_product_id !== null) {
                        $jsonErrors[] = 'Parent SKU faol asosiy mahsulot sifatida avval import qilingan bo‘lishi kerak.';
                    } else {
                        $data['parent_product_id'] = $parent->id;
                        if ($parent->unit !== ($data['unit'] ?? null) || empty($data['variant_attributes'])) {
                            $jsonErrors[] = 'Variant birligi parent bilan bir xil va attributlari mavjud bo‘lishi kerak.';
                        }
                    }
                }
                unset($data['parent_sku']);
            }
            $rules = ProductRequest::rulesFor($batch->organization_id);
            $rules['parent_product_id'] = ['nullable', 'integer'];
            $rules['sku'] = ['required', 'string', 'max:80'];
            $rules['barcode'] = ['nullable', 'regex:/^\d{8,14}$/'];
            $validator = Validator::make($data, $rules);
            $errors = [...$jsonErrors, ...$validator->errors()->all()];
            if (! empty($data['variant_attributes']) && empty($data['parent_product_id'])) {
                $errors[] = 'Variant uchun parent_sku kerak.';
            }
            if (! empty($data['package_unit']) && isset($data['unit'])) {
                $base = ['kg' => 'kg', 'g' => 'kg', 'l' => 'l', 'ml' => 'l', 'pcs' => 'pcs'][$data['package_unit']] ?? null;
                if ($base !== $data['unit']) {
                    $errors[] = 'Qadoq birligi asosiy birlikka mos emas.';
                }
            }
            $duplicate = false;
            if (empty($errors)) {
                $sku = $data['sku'];
                $existing = $skuCache->get($sku);
                if ($existing) {
                    $duplicate = true;
                    foreach ($data as $key => $value) {
                        if ($key === 'package_quantity' && $value !== null && $existing->$key !== null) {
                            $same = bccomp((string) $existing->$key, (string) $value, 3) === 0;
                        } elseif (is_array($value)) {
                            $same = (array) $existing->$key === $value;
                        } else {
                            $same = (string) $existing->$key === (string) $value;
                        }
                        if (! $same) {
                            $errors[] = 'SKU mavjud, ammo ma’lumotlar farq qiladi. Avtomatik yangilanmaydi.';
                            $duplicate = false;
                            break;
                        }
                    }
                }
                if (isset($seen[$sku])) {
                    if ($seen[$sku] !== $data) {
                        $errors[] = 'CSV ichida bir SKU uchun zid ma’lumotlar bor.';
                    } else {
                        $duplicate = true;
                    }
                }
                $seen[$sku] = $data;
                if (! empty($data['barcode'])) {
                    if (isset($barcodes[$data['barcode']]) && $barcodes[$data['barcode']] !== $sku) {
                        $errors[] = 'CSV ichida barcode boshqa SKUda takrorlangan.';
                    }
                    $barcodes[$data['barcode']] = $sku;
                }
                $barcodeOwner = empty($data['barcode']) ? null : $barcodeCache->get($data['barcode']);
                if ($barcodeOwner && $barcodeOwner->id !== $existing?->id) {
                    $errors[] = 'Barcode boshqa mahsulotda mavjud.';
                }
            }
            $results[] = ['row' => $index + 2, 'data' => $data, 'errors' => $errors, 'duplicate' => $duplicate];
        }

        return $results;
    }

    public function confirm(User $actor, Organization $organization, ProductImport $batch): array
    {
        Gate::forUser($actor)->authorize('catalog', $organization);
        abort_unless($batch->organization_id === $organization->id, 404);

        return DB::transaction(function () use ($actor, $organization, $batch): array {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $batch = ProductImport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->completed_at) {
                return $batch->summary;
            }
            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['import' => 'Preview muddati tugagan. Qayta yuklang.']);
            }
            $rows = $this->preview($batch);
            if (collect($rows)->contains(fn ($row) => ! empty($row['errors']))) {
                throw ValidationException::withMessages(['import' => 'Xatolarni tuzatmaguncha import tasdiqlanmaydi.']);
            }
            $created = 0;
            $duplicates = 0;
            foreach ($rows as $row) {
                if ($row['duplicate']) {
                    $duplicates++;

                    continue;
                }
                app(SaveProduct::class)->execute($actor, $organization, $row['data']);
                $created++;
            }
            $summary = ['created' => $created, 'duplicates' => $duplicates];
            $batch->update(['completed_at' => now(), 'summary' => $summary]);
            AuditLog::create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'action' => 'products.imported', 'subject_type' => 'product_import', 'subject_id' => $batch->id, 'metadata' => $summary]);

            return $summary;
        });
    }

    public static function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x{FEFF}\x{200B}]*[=+\-@\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
