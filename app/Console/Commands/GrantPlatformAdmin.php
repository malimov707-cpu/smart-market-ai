<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'admin:grant {email : Account email} {--create : Create a new verified admin account with a random initial password}';

    protected $description = 'Grant platform administrator access to an existing verified active account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->first();
        if ($this->option('create')) {
            if ($user) {
                $this->error('Account already exists. Omit --create to grant access without changing its password.');

                return self::FAILURE;
            }
            $password = Str::password(24);
            $user = DB::transaction(function () use ($email, $password): User {
                $user = new User;
                $user->forceFill(['name' => 'Platform administrator', 'email' => $email, 'password' => $password,
                    'email_verified_at' => now(), 'is_platform_admin' => true])->save();
                SecurityEvent::create(['user_id' => $user->id, 'action' => 'admin.created.cli']);

                return $user;
            });
            $this->line('Initial password: '.$password);
        }
        if (! $user || ! $user->hasVerifiedEmail() || ! $user->is_active || $user->is_suspended) {
            $this->error('A verified, active, non-suspended account with this email is required.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_platform_admin' => true])->save();
            SecurityEvent::create(['user_id' => $user->id, 'action' => 'admin.granted.cli']);
        });
        $this->info('Platform administrator access granted. Sign in at /admin.');

        return self::SUCCESS;
    }
}
