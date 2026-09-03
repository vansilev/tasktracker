<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TaskQueryService
{
    public function __construct(
        private TaskVisibilityService $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Task>
     */
    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->visibility->accessibleQuery($user)
            ->with([
                'initiator:id,name,email',
                'assignee:id,name,email',
                'department:id,name',
                'category:id,name',
                'parent:id,number,title',
                'checklistItems:id,task_id,is_done',
                'subtasks:id,parent_id,number,status,sort_order',
            ]);

        $this->applyTab($query, $user, (string) ($filters['tab'] ?? 'all'));
        $this->applyFilters($query, $filters);
        $this->applySorting($query, (string) ($filters['sort'] ?? 'priority'), (string) ($filters['dir'] ?? 'desc'));

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));

        return $query->paginate($perPage);
    }

    public function findByNumber(User $user, int $number): Task
    {
        $task = $this->visibility->accessibleQuery($user)
            ->where('number', $number)
            ->first();

        if ($task === null) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$number]);
        }

        $task->load([
            'initiator:id,name,email',
            'assignee:id,name,email',
            'closedBy:id,name,email',
            'department:id,name',
            'category:id,name',
            'parent:id,number,title,status',
            'subtasks' => fn ($q) => $q->with(['assignee:id,name,email']),
            'blockers:id,number,title,status',
            'watchers:id,name,email',
            'checklistItems',
            'comments' => fn ($q) => $q->latest()->limit(50)->with('author:id,name,email'),
            'histories' => fn ($q) => $q->latest()->limit(50)->with('changedBy:id,name,email'),
            'attachments:id,task_id,comment_id,filename,mime,size',
        ]);

        return $task;
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($search, $like): void {
                if (ctype_digit($search)) {
                    $q->where('number', (int) $search);
                }

                $q->orWhere('number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhereRaw('COALESCE(description_text, description) LIKE ?', [$like])
                    ->orWhereHas('comments', fn (Builder $cq) => $cq->whereRaw('COALESCE(body_text, body) LIKE ?', [$like]));
            });
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $statuses = $filters['statuses'] ?? null;
        if (is_array($statuses) && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['open'])) {
            $query->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()));
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', (int) $filters['assignee_id']);
        }

        if (! empty($filters['initiator_id'])) {
            $query->where('initiator_id', (int) $filters['initiator_id']);
        }

        if (! empty($filters['urgent'])) {
            $query->where('priority', '>=', 9);
        }

        if (isset($filters['priority_min']) && $filters['priority_min'] !== '' && $filters['priority_min'] !== null) {
            $query->where('priority', '>=', (int) $filters['priority_min']);
        }

        if (isset($filters['priority_max']) && $filters['priority_max'] !== '' && $filters['priority_max'] !== null) {
            $query->where('priority', '<=', (int) $filters['priority_max']);
        }

        if (! empty($filters['parent_number'])) {
            $query->whereHas('parent', fn (Builder $p) => $p->where('number', (int) $filters['parent_number']));
        } elseif (! empty($filters['parents_only'])) {
            $query->whereNull('parent_id');
        }

        if (! empty($filters['overdue'])) {
            $query->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))
                ->whereNotNull('deadline')
                ->where('deadline', '<', now());
        }
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTab(Builder $query, User $user, string $tab): void
    {
        match ($tab) {
            'assigned' => $query->where('assignee_id', $user->id),
            'created' => $query->where('initiator_id', $user->id),
            'watching' => $query->whereHas('watchers', fn (Builder $q) => $q->where('user_id', $user->id)),
            'department' => $user->headedDepartments()->exists()
                ? $query->whereIn('department_id', $user->headedDepartments()->pluck('id'))
                : ($user->department_id
                    ? $query->where('department_id', $user->department_id)
                    : $query->whereRaw('0 = 1')),
            'action' => app(TaskActionQueueService::class)->applyScope($query, $user),
            default => null,
        };
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applySorting(Builder $query, string $sort, string $dir): void
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'title' => $query
                ->orderByRaw("CASE WHEN title IS NULL OR title = '' THEN 1 ELSE 0 END")
                ->orderBy('title', $dir)
                ->orderBy('id'),
            'deadline' => $query
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline', $dir)
                ->orderBy('id'),
            'created_at' => $query->orderBy('created_at', $dir)->orderBy('id'),
            'number' => $query->orderBy('number', $dir),
            'status' => $query->orderBy('status', $dir)->orderBy('id'),
            default => $query
                ->orderBy('priority', $dir)
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->orderBy('id'),
        };
    }
}
