<?php

namespace App\Listeners;

use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\TaskEscalationNotification;
use App\Notifications\TaskMorningReminderNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the loop on reminder claims: delivered → "sent", failed → "failed" (the morning command
 * then tries again). Only reminder notifications are touched.
 */
class MarkTaskReminderDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $notification = $event->notification;

        if (! $event->notifiable instanceof User) {
            return;
        }

        if (! $notification instanceof TaskMorningReminderNotification && ! $notification instanceof TaskEscalationNotification) {
            return;
        }

        $failed = $event instanceof NotificationFailed;

        // Telegram answers null when it had nobody to send to: that is not a delivery.
        $status = $failed || $event->response === null ? TaskReminder::STATUS_FAILED : TaskReminder::STATUS_SENT;

        try {
            foreach ($this->claims($notification) as [$taskId, $kind, $marks, $refDate]) {
                DB::table('task_reminders')
                    ->where('task_id', $taskId)
                    ->where('user_id', $event->notifiable->id)
                    ->where('kind', $kind)
                    ->whereIn('mark', $marks)
                    ->whereDate('ref_date', $refDate)
                    ->where('status', TaskReminder::STATUS_PENDING)
                    ->update(['status' => $status]);
            }
        } catch (Throwable $e) {
            Log::warning('Task reminder status update failed: '.$e->getMessage());
        }
    }

    /** @return list<array{0: int, 1: string, 2: list<int>, 3: string}> */
    private function claims(TaskMorningReminderNotification|TaskEscalationNotification $notification): array
    {
        if ($notification instanceof TaskEscalationNotification) {
            return array_map(
                fn (array $item) => [(int) $item['task_id'], TaskReminder::KIND_ESCALATION, [-1], $notification->date],
                $notification->items,
            );
        }

        return array_map(
            fn (array $row) => [(int) $row['task_id'], (string) $row['kind'], array_map('intval', $row['marks']), (string) $row['ref_date']],
            $notification->rows,
        );
    }
}
