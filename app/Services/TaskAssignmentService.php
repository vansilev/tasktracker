<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskAssignmentService
{
    public function resolveAssignee(Department $department, ?int $assigneeId = null): User
    {
        if ($assigneeId) {
            $assignee = User::query()->find($assigneeId);
            if (! $assignee || ! $assignee->is_active || $assignee->department_id !== $department->id) {
                throw ValidationException::withMessages([
                    'assignee_id' => [__('task.assignee_department_mismatch')],
                ]);
            }

            return $assignee;
        }

        // Auto-distribution: only when explicitly enabled and the queue has active users.
        if ($department->auto_assign_enabled) {
            $queueUser = $department->assignQueue()->where('users.is_active', true)->first();
            if ($queueUser) {
                $this->rotateQueue($department, $queueUser->id);

                return $queueUser;
            }
        }

        // Default: department head.
        if ($department->head_user_id) {
            $head = User::query()->where('is_active', true)->find($department->head_user_id);
            if ($head) {
                return $head;
            }
        }

        // Fallback: first active queue member even if auto-assign is off.
        $queueUser = $department->assignQueue()->where('users.is_active', true)->first();
        if ($queueUser) {
            return $queueUser;
        }

        throw new \RuntimeException(__('task.no_assignee_available'));
    }

    private function rotateQueue(Department $department, int $userId): void
    {
        $maxOrder = (int) DB::table('department_assign_queue')
            ->where('department_id', $department->id)
            ->max('sort_order');

        DB::table('department_assign_queue')
            ->where('department_id', $department->id)
            ->where('user_id', $userId)
            ->update(['sort_order' => $maxOrder + 1]);
    }
}
