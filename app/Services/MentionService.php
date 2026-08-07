<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Collection;

class MentionService
{
    /**
     * Parse @mentions from comment body, persist links, and add mentioned users as watchers.
     *
     * @return Collection<int, User>
     */
    public function processCommentMentions(Task $task, TaskComment $comment): Collection
    {
        $mentioned = $this->parseMentionedUsers($comment->body);

        if ($mentioned->isEmpty()) {
            return $mentioned;
        }

        $comment->mentionedUsers()->syncWithoutDetaching($mentioned->pluck('id')->all());
        $task->watchers()->syncWithoutDetaching($mentioned->pluck('id')->all());

        return $mentioned;
    }

    /** @return Collection<int, User> */
    public function parseMentionedUsers(string $body): Collection
    {
        $tokens = $this->extractMentionTokens($body);

        if ($tokens === []) {
            return collect();
        }

        if ($this->usesSqlite()) {
            return User::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user) => $this->userMatchesAnyToken($user, $tokens))
                ->values();
        }

        return User::query()
            ->where('is_active', true)
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $lowerToken = mb_strtolower($token);
                    $q->orWhere('email', 'like', $token.'@%')
                        ->orWhere('email', $token)
                        ->orWhereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [$lowerToken])
                        ->orWhereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [mb_strtolower('@'.$token)]);
                }
            })
            ->get();
    }

    /**
     * Pull mention tokens from visible text only.
     *
     * Tags (and their attributes, including mailto: hrefs) are stripped first so
     * autolinked emails cannot yield phantom tokens. A lookbehind then ignores
     * `@` that sits inside an email local-part (letter/digit/._- immediately before).
     *
     * @return list<string>
     */
    private function extractMentionTokens(string $body): array
    {
        $text = $this->visibleText($body);

        if ($text === '' || ! preg_match_all('/(?<![\p{L}\p{N}._-])@([\p{L}\p{N}._-]+)/u', $text, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    private function visibleText(string $body): string
    {
        // Example snippets in code/pre should not notify anyone.
        $text = preg_replace('#<(code|pre)\b[^>]*>.*?</\1>#isu', ' ', $body) ?? $body;

        // TipTap emits compact block HTML without newlines. strip_tags alone
        // would turn <p>Hi</p><p>@Alice</p> into "Hi@Alice" and the lookbehind
        // would then miss the mention.
        $text = preg_replace(
            '#</?(?:p|br|li|ul|ol|tr|td|th|table|thead|tbody|h[1-6]|blockquote|div|hr)(?:\s[^>]*)?/?>#iu',
            ' ',
            $text,
        ) ?? $text;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /** @return list<array{id: int, name: string, email: string, token: string}> */
    public function searchMentionableUsers(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        if ($this->usesSqlite()) {
            return User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->filter(fn (User $user) => mb_stripos($user->name, $term) !== false
                    || str_starts_with(mb_strtolower($user->email), mb_strtolower($term)))
                ->take(8)
                ->map(fn (User $user) => $this->mentionSuggestionFromUser($user))
                ->values()
                ->all();
        }

        return User::query()
            ->where('is_active', true)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', $term.'%');
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => $this->mentionSuggestionFromUser($user))
            ->values()
            ->all();
    }

    /** @param  list<string>  $tokens */
    private function userMatchesAnyToken(User $user, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->emailMatchesToken($user->email, $token) || $this->nameMatchesToken($user->name, $token)) {
                return true;
            }
        }

        return false;
    }

    private function emailMatchesToken(string $email, string $token): bool
    {
        return str_starts_with($email, $token.'@') || $email === $token;
    }

    private function nameMatchesToken(string $name, string $token): bool
    {
        $collapsed = mb_strtolower(str_replace(' ', '', $name));
        $lowerToken = mb_strtolower($token);

        return $collapsed === $lowerToken || $collapsed === mb_strtolower('@'.$token);
    }

    /** @return array{id: int, name: string, email: string, token: string} */
    private function mentionSuggestionFromUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'token' => str_replace(' ', '', $user->name),
        ];
    }

    private function usesSqlite(): bool
    {
        return User::query()->getConnection()->getDriverName() === 'sqlite';
    }
}
