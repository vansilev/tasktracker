<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\FormatsTaskNotificationMessage;
use App\Notifications\Concerns\ResolvesNotificationChannels;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Support\TelegramWidget as W;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use NotificationChannels\Telegram\TelegramMessage;

class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use FormatsTaskNotificationMessage;
    use Queueable;
    use ResolvesNotificationChannels;
    use SerializesModels;

    public function __construct(
        public Task $task,
        public User $actor,
    ) {}

    protected function notificationEvent(): string
    {
        return 'task.assigned';
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $line = $this->assignedLine($this->task, $this->actor, $notifiable);

        return (new MailMessage)
            ->subject($line)
            ->line($line)
            ->action(
                $this->localizedLine($notifiable, 'notification.open_task'),
                $this->taskUrl($this->task),
            );
    }

    public function toTelegram(object $notifiable): TelegramMessage|TelegramWidgetMessage
    {
        /** @var User $notifiable */
        $locale = $notifiable->preferredLocale();
        $mine = $this->task->assignee_id === $notifiable->id;
        $description = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $this->task->plainDescription())), 300, '…');

        $headline = __($mine ? 'notification.bot.event_assigned_you' : 'notification.bot.event_assigned_other', [], $locale);
        $by = __('notification.bot.event_assigned_by', ['name' => $this->actor->name], $locale);
        // The title already is the description when the task has no title of its own.
        $showDescription = $description !== '' && filled($this->task->title);

        $text = $this->telegramTaskCard(
            $notifiable,
            $this->task,
            '🆕 <b>'.e($headline).'</b>',
            [
                '👤 '.e($by),
                $showDescription ? '📝 '.e($description) : null,
            ],
        );

        return $this->telegramTaskWidget($notifiable, $this->task, $text,
            [['🆕 '.$headline, 'bl']],
            W::meta(['👤 '.e($by)]).($showDescription ? W::box(null, '📝 '.$description) : ''),
            '🆕 '.$headline.': #'.$this->task->number.' '.($this->task->title ?: $this->task->plainDescription()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'task.assigned',
            'task_id' => $this->task->id,
            'task_number' => $this->task->number,
            'task_title' => $this->task->title,
            'actor_name' => $this->actor->name,
        ];
    }
}
