<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class PollTelegramUpdates extends Command
{
    protected $signature = 'telegram:poll {--once : Process queued updates once and exit}';

    protected $description = 'Receive Telegram bot updates locally without a public webhook';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN sozlanmagan.');

            return self::FAILURE;
        }

        $offset = 0;
        $once = (bool) $this->option('once');
        $this->info($once ? 'Telegram update’lari tekshirilmoqda.' : 'Telegram polling boshlandi. To‘xtatish uchun Ctrl+C bosing.');

        do {
            try {
                $updates = $telegram->getUpdates($offset, $once ? 0 : 25);
            } catch (ConnectionException $error) {
                if ($once) {
                    $this->error('Telegram API bilan aloqa uzildi. Token xavfsizligi uchun texnik tafsilotlar yashirildi.');

                    return self::FAILURE;
                }

                $this->warn('Telegram API bilan aloqa uzildi. 5 soniyadan keyin qayta uriniladi.');
                sleep(5);

                continue;
            } catch (RuntimeException $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            }

            foreach ($updates as $update) {
                $telegram->handleUpdate($update);
                $offset = $update['update_id'] + 1;
            }
        } while (! $once || $updates !== []);

        return self::SUCCESS;
    }
}
