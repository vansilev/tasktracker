<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TaskVisibilityService
{
    public function canView(User $user, Task $task): bool
    {
        if ($this->canViewDirect($user, $task)) {
            return true;
        }

        $parent = $task->relationLoaded('parent')
            ? $task->parent
            : $task->parent()->first();

        return $parent !== null && $this->canViewDirect($user, $parent);
    }

    public function canViewDirect(User $user, Task $task): bool
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
            $this->applyDirectAccess($q, $user, $visibleDeptIds, $headedDeptIds);

            $q->orWhereHas('parent', function (Builder $parent) use ($user, $visibleDeptIds, $headedDeptIds) {
                $this->applyDirectAccess($parent, $user, $visibleDeptIds, $headedDeptIds);
            });
        });
    }

    /**
     * @param  Builder<Task>  $q
     * @param  Collection<int, int|string>  $visibleDeptIds
     * @param  Collection<int, int|string>  $headedDeptIds
     */
    private function applyDirectAccess(
        Builder $q,
        User $user,
        $visibleDeptIds,
        $headedDeptIds,
    ): void {
        $q->where('initiator_id', $user->id)
            ->orWhere('assignee_id', $user->id)
            ->orWhereHas('watchers', fn (Builder $w) => $w->where('user_id', $user->id));

        if ($headedDeptIds->isNotEmpty()) {
            $q->orWhereIn('department_id', $headedDeptIds);
        }

        if ($visibleDeptIds->isNotEmpty()) {
            $q->orWhereIn('department_id', $visibleDeptIds);
        }
    }
}
