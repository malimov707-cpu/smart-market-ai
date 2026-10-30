<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AccountMail
{
    public static function available(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true)
            || (app()->environment('testing') && config('mail.default') === 'array');
    }

    public static function ensureAvailable(): void
    {
        if (! self::available()) {
            throw ValidationException::withMessages(['email' => 'Xavfsiz email transporti sozlanmagan. Administrator mail xizmatini sozlashi kerak; maxfiy havolalar logga yozilmaydi.']);
        }
    }
}
