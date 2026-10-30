<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($id = $this->route('supplier')) {
            Supplier::where('organization_id', $this->attributes->get('organization')->id)->findOrFail($id);
        }

        return $this->user()->can('catalog', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:180'], 'contact' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:254'], 'description' => ['nullable', 'string', 'max:5000']];
    }
}
