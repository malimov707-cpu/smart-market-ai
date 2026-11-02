<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin Telegram Bot API client. Never throws to the caller of send():
 * failures are logged with an honest status so briefing loops continue.
 */
class TelegramService
{
    public function configured(): bool
    {
        return (bool) config('services.telegram.bot_token');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout = 25): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN sozlanmagan.');
        }

        $response = Http::acceptJson()->connectTimeout(3)->timeout($timeout + 10)
            ->post($this->url('getUpdates'), [
                'offset' => $offset,
                'timeout' => $timeout,
                'allowed_updates' => ['message'],
            ]);

        if ($response->status() === 409) {
            throw new RuntimeException('Polling ishlashi uchun avval Telegram webhook’ni o‘chiring.');
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram getUpdates so‘rovi bajarilmadi (HTTP '.$response->status().').');
        }

        $updates = $response->json('result');
        if (! is_array($updates) || ! array_is_list($updates)) {
            throw new RuntimeException('Telegram getUpdates noto‘g‘ri javob qaytardi.');
        }
        foreach ($updates as $update) {
            if (! is_array($update) || ! is_int($update['update_id'] ?? null)) {
                throw new RuntimeException('Telegram update formati noto‘g‘ri.');
            }
        }

        return $updates;
    }

    public function send(User $user, string $text): bool
    {
        $chatId = $user->telegram_chat_id;
        if (! $this->configured() || ! is_string($chatId) || $chatId === '') {
            return false;
        }
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->post($this->url('sendMessage'), ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000),
                    'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning('telegram.send_failed', ['user_id' => $user->id, 'status' => $response->status()]);

                return false;
            }

            return true;
        } catch (ConnectionException|RuntimeException $error) {
            Log::warning('telegram.send_failed', ['user_id' => $user->id, 'exception' => $error::class]);

            return false;
        }
    }

    /**
     * Handle a verified webhook update. Returns true when processed.
     */
    public function handleUpdate(array $update): bool
    {
        $chatId = $update['message']['chat']['id'] ?? null;
        $text = trim((string) ($update['message']['text'] ?? ''));
        if (! is_int($chatId) && ! is_string($chatId)) {
            return false;
        }
        [$command, $payload] = $this->parseCommand($text);
        if ($command === 'start') {
            $token = $this->validLinkToken($payload);
            if ($token === null) {
                $this->reply($chatId, "Assalomu alaykum! 👋\n\nHisobingizni Smart Market AI > Hisob sahifasidagi Telegram havolasi orqali ulang.\n\nBuyruqlar: /help");

                return true;
            }
            $linked = User::where('telegram_link_token', $token)->where('is_active', true)->where('is_suspended', false)
                ->update(['telegram_chat_id' => (string) $chatId, 'telegram_link_token' => null]);
            if ($linked !== 1) {
                $this->reply($chatId, 'Bu havola eskirgan yoki noto‘g‘ri. Hisob sahifasidan yangi havola oling.');

                return true;
            }
            $this->reply($chatId, "✅ Ulandi!\n\nHar kuni ertalab Toshkent narxlari bo‘yicha qisqa briefing olasiz. Kuzatayotgan mahsulotlaringiz narxi keskin o‘zgarsa, alohida xabar keladi.");

            return true;
        }
        if ($command === 'help') {
            $this->reply($chatId, "<b>Smart Market AI bot buyruqlari</b>\n/brief — bozor narxlari xulosasi\n/status — ulanish va briefing holati\n/stop — Telegram briefingni to‘xtatish\n\nHisobni ulash yoki qayta ulash uchun saytning Hisob sahifasidagi Telegram havolasini oching.");

            return true;
        }
        if ($command === 'brief') {
            $user = $this->linkedActiveUser((string) $chatId);
            if (! $user) {
                $this->reply($chatId, 'Avval hisobingizni ulang: Smart Market AI > Hisob sahifasidagi havola.');

                return true;
            }
            $briefing = app(MarketBriefing::class)->forUser($user);
            $this->reply($chatId, $briefing);

            return true;
        }
        if ($command === 'status') {
            $user = $this->linkedActiveUser((string) $chatId);
            $status = $user
                ? ($user->telegram_briefing ? 'Yoqilgan' : 'O‘chirilgan')
                : 'Hisob ulanmagan';
            $this->reply($chatId, "<b>Ulanish holati:</b> {$status}\n".($user ? 'Kunlik briefingni saytdagi Hisob sahifasidan boshqaring.' : 'Hisobni ulash uchun /help yuboring.'));

            return true;
        }
        if ($command === 'stop') {
            User::where('telegram_chat_id', (string) $chatId)->update(['telegram_chat_id' => null]);
            $this->reply($chatId, 'Telegram briefing to‘xtatildi. Qayta ulash uchun saytning Hisob sahifasidan yangi Telegram havolasini oling.');

            return true;
        }
        if (str_starts_with($text, '/')) {
            $this->reply($chatId, 'Bu buyruqni tushunmadim. Mavjud buyruqlar: /help');

            return true;
        }

        return false;
    }

    private function reply(int|string $chatId, string $text): void
    {
        if (! $this->configured()) {
            return;
        }
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->post($this->url('sendMessage'),
                ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning('telegram.reply_failed', ['status' => $response->status()]);
            }
        } catch (ConnectionException|RuntimeException $error) {
            Log::warning('telegram.reply_failed', ['exception' => $error::class]);
        }
    }

    private function linkedActiveUser(string $chatId): ?User
    {
        return User::where('telegram_chat_id', $chatId)->where('is_active', true)->where('is_suspended', false)->first();
    }

    /**
     * @return array{string, string}
     */
    private function parseCommand(string $text): array
    {
        if (in_array(mb_strtolower($text), ['brief', 'stop'], true)) {
            return [mb_strtolower($text), ''];
        }
        if (preg_match('/^\/([a-z0-9_]+)(?:@([a-z0-9_]+))?(?:\s+(.+))?$/i', $text, $matches) !== 1) {
            return ['', ''];
        }
        $target = $matches[2] ?? '';
        $username = ltrim((string) config('services.telegram.username'), '@');
        if ($target !== '' && strcasecmp($target, $username) !== 0) {
            return ['', ''];
        }

        return [mb_strtolower($matches[1]), trim($matches[3] ?? '')];
    }

    private function validLinkToken(string $payload): ?string
    {
        $token = trim($payload);

        return preg_match('/^[a-zA-Z0-9]{20,64}$/', $token) === 1 ? $token : null;
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }
}
