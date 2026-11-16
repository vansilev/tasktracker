<?php

namespace App\Listeners;

use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the send log: one row per notification and channel.
 * A logging failure must never break the delivery itself.
 */
class LogNotificationDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        try {
            $failed = $event instanceof NotificationFailed;

            // The Telegram channel returns null when it had nobody to send to (no linked chat):
            // Laravel still fires NotificationSent, but nothing went out.
            if (! $failed && $event->channel === 'telegram' && $event->response === null) {
                return;
            }

            $task = $event->notification->task ?? null;

            NotificationDelivery::query()->create([
                'user_id' => $event->notifiable instanceof User ? $event->notifiable->id : null,
                'task_id' => $task instanceof Task ? $task->id : null,
                'event' => $this->eventName($event->notification, $event->notifiable),
                'channel' => Str::limit((string) $event->channel, 32, ''),
                'status' => $failed ? NotificationDelivery::STATUS_FAILED : NotificationDelivery::STATUS_SENT,
                'error' => $failed ? $this->errorText($event->data) : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Notification delivery log failed: '.$e->getMessage());
        }
    }

    private function eventName(object $notification, object $notifiable): string
    {
        $name = null;

        if (method_exists($notification, 'toArray')) {
            try {
                $name = $notification->toArray($notifiable)['event'] ?? null;
            } catch (Throwable) {
                $name = null;
            }
        }

        return Str::limit(is_string($name) && $name !== '' ? $name : class_basename($notification), 64, '');
    }

    /** @param  array<string, mixed>  $data */
    private function errorText(array $data): ?string
    {
        // Only the error itself: the event payload also carries the message text and the chat id.
        $text = $data['exception'] ?? $data['message'] ?? $data['error'] ?? null;

        if ($text instanceof Throwable) {
            $text = $text->getMessage();
        }

        if (! is_string($text) || $text === '') {
            return null;
        }

        // HTTP client errors quote the request URL, which contains the bot token.
        $text = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $text) ?? '';

        return Str::limit($text, 500, '');
    }
}
