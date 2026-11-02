<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\MarketRequest;
use App\Http\Requests\ProductRequest;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportReconciliation
{
    public const SUPPLIER_COLUMNS = ['supplier_id', 'name', 'contact', 'city', 'email', 'description'];

    public const OFFER_COLUMNS = ['product_id', 'sku', 'barcode', 'product_name', 'supplier_id', 'supplier_name', 'price', 'currency', 'unit', 'package_quantity', 'minimum_packages', 'delivery_fee', 'observed_at', 'expires_at', 'source_reference'];

    private array $cache = [];

    public function columns(string $type): array
    {
        return match ($type) {
            'products' => ProductCsv::COLUMNS, 'suppliers' => self::SUPPLIER_COLUMNS, 'offers' => self::OFFER_COLUMNS
        };
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    public function row(ImportBatch $batch, ImportRow $row): array
    {
        $data = [];
        foreach ($batch->mapping ?? [] as $field => $column) {
            if (in_array($field, $this->columns($batch->type), true) && $column !== null && $column !== '') {
                $value = $row->raw[(int) $column] ?? null;
                $data[$field] = $value === '' ? null : $value;
            }
        }
        if ($batch->type === 'offers') {
            if (! empty($row->overrides['product_id'])) {
                unset($data['sku'], $data['barcode'], $data['product_name']);
                $data['product_id'] = $row->overrides['product_id'];
            }
            if (! empty($row->overrides['supplier_id'])) {
                unset($data['supplier_name']);
                $data['supplier_id'] = $row->overrides['supplier_id'];
            }
        }
        $errors = [];
        $match = null;
        try {
            [$data, $existing, $identity] = match ($batch->type) {
                'products' => $this->product($batch, $data), 'suppliers' => $this->supplier($batch, $data), 'offers' => $this->offer($batch, $data)
            };
            if ($existing) {
                $match = ['id' => $existing->id, 'snapshot' => $this->snapshot($existing), 'record' => $existing->only($this->visibleFields($batch->type))];
            }
            $key = $batch->type.':'.$identity;
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $seen = $this->cache['seen'][$key] ?? null;
            if ($seen && $seen !== $hash) {
                throw ValidationException::withMessages(['row' => 'Batch ichida bir identifikator uchun zid qiymatlar mavjud.']);
            }
            $this->cache['seen'][$key] = $hash;
            $duplicate = $seen !== null || ($existing !== null && ($batch->duplicate_policy === 'skip' || $batch->type === 'offers'));
        } catch (ValidationException $error) {
            $errors = $error->errors();
            $duplicate = false;
        }

        return ['data' => $data, 'errors' => $errors, 'match' => $match, 'status' => $errors ? 'invalid' : ($duplicate ? 'duplicate' : 'valid')];
    }

    public function snapshot(Model $model): string
    {
        return hash('sha256', json_encode($model->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    public function visibleFields(string $type): array
    {
        return match ($type) {
            'products' => ['id', 'name', 'sku', 'unit', 'barcode', 'category', 'description', 'parent_product_id', 'variant_attributes', 'aliases', 'package_quantity', 'package_unit'],
            'suppliers' => ['id', 'name', 'city', 'contact', 'email', 'description'],
            'offers' => ['id', 'product_id', 'supplier_id', 'price', 'currency', 'package_quantity', 'unit', 'observed_at', 'expires_at', 'source_reference'],
        };
    }

    private function product(ImportBatch $batch, array $data): array
    {
        foreach (['aliases', 'variant_attributes'] as $field) {
            if (array_key_exists($field, $data)) {
                try {
                    $data[$field] = $data[$field] === null ? [] : json_decode($data[$field], true, 24, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->error($field, 'JSON list kerak.');
                }
            }
        }
        $existing = empty($data['sku']) ? null : Product::where('organization_id', $batch->organization_id)->where('sku', $data['sku'])->first();
        if (! empty($data['parent_sku'])) {
            $parent = Product::where('organization_id', $batch->organization_id)->where('sku', $data['parent_sku'])->whereNull('parent_product_id')->whereNull('archived_at')->first();
            if (! $parent) {
                $this->error('parent_sku', 'Parent SKU avval faol root mahsulot sifatida saqlangan bo‘lsin.');
            }
            $data['parent_product_id'] = $parent->id;
        }
        unset($data['parent_sku']);
        $data = Validator::make($data, ProductRequest::rulesFor($batch->organization_id, $existing?->id))->validate();
        if ($existing?->archived_at) {
            $this->error('sku', 'SKU arxivda; avval uni tiklang.');
        }
        if (! empty($data['parent_product_id'])) {
            $parent = Product::where('organization_id', $batch->organization_id)->findOrFail($data['parent_product_id']);
            if ($parent->unit !== $data['unit'] || empty($data['variant_attributes']) || $parent->id === $existing?->id) {
                $this->error('parent_sku', 'Variant parent, unit va attributlari mos bo‘lsin.');
            }
        } elseif (! empty($data['variant_attributes'])) {
            $this->error('parent_sku', 'Variant uchun parent kerak.');
        }
        if (! empty($data['package_unit']) && CompareOffers::UNITS[$data['package_unit']][0] !== $data['unit']) {
            $this->error('package_unit', 'Qadoq birligi mos emas.');
        }
        if ($existing) {
            foreach (['unit', 'barcode', 'parent_product_id', 'variant_attributes'] as $field) {
                if (array_key_exists($field, $data) && (is_array($data[$field]) ? $data[$field] != ($existing->$field ?? []) : (string) $data[$field] !== (string) $existing->$field)) {
                    $this->error($field, 'Mavjud SKU identifikatsiyasi import orqali o‘zgarmaydi.');
                }
            }
        }

        return [$data, $existing, $data['sku']];
    }

    private function supplier(ImportBatch $batch, array $data): array
    {
        $rules = ['supplier_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:180'], 'contact' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:254'], 'description' => ['nullable', 'string', 'max:5000']];
        $data = Validator::make($data, $rules)->validate();
        $query = Supplier::where('organization_id', $batch->organization_id);
        if (! empty($data['supplier_id'])) {
            $query->whereKey($data['supplier_id']);
        } else {
            $query->where('name', $data['name']);
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            $this->error('name', 'Supplier nomi noaniq; aniq supplier_id tanlang.');
        }
        $existing = $matches->first();
        if ((! empty($data['supplier_id']) && ! $existing) || ($existing && ($existing->name !== $data['name'] || $existing->archived_at))) {
            $this->error('supplier_id', 'Supplier shu tashkilotga tegishli, faol va nomi mos bo‘lsin.');
        }
        unset($data['supplier_id']);

        return [$data, $existing, $existing ? 'id:'.$existing->id : 'name:'.$data['name']];
    }

    private function offer(ImportBatch $batch, array $data): array
    {
        $product = $this->resolveProduct($batch, $data);
        $supplier = $this->resolveSupplier($batch, $data);
        $data['product_id'] = $product->id;
        $data['supplier_id'] = $supplier->id;
        foreach (['sku', 'barcode', 'product_name', 'supplier_name'] as $field) {
            unset($data[$field]);
        }
        foreach (['observed_at', 'expires_at'] as $field) {
            if (! empty($data[$field])) {
                if (! is_string($data[$field]) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/', $data[$field])) {
                    $this->error($field, 'ISO sana/vaqt yoki XLSX date cell kerak.');
                }
                $parsedDate = date_parse($data[$field]);
                if ($parsedDate['warning_count'] || $parsedDate['error_count']) {
                    $this->error($field, 'Sana kalendarga mos emas.');
                }
                try {
                    $data[$field] = CarbonImmutable::parse($data[$field], $batch->timezone)->utc()->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    $this->error($field, 'Sana/vaqt noto‘g‘ri.');
                }
            }
        }
        $rules = MarketRequest::offerRules();
        $rules['product_id'] = ['required', 'integer'];
        $rules['supplier_id'] = ['required', 'integer'];
        $data = Validator::make($data, $rules)->validate();
        $data['price'] = bcadd((string) $data['price'], '0', 2);
        $data['package_quantity'] = bcadd((string) $data['package_quantity'], '0', 3);
        $data['minimum_packages'] = (int) $data['minimum_packages'];
        $data['delivery_fee'] = isset($data['delivery_fee']) ? bcadd((string) $data['delivery_fee'], '0', 2) : null;
        $data['expires_at'] = empty($data['expires_at']) ? null : $data['expires_at'];
        ksort($data);
        $query = Offer::where('organization_id', $batch->organization_id);
        foreach ($data as $key => $value) {
            if (in_array($key, ['price', 'delivery_fee'], true) && $value !== null && DB::getDriverName() === 'sqlite') {
                $query->where(fn ($query) => $query->where($key.'_exact', $value)->orWhere(fn ($query) => $query->whereNull($key.'_exact')->where($key, $value)));
            } else {
                $value === null ? $query->whereNull($key) : $query->where($key, $value);
            }
        }
        $existing = $query->orderByDesc('id')->first();

        return [$data, $existing, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))];
    }

    private function resolveProduct(ImportBatch $batch, array $data): Product
    {
        $identity = array_filter(array_intersect_key($data, array_flip(['product_id', 'sku', 'barcode', 'product_name'])), fn ($value) => $value !== null && $value !== '');
        if ($identity === []) {
            $this->error('product_id', 'Mahsulot ID, SKU, barcode yoki aniq nomini tanlang.');
        }
        Validator::make($identity, ['product_id' => ['nullable', 'integer'], 'sku' => ['nullable', 'string', 'max:80'], 'barcode' => ['nullable', 'regex:/^\d{8,14}$/'], 'product_name' => ['nullable', 'string', 'max:180']])->validate();
        $key = 'product:'.json_encode($identity);
        if (! isset($this->cache[$key])) {
            $query = Product::where('organization_id', $batch->organization_id)->whereNull('archived_at');
            foreach ($identity as $field => $value) {
                $query->where(match ($field) {
                    'product_id' => 'id', 'product_name' => 'name', default => $field
                }, $value);
            }
            $records = $query->limit(2)->get();
            if ($records->count() !== 1) {
                $this->error('product_id', 'Mahsulot mosligi yo‘q yoki noaniq; aniq tenant SKU/IDni tanlang.');
            }
            $this->cache[$key] = $records->first();
        }

        return $this->cache[$key];
    }

    private function resolveSupplier(ImportBatch $batch, array $data): Supplier
    {
        $identity = array_filter(array_intersect_key($data, array_flip(['supplier_id', 'supplier_name'])), fn ($value) => $value !== null && $value !== '');
        if ($identity === []) {
            $this->error('supplier_id', 'Supplier ID yoki aniq nomini tanlang.');
        }
        Validator::make($identity, ['supplier_id' => ['nullable', 'integer'], 'supplier_name' => ['nullable', 'string', 'max:180']])->validate();
        $key = 'supplier:'.json_encode($identity);
        if (! isset($this->cache[$key])) {
            $query = Supplier::where('organization_id', $batch->organization_id)->whereNull('archived_at');
            foreach ($identity as $field => $value) {
                $query->where($field === 'supplier_id' ? 'id' : 'name', $value);
            }
            $records = $query->limit(2)->get();
            if ($records->count() !== 1) {
                $this->error('supplier_id', 'Supplier mosligi yo‘q yoki noaniq; aniq tenant IDni tanlang.');
            }
            $this->cache[$key] = $records->first();
        }

        return $this->cache[$key];
    }

    private function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
