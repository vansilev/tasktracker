<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskHistory;
use App\Models\TaskVisit;
use App\Models\User;

class TaskUnreadService
{
    public function markSeen(User $user, Task $task): void
    {
        TaskVisit::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'task_id' => $task->id,
            ],
            [
                'last_seen_at' => now(),
            ],
        );

        $user->unreadNotifications()
            ->where('data->task_id', $task->id)
            ->update(['read_at' => now()]);
    }

    /**
     * Comments and real status changes by others after this user's last visit.
     *
     * @param  list<int>  $taskIds
     * @return array<int, int>
     */
    public function counts(User $user, array $taskIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $taskIds),
            static fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $counts = array_fill_keys($ids, 0);

        $commentCounts = TaskComment::query()
            ->selectRaw('task_comments.task_id as task_id, COUNT(*) as aggregate')
            ->leftJoin('task_visits', function ($join) use ($user): void {
                $join->on('task_visits.task_id', '=', 'task_comments.task_id')
                    ->where('task_visits.user_id', '=', $user->id);
            })
            ->whereIn('task_comments.task_id', $ids)
            ->where('task_comments.author_id', '!=', $user->id)
            ->where(function ($query): void {
                $query->whereNull('task_visits.last_seen_at')
                    ->orWhereColumn('task_comments.created_at', '>', 'task_visits.last_seen_at');
            })
            ->groupBy('task_comments.task_id')
            ->pluck('aggregate', 'task_id');

        $statusCounts = TaskHistory::query()
            ->selectRaw('task_histories.task_id as task_id, COUNT(*) as aggregate')
            ->leftJoin('task_visits', function ($join) use ($user): void {
                $join->on('task_visits.task_id', '=', 'task_histories.task_id')
                    ->where('task_visits.user_id', '=', $user->id);
            })
            ->whereIn('task_histories.task_id', $ids)
            ->where('task_histories.field', 'status')
            ->whereNotNull('task_histories.old_value')
            ->where('task_histories.changed_by', '!=', $user->id)
            ->where(function ($query): void {
                $query->whereNull('task_visits.last_seen_at')
                    ->orWhereColumn('task_histories.created_at', '>', 'task_visits.last_seen_at');
            })
            ->groupBy('task_histories.task_id')
            ->pluck('aggregate', 'task_id');

        foreach ($commentCounts as $taskId => $count) {
            $counts[(int) $taskId] = (int) $count;
        }

        foreach ($statusCounts as $taskId => $count) {
            $counts[(int) $taskId] = ($counts[(int) $taskId] ?? 0) + (int) $count;
        }

        return $counts;
    }
}
