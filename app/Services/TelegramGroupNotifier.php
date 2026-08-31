<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\SendTelegramGroupMessage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class TelegramGroupNotifier
{
    public function __construct(private TelegramGroupMessageBuilder $builder) {}

    public function notifyCreated(Task $task, User $actor): void
    {
        $this->dispatch($this->builder->forCreated($task, $actor));
    }

    public function notifyReassigned(Task $task, User $actor): void
    {
        $this->dispatch($this->builder->forReassigned($task, $actor));
    }

    public function notifyStatusChanged(
        Task $task,
        User $actor,
        TaskStatus $from,
        TaskStatus $to,
        ?string $reasonExcerpt = null,
    ): void {
        $this->dispatch($this->builder->forStatusChanged($task, $actor, $from, $to, $reasonExcerpt));
    }

    /**
     * @param  Collection<int, User>  $mentioned
     * @param  Collection<int, User>  $repliedTo
     */
    public function notifyCommented(
        Task $task,
        User $actor,
        string $excerpt,
        Collection $mentioned,
        Collection $repliedTo,
    ): void {
        $this->dispatch($this->builder->forCommented($task, $actor, $excerpt, $mentioned, $repliedTo));
    }

    public function notifyReacted(
        Task $task,
        User $actor,
        ?User $author,
        string $excerpt,
        string $emoji,
    ): void {
        if ($author === null || $author->id === $actor->id || ! $author->is_active) {
            return;
        }

        $this->dispatch($this->builder->forReacted($task, $actor, $author, $excerpt, $emoji));
    }

    public function isReady(): bool
    {
        return (bool) config('services.telegram.group_enabled')
            && filled(config('services.telegram.token'))
            && filled(config('services.telegram.group_chat_id'))
            && filled(config('services.telegram.group_message_thread_id'));
    }

    private function dispatch(string $html): void
    {
        if (! $this->isReady() || trim($html) === '') {
            return;
        }

        SendTelegramGroupMessage::dispatch($html);
    }
}
