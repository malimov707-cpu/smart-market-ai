<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'account.profile' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($this->user()->id)],
                'locale' => ['required', Rule::in(['uz', 'ru', 'en'])],
                'current_password' => $this->user()->telegram_login_id !== null
                    && str_ends_with($this->user()->email, '@login.smart-market.invalid')
                    ? ['nullable']
                    : ['required', 'current_password'],
            ],
            'account.password' => [
                'current_password' => ['required', 'current_password'],
                'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()],
            ],
            default => ['current_password' => ['required', 'current_password']],
        };
    }
}
