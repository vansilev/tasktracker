<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReminderSettings;
use App\Services\TaskDigestService;
use App\Services\TelegramActionService;
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

        // A pressed button. It carries no top-level "message", so it is handled before anything else.
        $callback = $request->input('callback_query');

        if (is_array($callback)) {
            $this->handleButton($request, $callback);

            return response('OK', 200);
        }

        $message = $request->input('message') ?? $request->input('edited_message');

        if (! is_array($message)) {
            return response('OK', 200);
        }

        // Only a freshly sent message may start or continue anything; an edited old one may not.
        $isNew = is_array($request->input('message'));

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
            if ($isNew) {
                $this->handleDialogText($request, $message, $text);
            }

            return response('OK', 200);
        }

        // Linking is personal: a code sent in a group would bind the whole group chat.
        if (! in_array(data_get($message, 'chat.type', 'private'), ['private', null], true)) {
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

        // Whoever held this account in Telegram before is told that it now answers elsewhere.
        if (filled($result['previous_chat_id'] ?? null) && $result['previous_chat_id'] !== (string) $chatId) {
            $this->sendTelegramMessage(
                (string) $result['previous_chat_id'],
                __('notification.telegram_relinked', [], $locale),
            );
        }

        return response('OK', 200);
    }

    /**
     * Who is on the other end — or null when the update cannot be trusted or is not personal.
     * Shared by everything that reads or changes tasks: the digest, buttons, dialogs.
     */
    private function actor(Request $request, mixed $chatId, mixed $fromId, mixed $chatType, string $what): ?User
    {
        if (! filled(config('services.telegram.webhook_secret'))) {
            Log::warning("Telegram {$what} ignored: TELEGRAM_WEBHOOK_SECRET is not set, the sender cannot be trusted.");

            return null;
        }

        $chatId = (string) $chatId;

        if ($chatType !== 'private' || $chatId === '' || $chatId !== (string) $fromId) {
            return null;
        }

        // Telegram repeats an update when the answer was slow; one update is served once.
        $updateId = $request->input('update_id');
        if ($updateId !== null && ! Cache::add('telegram-update:'.$updateId, 1, now()->addMinutes(10))) {
            return null;
        }

        $users = User::query()->where('telegram_chat_id', $chatId)->where('is_active', true)->limit(2)->get();

        if ($users->count() > 1) {
            // Two accounts on one chat: nobody can say whose action this is.
            Log::warning('Telegram '.$what.' ignored: chat …'.substr($chatId, -4).' is linked to more than one user.');

            return null;
        }

        return $users->first();
    }

    /** @param  array<string, mixed>  $callback */
    private function handleButton(Request $request, array $callback): void
    {
        $callbackId = (string) data_get($callback, 'id');
        $chatId = data_get($callback, 'message.chat.id');
        $messageId = (int) data_get($callback, 'message.message_id');
        $data = (string) data_get($callback, 'data');

        // A button on a message Telegram no longer shows us cannot be acted on.
        if ($callbackId === '' || $messageId < 1 || $data === '') {
            return;
        }

        $user = $this->actor($request, $chatId, data_get($callback, 'from.id'), data_get($callback, 'message.chat.type'), 'button');

        if ($user === null || ! Cache::add('telegram-callback:'.$callbackId, 1, now()->addMinutes(10))) {
            return;
        }

        app(TelegramActionService::class)->press($user, $data, $callbackId, (string) $chatId, $messageId);
    }

    /**
     * Plain text in the private chat: /cancel, or the answer to a question the bot has asked.
     * Forwarded messages and unknown commands are never taken as an answer.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleDialogText(Request $request, array $message, string $text): void
    {
        $cancel = $this->isCommand($text, 'cancel');

        if ((str_starts_with($text, '/') && ! $cancel) || $this->isForwarded($message)) {
            return;
        }

        $user = $this->actor($request, data_get($message, 'chat.id'), data_get($message, 'from.id'), data_get($message, 'chat.type'), 'message');

        if ($user === null) {
            return;
        }

        $chatId = (string) data_get($message, 'chat.id');
        $actions = app(TelegramActionService::class);

        if ($cancel) {
            $actions->cancel($user, $chatId);

            return;
        }

        $replyTo = data_get($message, 'reply_to_message.message_id');
        $actions->text($user, $text, $chatId, $replyTo === null ? null : (int) $replyTo);
    }

    /** @param  array<string, mixed>  $message */
    private function isForwarded(array $message): bool
    {
        foreach (['forward_origin', 'forward_from', 'forward_from_chat', 'forward_date'] as $key) {
            if (data_get($message, $key) !== null) {
                return true;
            }
        }

        return false;
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
        $chatId = (string) data_get($message, 'chat.id');
        $user = $this->actor($request, $chatId, data_get($message, 'from.id'), data_get($message, 'chat.type'), '/svodka');

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
