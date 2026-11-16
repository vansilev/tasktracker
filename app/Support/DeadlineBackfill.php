<?php

namespace App\Support;

use App\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off move of stored deadlines to the end of their day (task 448).
 *
 * Works on raw "Y-m-d H:i:s" strings through the app connection, so it behaves the same on
 * SQLite and MySQL and does not depend on the database session time zone. Task history and
 * updated_at are left alone. Safe to run twice; revert() undoes only what apply() itself did.
 */
class DeadlineBackfill
{
    public const BACKUP_TABLE = 'task_deadline_backups';

    /**
     * @return array{moved: int, overdue_flag_cleared: int, reminder_flag_set: int, skipped_changed: int}
     */
    public function apply(): array
    {
        $this->ensureBackupTable();

        $now = now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $dayAhead = now()->timezone(config('app.timezone'))->addDay()->format('Y-m-d H:i:s');
        $open = array_map(fn (TaskStatus $status) => $status->value, TaskStatus::open());
        $stats = ['moved' => 0, 'overdue_flag_cleared' => 0, 'reminder_flag_set' => 0, 'skipped_changed' => 0];

        DB::table('tasks')
            ->whereNotNull('deadline')
            ->select(['id', 'deadline', 'status', 'overdue_notified_at', 'deadline_reminder_sent_at'])
            ->chunkById(200, function ($rows) use (&$stats, $now, $dayAhead, $open): void {
                foreach ($rows as $row) {
                    $old = substr((string) $row->deadline, 0, 19);

                    if (substr($old, 11) === Deadline::END_OF_DAY) {
                        continue;
                    }

                    $new = substr($old, 0, 10).' '.Deadline::END_OF_DAY;
                    $updates = ['deadline' => $new];
                    $clearedOverdue = null;
                    $setReminder = null;

                    // Only tasks that were overdue by the old midnight but are still running today
                    // change state; everything else keeps its reminder flags untouched.
                    if (in_array($row->status, $open, true) && $old < $now && $new > $now) {
                        if ($row->overdue_notified_at !== null) {
                            // "Overdue" must fire again once the day really ends.
                            $clearedOverdue = substr((string) $row->overdue_notified_at, 0, 19);
                            $updates['overdue_notified_at'] = null;
                        }

                        if ($row->deadline_reminder_sent_at === null && $new <= $dayAhead) {
                            // Otherwise the very next scheduler run sends a surprise "due within a day".
                            $setReminder = $now;
                            $updates['deadline_reminder_sent_at'] = $now;
                        }
                    }

                    $moved = DB::transaction(function () use ($row, $old, $new, $updates, $clearedOverdue, $setReminder): bool {
                        // The deadline condition skips a row someone edited after we read it.
                        $affected = DB::table('tasks')
                            ->where('id', $row->id)
                            ->where('deadline', $row->deadline)
                            ->update($updates);

                        if ($affected !== 1) {
                            return false;
                        }

                        DB::table(self::BACKUP_TABLE)->updateOrInsert(
                            ['task_id' => $row->id],
                            [
                                'old_deadline' => $old,
                                'new_deadline' => $new,
                                'cleared_overdue_notified_at' => $clearedOverdue,
                                'set_deadline_reminder_sent_at' => $setReminder,
                            ],
                        );

                        return true;
                    });

                    if (! $moved) {
                        $stats['skipped_changed']++;

                        continue;
                    }

                    $stats['moved']++;
                    $stats['overdue_flag_cleared'] += $clearedOverdue !== null ? 1 : 0;
                    $stats['reminder_flag_set'] += $setReminder !== null ? 1 : 0;
                }
            });

        return $stats;
    }

    /**
     * Puts back the old deadline — and the reminder flags apply() changed — only where the task
     * still holds exactly what apply() wrote. Anything people or the scheduler changed since stays.
     *
     * @return array{restored: int, kept: int}
     */
    public function revert(): array
    {
        $stats = ['restored' => 0, 'kept' => 0];

        if (! Schema::hasTable(self::BACKUP_TABLE)) {
            return $stats;
        }

        foreach (DB::table(self::BACKUP_TABLE)->orderBy('task_id')->cursor() as $backup) {
            $task = DB::table('tasks')
                ->where('id', $backup->task_id)
                ->first(['id', 'deadline', 'overdue_notified_at', 'deadline_reminder_sent_at']);

            if ($task === null || substr((string) $task->deadline, 0, 19) !== $backup->new_deadline) {
                $stats['kept']++;

                continue;
            }

            $updates = ['deadline' => $backup->old_deadline];

            if ($backup->cleared_overdue_notified_at !== null && $task->overdue_notified_at === null) {
                $updates['overdue_notified_at'] = $backup->cleared_overdue_notified_at;
            }

            if (
                $backup->set_deadline_reminder_sent_at !== null
                && substr((string) $task->deadline_reminder_sent_at, 0, 19) === $backup->set_deadline_reminder_sent_at
            ) {
                $updates['deadline_reminder_sent_at'] = null;
            }

            $affected = DB::table('tasks')
                ->where('id', $task->id)
                ->where('deadline', $task->deadline)
                ->update($updates);

            $stats[$affected === 1 ? 'restored' : 'kept']++;
        }

        Schema::drop(self::BACKUP_TABLE);

        return $stats;
    }

    private function ensureBackupTable(): void
    {
        if (Schema::hasTable(self::BACKUP_TABLE)) {
            return;
        }

        Schema::create(self::BACKUP_TABLE, function ($table) {
            $table->unsignedBigInteger('task_id')->primary();
            $table->string('old_deadline', 19);
            $table->string('new_deadline', 19);
            $table->string('cleared_overdue_notified_at', 19)->nullable();
            $table->string('set_deadline_reminder_sent_at', 19)->nullable();
        });
    }
}
