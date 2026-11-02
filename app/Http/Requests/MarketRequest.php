<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ExchangeRates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('products.*', 'suppliers.*') ? 'catalog' : 'manage', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        $tenant = $this->attributes->get('organization')->id;

        return match ($this->route()->getName()) {
            'products.store' => [
                'name' => ['required', 'string', 'max:180'],
                'sku' => ['required', 'string', 'max:80', Rule::unique('products')->where('organization_id', $tenant)],
                'unit' => ['required', Rule::in(['kg', 'l', 'pcs'])],
                'category' => ['nullable', 'string', 'max:100'],
            ],
            'suppliers.store' => [
                'name' => ['required', 'string', 'max:180'],
                'contact' => ['nullable', 'string', 'max:180'],
                'city' => ['required', 'string', 'max:100'],
            ],
            'offers.store' => self::offerRules(),
            default => [],
        };
    }

    public static function offerRules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('archived_at')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('archived_at')],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'currency' => ['required', Rule::in(ExchangeRates::CURRENCIES)],
            'unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'pcs'])],
            'package_quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999.999'],
            'minimum_packages' => ['required', 'integer', 'min:1', 'max:1000000'],
            'delivery_fee' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'observed_at' => ['required', 'date', 'before_or_equal:now'],
            'expires_at' => ['nullable', 'date', 'after:observed_at'],
            'source_reference' => ['required', 'string', 'max:255'],
            'source_type' => ['sometimes', Rule::in(['manual', 'supplier', 'csv', 'excel', 'api', 'ocr'])],
            'source_identifier' => ['nullable', 'string', 'max:255'],
            'task_reference' => ['nullable', 'string', 'max:255'],
            'evidence_file_id' => ['nullable', 'integer'],
            'correction_of_id' => ['nullable', 'integer'],
            'correction_reason' => ['required_with:correction_of_id', 'nullable', 'string', 'min:10', 'max:3000'],
        ];
    }
}
