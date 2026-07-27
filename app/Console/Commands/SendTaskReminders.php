<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskNotificationService;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders {--dry-run : Count matching tasks without sending or updating}';

    protected $description = 'Send in-app reminders for approaching deadlines, overdue tasks, and expired review SLA';

    public function handle(TaskNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $openStatuses = array_map(fn (TaskStatus $status) => $status->value, TaskStatus::open());

        $approachingCount = 0;
        $overdueCount = 0;
        $reviewSlaCount = 0;

        Task::query()
            ->whereIn('status', $openStatuses)
            ->whereNotNull('deadline')
            ->whereNull('deadline_reminder_sent_at')
            ->where('deadline', '>=', now())
            ->where('deadline', '<=', now()->addDay())
            ->eachById(function (Task $task) use ($notifications, $dryRun, &$approachingCount): void {
                if (! $dryRun) {
                    $notifications->notifyDeadlineApproaching($task);
                    $task->update(['deadline_reminder_sent_at' => now()]);
                }

                $approachingCount++;
            });

        Task::query()
            ->whereIn('status', $openStatuses)
            ->whereNotNull('deadline')
            ->whereNull('overdue_notified_at')
            ->where('deadline', '<', now())
            ->eachById(function (Task $task) use ($notifications, $dryRun, &$overdueCount): void {
                if (! $dryRun) {
                    $notifications->notifyOverdue($task);
                    $task->update(['overdue_notified_at' => now()]);
                }

                $overdueCount++;
            });

        Task::query()
            ->where('status', TaskStatus::OnReview)
            ->whereNotNull('review_due_at')
            ->whereNull('review_sla_notified_at')
            ->where('review_due_at', '<', now())
            ->eachById(function (Task $task) use ($notifications, $dryRun, &$reviewSlaCount): void {
                if (! $dryRun) {
                    $notifications->notifyReviewSlaExpired($task);
                    $task->update(['review_sla_notified_at' => now()]);
                }

                $reviewSlaCount++;
            });

        $prefix = $dryRun ? 'Would process' : 'Processed';

        $this->info("{$prefix} deadline approaching: {$approachingCount}");
        $this->info("{$prefix} overdue: {$overdueCount}");
        $this->info("{$prefix} review SLA expired: {$reviewSlaCount}");

        return self::SUCCESS;
    }
}
