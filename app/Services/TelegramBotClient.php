<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The few Bot API calls the tracker makes outside queued notifications: answering a button
 * press, editing the message it sits on, and short replies inside a dialog.
 *
 * A failed call is logged (never with the token) and reported as false: a dead Telegram must
 * not turn a webhook request into an error that Telegram would then repeat.
 */
class TelegramBotClient
{
    /**
     * @param  list<list<array{text: string, callback_data?: string, url?: string}>>|null  $keyboard
     * @return int|null id of the sent message
     */
    public function sendMessage(string $chatId, string $html, ?array $keyboard = null): ?int
    {
        if (! $this->mayWriteTo($chatId)) {
            return null;
        }

        $result = $this->call('sendMessage', $this->withKeyboard([
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $keyboard));

        return is_array($result) ? (int) ($result['message_id'] ?? 0) ?: null : null;
    }

    /**
     * A question the person answers by replying: Telegram opens the reply box for them.
     *
     * @return int|null id of the question, to match the answer against
     */
    public function sendPrompt(string $chatId, string $html): ?int
    {
        if (! $this->mayWriteTo($chatId)) {
            return null;
        }

        $result = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode(['force_reply' => true]),
        ]);

        return is_array($result) ? (int) ($result['message_id'] ?? 0) ?: null : null;
    }

    /**
     * Replaces the text and the buttons of a message. Passing no keyboard removes the buttons.
     *
     * @param  list<list<array{text: string, callback_data?: string, url?: string}>>|null  $keyboard
     */
    public function editMessage(string $chatId, int $messageId, string $html, ?array $keyboard = null): bool
    {
        return $this->call('editMessageText', $this->withKeyboard([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $keyboard ?? [])) !== null;
    }

    /** Stops the "loading" spinner on the pressed button; $text shows as a small toast. */
    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): bool
    {
        return $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => mb_strimwidth($text, 0, 190, '…'),
            'show_alert' => $alert ?: null,
        ], fn ($value) => $value !== null && $value !== '')) !== null;
    }

    /**
     * The last gate of the pilot run: while a pilot list is set, a new message goes only to a
     * chat of somebody on it — whatever part of the tracker asked for it.
     */
    private function mayWriteTo(string $chatId): bool
    {
        $pilot = app(ReminderSettings::class)->pilotUserIds();

        if ($pilot === []) {
            return true;
        }

        $allowed = User::query()->whereIn('id', $pilot)->where('telegram_chat_id', $chatId)->exists();

        if (! $allowed) {
            Log::info('Telegram message held back by the pilot list: chat …'.substr($chatId, -4));
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<list<array<string, string>>>|null  $keyboard
     * @return array<string, mixed>
     */
    private function withKeyboard(array $payload, ?array $keyboard): array
    {
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|true|null null = the call failed
     */
    private function call(string $method, array $payload): array|bool|null
    {
        $token = config('services.telegram.token');

        if (! filled($token)) {
            Log::warning("Telegram {$method} skipped: TELEGRAM_BOT_TOKEN is not set.");

            return null;
        }

        try {
            $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

            // Editing a message to the very same content is refused by Telegram, but nothing is wrong.
            if ($method === 'editMessageText' && str_contains((string) $response->json('description'), 'message is not modified')) {
                return true;
            }

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning("Telegram {$method} failed: ".$response->status().' '.mb_strimwidth((string) $response->json('description'), 0, 200, '…'));

                return null;
            }

            return $response->json('result') ?? true;
        } catch (Throwable $e) {
            // The message of an HTTP client error quotes the URL, and the URL carries the token.
            Log::warning("Telegram {$method} failed: ".preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $e->getMessage()));

            return null;
        }
    }
}
