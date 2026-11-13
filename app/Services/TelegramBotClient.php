<?php

namespace App\Services;

use App\Models\User;
use App\Support\TelegramWidget;
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
     * An HTML widget with a caption and buttons under it. When the widget does not fit or
     * Telegram refuses it, the fallback text goes instead, so the news always arrives.
     *
     * @param  list<list<array<string, mixed>>>|null  $keyboard
     * @param  list<list<array<string, mixed>>>|null  $fallbackKeyboard
     * @return int|null id of the sent message
     */
    public function sendWidget(string $chatId, string $html, string $captionHtml, ?array $keyboard, string $fallbackHtml, ?array $fallbackKeyboard): ?int
    {
        if (! $this->mayWriteTo($chatId)) {
            return null;
        }

        if (TelegramWidget::fits($html)) {
            $result = $this->call('sendWidget', $this->withKeyboard([
                'chat_id' => $chatId,
                'html' => $html,
                'caption' => $captionHtml,
                'parse_mode' => 'HTML',
            ], $keyboard ?: null), refusal: $refused);

            if (is_array($result)) {
                return (int) ($result['message_id'] ?? 0) ?: null;
            }

            // A timeout may still have delivered it: only a plain refusal gets the text instead.
            if (! $refused) {
                return null;
            }
        }

        return $this->sendMessage($chatId, $fallbackHtml, $fallbackKeyboard);
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
     *
     * On a widget (which has no text) the buttons go; $widgetCaption, when given, becomes its
     * caption, otherwise the new content comes as a message of its own.
     */
    public function editMessage(string $chatId, int $messageId, string $html, ?array $keyboard = null, ?string $widgetCaption = null): bool
    {
        $edited = $this->call('editMessageText', $this->withKeyboard([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $keyboard ?? []), refusal: $refused, quietOn: 'no text in the message');

        if ($edited !== null || ! $refused || $this->lastDescription === null || ! str_contains($this->lastDescription, 'no text in the message')) {
            return $edited !== null;
        }

        if ($widgetCaption !== null) {
            return $this->call('editMessageCaption', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'caption' => $widgetCaption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard ?? []], JSON_UNESCAPED_UNICODE),
            ]) !== null;
        }

        // A widget has no text to replace and its HTML never changes: its buttons go, and the
        // new content arrives as a message of its own right under it.
        $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []]),
        ]);

        return $this->sendMessage($chatId, $html, $keyboard) !== null;
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
    private ?string $lastDescription = null;

    private function call(string $method, array $payload, ?bool &$refusal = null, ?string $quietOn = null): array|bool|null
    {
        $refusal = false;
        $this->lastDescription = null;
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
                $this->lastDescription = (string) $response->json('description');
                // Telegram answered: the call was refused, not lost on the way.
                $refusal = $response->status() >= 400 && $response->status() < 500;

                if ($quietOn === null || ! str_contains($this->lastDescription, $quietOn)) {
                    Log::warning("Telegram {$method} failed: ".$response->status().' '.mb_strimwidth($this->lastDescription, 0, 200, '…'));
                }

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
