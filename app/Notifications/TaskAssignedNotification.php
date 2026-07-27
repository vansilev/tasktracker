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

    public function toTelegram(object $notifiable): TelegramMessage
    {
        /** @var User $notifiable */
        return TelegramMessage::create()
            ->normal()
            ->content($this->assignedLine($this->task, $this->actor, $notifiable))
            ->button(
                $this->localizedLine($notifiable, 'notification.open_task'),
                $this->taskUrl($this->task),
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
