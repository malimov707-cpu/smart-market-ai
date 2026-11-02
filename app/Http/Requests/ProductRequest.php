<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($id = $this->route('product')) {
            Product::whereIn('organization_id', Organization::allIds())->findOrFail($id);
        }

        return $this->user()->can('catalog', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        $id = $this->route('product') ? (int) $this->route('product') : null;
        $tenant = $id
            ? Product::whereIn('organization_id', Organization::allIds())->findOrFail($id)->organization_id
            : $this->attributes->get('organization')->id;

        return self::rulesFor((int) $tenant, $id);
    }

    public static function rulesFor(int $tenant, ?int $id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products')->where('organization_id', $tenant)->ignore($id)],
            'unit' => ['required', Rule::in(['kg', 'l', 'pcs'])],
            'description' => ['nullable', 'string', 'max:5000'], 'category' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'regex:/^\d{8,14}$/', Rule::unique('products')->where('organization_id', $tenant)->ignore($id)],
            'aliases' => ['sometimes', 'array', 'list', 'max:20'], 'aliases.*' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'parent_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('organization_id', $tenant)->whereNull('parent_product_id')->whereNull('archived_at')],
            'variant_attributes' => ['sometimes', 'array', 'list', 'max:10'], 'variant_attributes.*' => ['array:name,value'], 'variant_attributes.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'variant_attributes.*.value' => ['required', 'string', 'max:120'],
            'package_quantity' => ['nullable', 'required_with:package_unit', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999.999'],
            'package_unit' => ['nullable', 'required_with:package_quantity', Rule::in(['kg', 'g', 'l', 'ml', 'pcs'])],
        ];
    }
}
