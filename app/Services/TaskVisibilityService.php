<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TaskVisibilityService
{
    public function canView(User $user, Task $task): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($task->initiator_id === $user->id || $task->assignee_id === $user->id) {
            return true;
        }

        if ($task->relationLoaded('watchers')) {
            if ($task->watchers->contains('id', $user->id)) {
                return true;
            }
        } elseif ($task->watchers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($user->isDeptHead()) {
            $headedIds = $user->headedDepartments()->pluck('id');
            if ($headedIds->contains($task->department_id)) {
                return true;
            }
        }

        return $user->visibleDepartmentIds()->contains($task->department_id);
    }

    /** @return Builder<Task> */
    public function accessibleQuery(User $user): Builder
    {
        if ($user->isAdmin()) {
            return Task::query();
        }

        $visibleDeptIds = $user->visibleDepartmentIds();
        $headedDeptIds = $user->isDeptHead()
            ? $user->headedDepartments()->pluck('id')
            : collect();

        return Task::query()->where(function (Builder $q) use ($user, $visibleDeptIds, $headedDeptIds) {
            $q->where('initiator_id', $user->id)
                ->orWhere('assignee_id', $user->id)
                ->orWhereHas('watchers', fn (Builder $w) => $w->where('user_id', $user->id));

            if ($headedDeptIds->isNotEmpty()) {
                $q->orWhereIn('department_id', $headedDeptIds);
            }

            if ($visibleDeptIds->isNotEmpty()) {
                $q->orWhereIn('department_id', $visibleDeptIds);
            }
        });
    }
}
