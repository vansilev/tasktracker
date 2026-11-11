<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\TaskDigestNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the loop on the scheduled digest: delivered → "sent", failed → "failed" (the digest
 * command then tries again the same working day). A digest of several messages counts as sent
 * when its last part is; any failed part fails the whole delivery.
 */
class MarkDigestDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $notification = $event->notification;

        if (! $notification instanceof TaskDigestNotification || ! $notification->scheduled || ! $event->notifiable instanceof User) {
            return;
        }

        $failed = $event instanceof NotificationFailed || $event->response === null;

        if (! $failed && $notification->part < $notification->parts) {
            return;
        }

        try {
            DB::table('digest_deliveries')
                ->whereDate('date', $notification->date)
                ->where('department_id', $notification->departmentId)
                ->where('user_id', $event->notifiable->id)
                ->where('status', 'pending')
                ->update(['status' => $failed ? 'failed' : 'sent']);
        } catch (Throwable $e) {
            Log::warning('Digest delivery status update failed: '.$e->getMessage());
        }
    }
}
