<?php

namespace App\Notifications\Concerns;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;

trait FormatsTaskNotificationMessage
{
    protected function localizedLine(User $notifiable, string $key, array $replace = []): string
    {
        $locale = $notifiable->locale ?: config('app.locale');

        return App::getLocale() === $locale
            ? __($key, $replace)
            : __($key, $replace, $locale);
    }

    protected function formatDateTime(?CarbonInterface $value, User $notifiable): string
    {
        if ($value === null) {
            return '';
        }

        return $value
            ->timezone(config('app.timezone', 'Europe/Kyiv'))
            ->format('d.m.Y H:i');
    }

    protected function taskUrl(Task $task): string
    {
        return route('tasks.show', $task);
    }

    protected function assignedLine(Task $task, User $actor, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_assigned', [
            'number' => $task->number,
            'title' => $task->title,
            'actor' => $actor->name,
        ]);
    }

    protected function statusChangedLine(
        Task $task,
        User $actor,
        TaskStatus $oldStatus,
        TaskStatus $newStatus,
        User $notifiable,
    ): string {
        $locale = $notifiable->locale ?: config('app.locale');

        return $this->localizedLine($notifiable, 'notification.task_status_changed', [
            'number' => $task->number,
            'title' => $task->title,
            'actor' => $actor->name,
            'old' => __("task.status.{$oldStatus->value}", [], $locale),
            'new' => __("task.status.{$newStatus->value}", [], $locale),
        ]);
    }

    protected function commentedLine(Task $task, User $actor, string $excerpt, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_commented', [
            'number' => $task->number,
            'title' => $task->title,
            'actor' => $actor->name,
            'excerpt' => $excerpt,
        ]);
    }

    protected function mentionedLine(Task $task, User $actor, string $excerpt, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_mentioned', [
            'number' => $task->number,
            'title' => $task->title,
            'actor' => $actor->name,
            'excerpt' => $excerpt,
        ]);
    }

    protected function deadlineApproachingLine(Task $task, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_deadline_approaching', [
            'number' => $task->number,
            'title' => $task->title,
            'deadline' => $this->formatDateTime($task->deadline, $notifiable),
        ]);
    }

    protected function overdueLine(Task $task, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_overdue', [
            'number' => $task->number,
            'title' => $task->title,
            'deadline' => $this->formatDateTime($task->deadline, $notifiable),
        ]);
    }

    protected function reviewSlaExpiredLine(Task $task, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_review_sla_expired', [
            'number' => $task->number,
            'title' => $task->title,
            'review_due_at' => $this->formatDateTime($task->review_due_at, $notifiable),
        ]);
    }
}
