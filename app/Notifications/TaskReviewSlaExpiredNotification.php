<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\FormatsTaskNotificationMessage;
use App\Notifications\Concerns\ResolvesNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Telegram\TelegramMessage;

class TaskReviewSlaExpiredNotification extends Notification implements ShouldQueue
{
    use FormatsTaskNotificationMessage;
    use Queueable;
    use ResolvesNotificationChannels;
    use SerializesModels;

    public function __construct(
        public Task $task,
    ) {}

    protected function notificationEvent(): string
    {
        return 'task.review_sla_expired';
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $line = $this->reviewSlaExpiredLine($this->task, $notifiable);

        return (new MailMessage)
            ->subject($line)
            ->line($line)
            ->action(
                $this->localizedLine($notifiable, 'notification.open_task'),
                $this->taskUrl($this->task),
            );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        /** @var User $notifiable */
        return TelegramMessage::create()
            ->normal()
            ->content($this->reviewSlaExpiredLine($this->task, $notifiable))
            ->button(
                $this->localizedLine($notifiable, 'notification.open_task'),
                $this->taskUrl($this->task),
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'task.review_sla_expired',
            'task_id' => $this->task->id,
            'task_number' => $this->task->number,
            'task_title' => $this->task->title,
            'review_due_at' => $this->task->review_due_at?->toIso8601String(),
        ];
    }
}
