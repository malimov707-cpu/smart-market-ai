<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class CatalogFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('product') ? Product::class : Supplier::class;
        $record = $model::where('organization_id', $this->attributes->get('organization')->id)->findOrFail($this->route('product') ?? $this->route('supplier'));

        return $this->user()->can('update', $record);
    }

    public function rules(): array
    {
        $rules = $this->route('product') ? ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'] :
            ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp', 'max:5120'];

        return ['file' => $rules, 'replace_id' => ['nullable', 'integer']];
    }
}
