<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\CommentNotice;
use App\Models\User;
use App\Services\ReminderSettings;
use App\Services\TelegramActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The small window Telegram opens over the chat (a Mini App): a reply, a comment, a new
 * deadline, a reason. Task 448.
 *
 * The page itself is public and holds nothing: it asks for its data with the "initData" that
 * Telegram signs with the bot's token. Only a valid signature, fresh enough, from a private
 * chat linked to exactly one active person, opens anything — and then every action is that
 * person's, checked as in the web interface.
 */
class TelegramAppController extends Controller
{
    /** How old the signed data may be: the window is opened from a message, used at once. */
    private const MAX_AGE_SECONDS = 86400;

    private const MODES = ['reply', 'comment', 'deadline', 'reject', 'status'];

    public function page(): View
    {
        return view('telegram-app');
    }

    public function context(Request $request, TelegramActionService $actions): JsonResponse
    {
        $user = $this->user($request);
        $mode = is_string($request->input('m')) ? $request->input('m') : '';

        if ($user === null) {
            return $this->fail('auth', 401);
        }

        app()->setLocale($user->preferredLocale());

        if (! in_array($mode, self::MODES, true)) {
            return $this->fail(__('notification.bot.unknown'));
        }

        $context = $actions->appContext($user, $mode, $this->ids($request));

        return $context === null
            ? $this->fail(__('notification.bot.not_available'))
            : response()->json(['ok' => true, 'user' => $user->name, 'i18n' => __('telegram_app')] + $context);
    }

    public function submit(Request $request, TelegramActionService $actions): JsonResponse
    {
        $user = $this->user($request);

        if ($user === null) {
            return $this->fail('auth', 401);
        }

        app()->setLocale($user->preferredLocale());

        // A double tap on "Send" is one action.
        $key = 'telegram-app-submit:'.$user->id.':'.sha1((string) $request->getContent());

        if (! Cache::add($key, 1, now()->addSeconds(30))) {
            return $this->fail(__('notification.bot.app_already_sent'));
        }

        $ids = $this->ids($request);
        $text = is_string($request->input('text')) ? mb_substr(trim($request->input('text')), 0, 4000) : '';
        $chatId = (string) $user->telegram_chat_id;

        [$ok, $message] = match (is_string($request->input('m')) ? $request->input('m') : '') {
            'reply', 'comment' => $actions->appComment($user, $this->taskOfReply($user, $ids), $text),
            'status' => $actions->appStatus($user, $ids['task'] ?? 0, TaskStatus::tryFrom(is_string($request->input('status')) ? $request->input('status') : ''), $text, is_string($request->input('date')) ? $request->input('date') : null, $chatId),
            'deadline' => $actions->appDeadline($user, $ids['task'] ?? 0, is_string($request->input('date')) ? $request->input('date') : '', $text, $chatId),
            'reject' => $actions->appReject($user, $ids['req'] ?? 0, $text, $chatId),
            default => [false, __('notification.bot.unknown')],
        };

        if (! $ok) {
            Cache::forget($key);
        }

        return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
    }

    /** For a reply the task comes from the person's own notice, never from the page. */
    private function taskOfReply(User $user, array $ids): int
    {
        if (! isset($ids['notice'])) {
            return $ids['task'] ?? 0;
        }

        return (int) CommentNotice::query()->whereKey($ids['notice'])->where('user_id', $user->id)->value('task_id');
    }

    /** @return array<string, int> */
    private function ids(Request $request): array
    {
        $ids = [];

        foreach (['task', 'notice', 'req'] as $name) {
            $value = $request->input($name);

            if (is_numeric($value) && (int) $value > 0) {
                $ids[$name] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * Who opened the window, from Telegram's signed initData (core.telegram.org/bots/webapps,
     * "Validating data received via the Mini App").
     */
    private function user(Request $request): ?User
    {
        $token = (string) config('services.telegram.token');
        $initData = $request->input('initData');

        if (! is_string($initData)) {
            return null;
        }

        if ($token === '' || $initData === '' || strlen($initData) > 4096) {
            return null;
        }

        parse_str($initData, $fields);

        // Telegram sends flat key=value pairs only; anything else is not its data.
        foreach ($fields as $value) {
            if (! is_string($value)) {
                return null;
            }
        }

        $hash = (string) ($fields['hash'] ?? '');
        unset($fields['hash']);
        ksort($fields);

        $check = implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);

        if ($hash === '' || ! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            Log::info('Telegram app: initData with a bad signature.');

            return null;
        }

        if (abs(now()->timestamp - (int) ($fields['auth_date'] ?? 0)) > self::MAX_AGE_SECONDS) {
            return null;
        }

        $telegramId = data_get(json_decode((string) ($fields['user'] ?? ''), true), 'id');

        if (! is_numeric($telegramId)) {
            return null;
        }

        // In a private chat with the bot the chat id is the person's id.
        $users = User::query()->where('telegram_chat_id', (string) $telegramId)->where('is_active', true)->limit(2)->get();

        if ($users->count() !== 1 || ! app(ReminderSettings::class)->allowsPersonalMessages($users->first()->id)) {
            return null;
        }

        return $users->first();
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }
}
