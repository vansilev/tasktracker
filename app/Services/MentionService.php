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
        if (! preg_match_all('/@([\p{L}\p{N}._-]+)/u', $body, $matches)) {
            return collect();
        }

        $tokens = array_unique($matches[1]);

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
