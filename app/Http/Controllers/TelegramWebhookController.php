<?php

namespace App\Http\Controllers;

use App\Services\TelegramLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramLinkService $linkService): Response
    {
        $secret = config('services.telegram.webhook_secret');

        if (filled($secret)) {
            $provided = $request->header('X-Telegram-Bot-Api-Secret-Token');

            if (! hash_equals((string) $secret, (string) $provided)) {
                return response('Forbidden', 403);
            }
        }

        $message = $request->input('message') ?? $request->input('edited_message');

        if (! is_array($message)) {
            return response('OK', 200);
        }

        $chatId = data_get($message, 'chat.id');
        $text = trim((string) data_get($message, 'text', ''));

        if ($chatId === null || $text === '') {
            return response('OK', 200);
        }

        if (! str_starts_with($text, '/start')) {
            return response('OK', 200);
        }

        $payload = trim(substr($text, strlen('/start')));
        // Telegram deep-link payload may include bot username suffix after @
        $payload = preg_replace('/@.+$/', '', $payload) ?? $payload;
        $payload = trim($payload);

        $result = $linkService->consumeStartPayload((string) $chatId, $payload !== '' ? $payload : null);
        $locale = $result['user']->locale ?? config('app.locale');
        $reply = __($result['message_key'], [], $locale);

        $this->sendTelegramMessage((string) $chatId, $reply);

        return response('OK', 200);
    }

    private function sendTelegramMessage(string $chatId, string $text): void
    {
        $token = config('services.telegram.token');

        if (! filled($token)) {
            Log::warning('Telegram webhook reply skipped: TELEGRAM_BOT_TOKEN is not set.');

            return;
        }

        try {
            Http::timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram webhook reply failed: '.$e->getMessage());
        }
    }
}
