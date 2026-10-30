<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountSecurity
{
    /** Version invalidation also protects non-database session backends. */
    public function revoke(User $user, string $action): void
    {
        DB::transaction(function () use ($user, $action): void {
            User::whereKey($user->id)->increment('security_version');
            $user->refresh()->forceFill(['remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            SecurityEvent::create(['user_id' => $user->id, 'action' => $action]);
        });
    }
}
