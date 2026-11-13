<?php

namespace App\Notifications;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\FormatsTaskNotificationMessage;
use App\Notifications\Concerns\ResolvesNotificationChannels;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramWidget as W;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Telegram\TelegramMessage;

class TaskStatusChangedNotification extends Notification implements ShouldQueue
{
    use FormatsTaskNotificationMessage;
    use Queueable;
    use ResolvesNotificationChannels;
    use SerializesModels;

    public function __construct(
        public Task $task,
        public User $actor,
        public TaskStatus $oldStatus,
        public TaskStatus $newStatus,
        public ?string $reasonExcerpt = null,
    ) {}

    protected function notificationEvent(): string
    {
        return 'task.status_changed';
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $line = $this->statusChangedLine(
            $this->task,
            $this->actor,
            $this->oldStatus,
            $this->newStatus,
            $notifiable,
        );

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

        $text = $this->telegramTaskCard(
            $notifiable,
            $this->task,
            '🔄 <b>'.e(__('notification.bot.event_status', [], $locale)).'</b> '
                .F::status($this->oldStatus->value, $locale).' → <b>'.F::status($this->newStatus->value, $locale).'</b>',
            [
                '👤 '.e(__('notification.bot.event_status_by', ['name' => $this->actor->name], $locale)),
                filled($this->reasonExcerpt) ? '<blockquote>'.e($this->reasonExcerpt).'</blockquote>' : null,
            ],
        );

        $old = __('task.status.'.$this->oldStatus->value, [], $locale);
        $new = __('task.status.'.$this->newStatus->value, [], $locale);
        $by = __('notification.bot.event_status_by', ['name' => $this->actor->name], $locale);

        return $this->telegramTaskWidget($notifiable, $this->task, $text,
            [['🔄 '.__('notification.bot.event_status', [], $locale).' '.$new, 'or']],
            W::steps([$old], $new)
                .(filled($this->reasonExcerpt) ? W::box($this->actor->name, $this->reasonExcerpt) : W::meta(['👤 '.e($by)], 'margin-top:6px')),
            '🔄 #'.$this->task->number.' '.$old.' → '.$new.' ('.$this->actor->name.')'.(filled($this->reasonExcerpt) ? ': '.$this->reasonExcerpt : ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'task.status_changed',
            'task_id' => $this->task->id,
            'task_number' => $this->task->number,
            'task_title' => $this->task->title,
            'actor_name' => $this->actor->name,
            'old_status' => $this->oldStatus->value,
            'new_status' => $this->newStatus->value,
        ];
    }
}
