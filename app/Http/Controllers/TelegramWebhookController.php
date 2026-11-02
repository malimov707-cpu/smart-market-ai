<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function store(Request $request, TelegramService $telegram, string $secret): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret');
        abort_if($expected === '' || ! hash_equals($expected, $secret), 404);
        abort_unless($telegram->configured(), 503);

        $update = $request->json()->all();
        if (! is_array($update) || $update === []) {
            return response()->json(['ok' => true]);
        }
        $handled = $telegram->handleUpdate($update);
        if ($handled && is_scalar($update['message']['from']['id'] ?? null)) {
            Log::info('telegram.update.handled', ['telegram_user' => (string) $update['message']['from']['id']]);
        }

        return response()->json(['ok' => true]);
    }
}
