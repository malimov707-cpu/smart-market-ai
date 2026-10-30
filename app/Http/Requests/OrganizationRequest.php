<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('administer', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        $roles = ['owner', 'manager', 'buyer', 'analyst', 'auditor'];

        return match ($this->route()->getName()) {
            'settings.update' => ['name' => ['required', 'string', 'max:180'], 'city' => ['required', 'string', 'max:100']],
            'invitations.store' => ['email' => ['required', 'email', 'max:254'], 'role' => ['required', Rule::in($roles)]],
            'members.update' => ['role' => ['required', Rule::in($roles)]],
            default => [],
        };
    }
}
