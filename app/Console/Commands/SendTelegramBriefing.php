<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MarketBriefing;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendTelegramBriefing extends Command
{
    protected $signature = 'telegram:briefing {--user= : Only this user id}';

    protected $description = 'Send the daily Telegram market briefing to linked users';

    public function handle(MarketBriefing $briefing, TelegramService $telegram): int
    {
        if (! $telegram->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN sozlanmagan.');

            return self::FAILURE;
        }
        $users = User::whereNotNull('telegram_chat_id')->where('telegram_briefing', true)->where('is_active', true)->where('is_suspended', false)
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey((int) $id))
            ->get();
        $sent = 0;
        $failed = 0;
        foreach ($users as $user) {
            if ($telegram->send($user, $briefing->forUser($user))) {
                $sent++;
            } else {
                $failed++;
            }
        }
        $this->info("Briefing yuborildi: {$sent}/{$users->count()} foydalanuvchi; muvaffaqiyatsiz: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
