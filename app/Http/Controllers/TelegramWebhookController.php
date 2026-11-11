<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReminderSettings;
use App\Services\TaskDigestService;
use App\Services\TelegramLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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

        if ($this->isCommand($text, 'svodka')) {
            // An edited old message must not ask for the digest again.
            if (is_array($request->input('message'))) {
                $this->handleDigestRequest($request, $message);
            }

            return response('OK', 200);
        }

        if (! str_starts_with($text, '/start')) {
            return response('OK', 200);
        }

        $payload = trim(substr($text, strlen('/start')));
        // Telegram deep-link payload may include bot username suffix after @
        $payload = preg_replace('/@.+$/', '', $payload) ?? $payload;
        $payload = trim($payload);

        $fromUsername = data_get($message, 'from.username');
        $result = $linkService->consumeStartPayload(
            (string) $chatId,
            $payload !== '' ? $payload : null,
            is_string($fromUsername) ? $fromUsername : null,
        );
        $locale = $result['user']->locale ?? config('app.locale');
        $reply = __($result['message_key'], [], $locale);

        $this->sendTelegramMessage((string) $chatId, $reply);

        return response('OK', 200);
    }

    /** Exactly "/name" or "/name@this_bot", optionally followed by arguments. */
    private function isCommand(string $text, string $name): bool
    {
        if (preg_match('/^\/'.preg_quote($name, '/').'(?:@([A-Za-z0-9_]+))?(?:\s|$)/u', $text, $match) !== 1) {
            return false;
        }

        $addressedTo = $match[1] ?? '';
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        return $addressedTo === '' || ($bot !== '' && strcasecmp($addressedTo, $bot) === 0);
    }

    /**
     * /svodka — the task digest on request. It carries every open task of the company, so it
     * answers only in a private chat, only to an active digest recipient or administrator, and
     * only when the webhook is protected by its secret. Strangers get no answer at all.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleDigestRequest(Request $request, array $message): void
    {
        if (! filled(config('services.telegram.webhook_secret'))) {
            Log::warning('Telegram /svodka ignored: TELEGRAM_WEBHOOK_SECRET is not set, the sender cannot be trusted.');

            return;
        }

        $chatId = (string) data_get($message, 'chat.id');
        $fromId = (string) data_get($message, 'from.id');

        if (data_get($message, 'chat.type') !== 'private' || $chatId === '' || $chatId !== $fromId) {
            return;
        }

        // Telegram repeats an update when the answer was slow; one update is served once.
        $updateId = $request->input('update_id');
        if ($updateId !== null && ! Cache::add('telegram-update:'.$updateId, 1, now()->addMinutes(10))) {
            return;
        }

        $user = User::query()->where('telegram_chat_id', $chatId)->where('is_active', true)->first();

        if ($user === null) {
            return;
        }

        $locale = $user->preferredLocale();

        if (! $user->isAdmin() && ! in_array($user->id, app(ReminderSettings::class)->digestRecipientIds(), true)) {
            $this->sendTelegramMessage($chatId, __('notification.digest.not_allowed', [], $locale));

            return;
        }

        if (! Cache::add('telegram-svodka:'.$user->id, 1, now()->addMinute())) {
            $this->sendTelegramMessage($chatId, __('notification.digest.too_often', [], $locale));

            return;
        }

        app(TaskDigestService::class)->sendOnDemand($user);
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
