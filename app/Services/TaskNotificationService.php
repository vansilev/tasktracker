<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentedNotification;
use App\Notifications\TaskDeadlineApproachingNotification;
use App\Notifications\TaskMentionedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReviewSlaExpiredNotification;
use App\Notifications\TaskStatusChangedNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class TaskNotificationService
{
    /** @var list<string> */
    public const PREFERENCE_CHANNELS = ['database', 'email', 'telegram'];

    public function notifyTaskCreated(Task $task, User $actor): void
    {
        $task->loadMissing(['assignee', 'watchers', 'department.head']);

        $recipients = collect([$task->assignee]);

        $head = $task->department?->head;
        if ($head && $head->id !== $task->assignee_id && $head->id !== $task->initiator_id) {
            $recipients->push($head);
        }

        $recipients = $recipients->merge($task->watchers);

        $this->sendTaskAssigned($task, $actor, $recipients);
    }

    public function notifyTaskReassigned(Task $task, User $actor): void
    {
        $task->loadMissing(['assignee', 'watchers']);

        $recipients = collect([$task->assignee])->merge($task->watchers);

        $this->sendTaskAssigned($task, $actor, $recipients);
    }

    public function notifyStatusChanged(Task $task, User $actor, TaskStatus $oldStatus, TaskStatus $newStatus): void
    {
        $task->loadMissing(['initiator', 'assignee', 'watchers']);

        $recipients = collect([$task->initiator, $task->assignee])->merge($task->watchers);

        $this->sendTaskStatusChanged($task, $actor, $oldStatus, $newStatus, $recipients);
    }

    /**
     * @param  Collection<int, User>  $mentioned
     */
    public function notifyComment(Task $task, User $actor, TaskComment $comment, Collection $mentioned): void
    {
        $task->loadMissing(['initiator', 'assignee', 'watchers']);

        $mentionedIds = $mentioned->pluck('id')->all();
        $excerpt = $this->commentExcerpt($comment->body);

        $commentRecipients = collect([$task->initiator, $task->assignee])
            ->merge($task->watchers)
            ->reject(fn (User $user) => in_array($user->id, $mentionedIds, true));

        $this->sendTaskMentioned($task, $actor, $excerpt, $mentioned);
        $this->sendTaskCommented($task, $actor, $excerpt, $commentRecipients);
    }

    public function notifyDeadlineApproaching(Task $task): void
    {
        $task->loadMissing(['initiator', 'assignee']);

        $recipients = collect([$task->assignee, $task->initiator]);

        $this->sendToUsers(
            $recipients,
            null,
            'task.deadline_approaching',
            fn () => new TaskDeadlineApproachingNotification($task),
        );
    }

    public function notifyOverdue(Task $task): void
    {
        $task->loadMissing(['initiator', 'assignee']);

        $recipients = collect([$task->assignee, $task->initiator]);

        $this->sendToUsers(
            $recipients,
            null,
            'task.overdue',
            fn () => new TaskOverdueNotification($task),
        );
    }

    public function notifyReviewSlaExpired(Task $task): void
    {
        $task->loadMissing(['initiator', 'department.head']);

        $recipients = collect([$task->initiator]);

        $head = $task->department?->head;
        if ($head && $head->is_active) {
            $recipients->push($head);
        }

        $this->sendToUsers(
            $recipients,
            null,
            'task.review_sla_expired',
            fn () => new TaskReviewSlaExpiredNotification($task),
        );
    }

    public function isChannelEnabled(User $user, string $event, string $channel): bool
    {
        $preference = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event', $event)
            ->where('channel', $channel)
            ->first();

        return ! ($preference && ! $preference->enabled);
    }

    /** @deprecated Use isChannelEnabled($user, $event, 'database') */
    public function isDatabaseEnabled(User $user, string $event): bool
    {
        return $this->isChannelEnabled($user, $event, 'database');
    }

    public function hasAnyChannelEnabled(User $user, string $event): bool
    {
        foreach (self::PREFERENCE_CHANNELS as $channel) {
            if ($this->isChannelEnabled($user, $event, $channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map preference channels to Laravel notification channel names.
     *
     * @return list<string>
     */
    public function resolveChannels(User $user, string $event): array
    {
        $channels = [];

        if ($this->isChannelEnabled($user, $event, 'database')) {
            $channels[] = 'database';
        }

        if ($this->isChannelEnabled($user, $event, 'email') && filled($user->email)) {
            $channels[] = 'mail';
        }

        if ($this->isChannelEnabled($user, $event, 'telegram') && filled($user->telegram_chat_id)) {
            $channels[] = 'telegram';
        }

        return $channels;
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function sendTaskAssigned(Task $task, User $actor, Collection $recipients): void
    {
        $this->sendToUsers(
            $recipients,
            $actor,
            'task.assigned',
            fn () => new TaskAssignedNotification($task, $actor),
        );
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function sendTaskStatusChanged(
        Task $task,
        User $actor,
        TaskStatus $oldStatus,
        TaskStatus $newStatus,
        Collection $recipients,
    ): void {
        $this->sendToUsers(
            $recipients,
            $actor,
            'task.status_changed',
            fn () => new TaskStatusChangedNotification($task, $actor, $oldStatus, $newStatus),
        );
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function sendTaskCommented(Task $task, User $actor, string $excerpt, Collection $recipients): void
    {
        $this->sendToUsers(
            $recipients,
            $actor,
            'task.commented',
            fn () => new TaskCommentedNotification($task, $actor, $excerpt),
        );
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function sendTaskMentioned(Task $task, User $actor, string $excerpt, Collection $recipients): void
    {
        $this->sendToUsers(
            $recipients,
            $actor,
            'task.mentioned',
            fn () => new TaskMentionedNotification($task, $actor, $excerpt),
        );
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function sendToUsers(
        Collection $recipients,
        ?User $actor,
        string $event,
        callable $notificationFactory,
    ): void {
        $recipients
            ->filter(fn (User $user) => $user->is_active && ($actor === null || $user->id !== $actor->id))
            ->unique('id')
            ->each(function (User $user) use ($event, $notificationFactory) {
                if (! $this->hasAnyChannelEnabled($user, $event)) {
                    return;
                }

                if ($this->resolveChannels($user, $event) === []) {
                    return;
                }

                /** @var Notification $notification */
                $notification = $notificationFactory();
                $user->notify($notification);
            });
    }

    private function commentExcerpt(string $body): string
    {
        $plain = strip_tags($body);
        $plain = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/[*_~`#>|]/', '', $plain) ?? $plain;
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);

        if (mb_strlen($plain) <= 120) {
            return $plain;
        }

        return mb_substr($plain, 0, 120).'…';
    }
}
