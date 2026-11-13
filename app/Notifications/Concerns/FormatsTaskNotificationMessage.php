<?php

namespace App\Notifications\Concerns;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Services\ReminderSettings;
use App\Services\TaskVisibilityService;
use App\Support\Deadline;
use App\Support\TelegramAppLinks;
use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramTaskCard;
use App\Support\TelegramWidget as W;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;
use NotificationChannels\Telegram\TelegramMessage;

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

    /**
     * The personal Telegram message about one task: a headline, the task card and its buttons.
     * The buttons only invite; every press is checked again on the server.
     *
     * @param  list<string|null>  $extra
     */
    protected function telegramTaskCard(User $notifiable, Task $task, string $headline, array $extra = []): TelegramMessage
    {
        $card = app(TelegramTaskCard::class);
        $visible = app(TaskVisibilityService::class)->accessibleQuery($notifiable)->whereKey($task->id)->exists();
        $rows = $visible ? $card->keyboard($task, $notifiable) : [];

        return TelegramMessage::create()
            ->parseMode('HTML')
            ->options(array_filter([
                'disable_web_page_preview' => true,
                'reply_markup' => $rows === [] ? null : json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE),
            ]))
            ->content($card->text($task, $notifiable, $headline, $extra));
    }

    /**
     * The same news as telegramTaskCard(), as an HTML widget for those who get widgets; the
     * card text stays as the fallback. Buttons under it: the card's, with the typing ones
     * opening the tracker's window.
     *
     * @param  list<array{0: string, 1?: string}|null>  $chips
     * @param  string  $bodyHtml  widget bricks shown under the task's title and facts
     */
    protected function telegramTaskWidget(User $notifiable, Task $task, TelegramMessage $fallback, array $chips, string $bodyHtml, string $caption): TelegramMessage|TelegramWidgetMessage
    {
        if (! app(ReminderSettings::class)->widgetsFor($notifiable->id)) {
            return $fallback;
        }

        $locale = $notifiable->preferredLocale();
        $b = fn (string $key, array $replace = []) => __('notification.bot.'.$key, $replace, $locale);
        $visible = app(TaskVisibilityService::class)->accessibleQuery($notifiable)->whereKey($task->id)->exists();
        $rows = $visible ? app(TelegramTaskCard::class)->keyboard($task, $notifiable) : [];
        $status = F::status($task->status->value, $locale);

        $who = match (true) {
            $task->assignee_id === $notifiable->id => '🎯 '.e($b('you_assignee')).' · '.$status,
            $task->initiator_id === $notifiable->id => '🙋 '.e($b('you_initiator')).' · '.$status,
            default => '👀 '.e($b('you_watcher')).' · '.$status,
        };

        $html = W::chips($chips)
            .W::title($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()))
            .W::meta([
                $who,
                $task->assignee_id !== $notifiable->id && $task->assignee !== null ? '🎯 '.e($b('assignee_is', ['name' => $task->assignee->name])) : null,
                '🗓 '.e($task->deadline === null ? $b('no_deadline') : $b('deadline', ['date' => F::weekdayDate($task->deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d'), $locale)])),
                F::priorityLine(max(1, min(10, (int) $task->priority)), $locale),
            ])
            .$bodyHtml
            .W::linkButtons($task->id, $task->number, $locale);

        return TelegramWidgetMessage::make(W::document($html, $locale), W::caption($caption), $fallback, TelegramAppLinks::widgetKeyboard($rows));
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

    protected function commentRepliedLine(Task $task, User $actor, string $excerpt, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_comment_replied', [
            'number' => $task->number,
            'title' => $task->title,
            'actor' => $actor->name,
            'excerpt' => $excerpt,
        ]);
    }

    protected function commentReactedLine(
        Task $task,
        User $actor,
        string $excerpt,
        string $emoji,
        User $notifiable,
    ): string {
        return $this->localizedLine($notifiable, 'notification.task_comment_reacted', [
            'number' => $task->number,
            'title' => $task->title,
            'actor' => $actor->name,
            'excerpt' => $excerpt,
            'emoji' => $emoji,
        ]);
    }

    protected function deadlineApproachingLine(Task $task, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_deadline_approaching', [
            'number' => $task->number,
            'title' => $task->title,
            'deadline' => Deadline::format($task->deadline),
        ]);
    }

    protected function overdueLine(Task $task, User $notifiable): string
    {
        return $this->localizedLine($notifiable, 'notification.task_overdue', [
            'number' => $task->number,
            'title' => $task->title,
            'deadline' => Deadline::format($task->deadline),
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
