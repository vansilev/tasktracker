<?php

namespace App\Services;

use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public const CODE_TTL_MINUTES = 30;

    public function createLinkCode(User $user): TelegramLinkCode
    {
        TelegramLinkCode::query()->where('user_id', $user->id)->delete();

        return TelegramLinkCode::query()->create([
            'user_id' => $user->id,
            'code' => Str::lower(Str::random(32)),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);
    }

    public function deepLinkUrl(string $code): ?string
    {
        $username = config('services.telegram.bot_username');

        if (! filled($username)) {
            return null;
        }

        $username = ltrim((string) $username, '@');

        return "https://t.me/{$username}?start={$code}";
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user) {
            TelegramLinkCode::query()->where('user_id', $user->id)->delete();
            $user->update([
                'telegram_chat_id' => null,
                'telegram_username' => null,
            ]);
        });
    }

    /**
     * @return array{ok: bool, message_key: string, user?: User}
     */
    public function consumeStartPayload(string $chatId, ?string $payload, ?string $username = null): array
    {
        $chatId = trim($chatId);

        if ($chatId === '') {
            return ['ok' => false, 'message_key' => 'notification.telegram_link_invalid'];
        }

        $code = trim((string) $payload);

        if ($code === '') {
            return ['ok' => false, 'message_key' => 'notification.telegram_link_need_code'];
        }

        $normalizedUsername = $this->normalizeUsername($username);

        /** @var TelegramLinkCode|null $link */
        $link = TelegramLinkCode::query()
            ->with('user')
            ->where('code', $code)
            ->first();

        if (! $link || $link->isExpired() || ! $link->user) {
            return ['ok' => false, 'message_key' => 'notification.telegram_link_invalid'];
        }

        if (! $link->user->is_active) {
            return ['ok' => false, 'message_key' => 'notification.telegram_link_invalid'];
        }

        DB::transaction(function () use ($link, $chatId, $normalizedUsername) {
            User::query()
                ->where('telegram_chat_id', $chatId)
                ->where('id', '!=', $link->user_id)
                ->update([
                    'telegram_chat_id' => null,
                    'telegram_username' => null,
                ]);

            $link->user->update([
                'telegram_chat_id' => $chatId,
                'telegram_username' => $normalizedUsername,
            ]);
            TelegramLinkCode::query()->where('user_id', $link->user_id)->delete();
        });

        return [
            'ok' => true,
            'message_key' => 'notification.telegram_link_success',
            'user' => $link->user->fresh(),
        ];
    }

    private function normalizeUsername(?string $username): ?string
    {
        $username = ltrim(trim((string) $username), '@');

        if ($username === '' || preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) !== 1) {
            return null;
        }

        return $username;
    }
}
