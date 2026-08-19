<?php

namespace App\Services;

use App\Models\User;

class TelegramMentionFormatter
{
    public function mention(User $user): string
    {
        $name = $this->escape($user->name);

        $chatId = trim((string) ($user->telegram_chat_id ?? ''));

        if ($chatId !== '' && preg_match('/^\d+$/', $chatId) === 1) {
            return '<a href="tg://user?id='.$this->escape($chatId).'">'.$name.'</a>';
        }

        $username = ltrim(trim((string) ($user->telegram_username ?? '')), '@');

        if ($username !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) === 1) {
            return '@'.$this->escape($username);
        }

        return $name;
    }

    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  iterable<int, User>  $users
     */
    public function mentions(iterable $users): string
    {
        $parts = [];

        foreach ($users as $user) {
            $parts[] = $this->mention($user);
        }

        return implode(' ', $parts);
    }
}
