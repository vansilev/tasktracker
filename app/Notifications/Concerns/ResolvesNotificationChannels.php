<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\TaskNotificationService;

trait ResolvesNotificationChannels
{
    abstract protected function notificationEvent(): string;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        return app(TaskNotificationService::class)
            ->resolveChannels($notifiable, $this->notificationEvent());
    }
}
