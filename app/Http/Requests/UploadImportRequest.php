<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->input('type') === 'offers' ? 'manage' : 'catalog', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['products', 'suppliers', 'offers'])],
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx,pdf,png,jpg,jpeg'],
            'timezone' => ['required', Rule::in(['UTC', 'Asia/Tashkent'])],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'idempotency_key' => ['nullable', 'regex:/^[a-f0-9]{64}$/']];
    }
}
