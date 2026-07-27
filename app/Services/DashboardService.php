<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private TaskVisibilityService $visibility,
    ) {}

    /** @return array{by_status: array<string, int>, total: int} */
    public function openByStatus(User $user): array
    {
        $query = $this->accessible($user)->whereIn('status', $this->openStatusValues());
        $byStatus = [];

        foreach (TaskStatus::open() as $status) {
            $byStatus[$status->value] = (clone $query)->where('status', $status)->count();
        }

        return [
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
        ];
    }

    /** @return array{count: int, items: list<array{id: int, number: int, title: string, deadline: \Carbon\Carbon|null, assignee_name: string|null}>} */
    public function overdue(User $user, int $limit = 10): array
    {
        $query = $this->accessible($user)
            ->whereIn('status', $this->openStatusValues())
            ->whereNotNull('deadline')
            ->where('deadline', '<', now());

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->with('assignee:id,name')
                ->orderBy('deadline')
                ->limit($limit)
                ->get(['id', 'number', 'title', 'deadline', 'assignee_id'])
                ->map(fn ($task) => [
                    'id' => $task->id,
                    'number' => $task->number,
                    'title' => $task->title,
                    'deadline' => $task->deadline,
                    'assignee_name' => $task->assignee?->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{count: int, items: list<\App\Models\Task>} */
    public function onReviewForInitiator(User $user, int $limit = 10): array
    {
        $query = $this->accessible($user)
            ->where('status', TaskStatus::OnReview)
            ->where('initiator_id', $user->id);

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get(['id', 'number', 'title', 'deadline', 'priority']),
        ];
    }

    /** @return array{count: int, items: list<\App\Models\Task>} */
    public function urgent(User $user, int $limit = 10): array
    {
        $query = $this->accessible($user)
            ->whereIn('status', $this->openStatusValues())
            ->where('priority', '>=', 9);

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->orderByDesc('priority')
                ->orderBy('deadline')
                ->limit($limit)
                ->get(['id', 'number', 'title', 'deadline', 'priority']),
        ];
    }

    /** @return list<array{id: int, name: string, created: int, completed: int}> */
    public function byDepartment(User $user, Carbon $from, Carbon $to): array
    {
        $base = $this->accessible($user);

        $createdCounts = (clone $base)
            ->whereBetween('created_at', [$from, $to])
            ->select('department_id', DB::raw('count(*) as aggregate'))
            ->groupBy('department_id')
            ->pluck('aggregate', 'department_id');

        $completedCounts = (clone $base)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->select('department_id', DB::raw('count(*) as aggregate'))
            ->groupBy('department_id')
            ->pluck('aggregate', 'department_id');

        $departmentIds = Department::query()
            ->active()
            ->pluck('id')
            ->merge($createdCounts->keys())
            ->merge($completedCounts->keys())
            ->unique()
            ->sort()
            ->values();

        return Department::query()
            ->whereIn('id', $departmentIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'created' => (int) ($createdCounts[$department->id] ?? 0),
                'completed' => (int) ($completedCounts[$department->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, name: string, count: int}> */
    public function byCategory(User $user, Carbon $from, Carbon $to): array
    {
        return $this->accessible($user)
            ->whereBetween('tasks.created_at', [$from, $to])
            ->join('categories', 'tasks.category_id', '=', 'categories.id')
            ->select('categories.id', 'categories.name', DB::raw('count(*) as aggregate'))
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    public function avgClosingTime(User $user, Carbon $from, Carbon $to): ?float
    {
        $tasks = $this->accessible($user)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->get(['created_at', 'completed_at']);

        if ($tasks->isEmpty()) {
            return null;
        }

        $totalHours = $tasks->sum(
            fn ($task) => $task->created_at->diffInHours($task->completed_at, absolute: true),
        );

        return $totalHours / $tasks->count();
    }

    public static function formatDurationHours(?float $hours): ?string
    {
        if ($hours === null) {
            return null;
        }

        $totalHours = (int) round($hours);
        $days = intdiv($totalHours, 24);
        $remainingHours = $totalHours % 24;
        $parts = [];

        if ($days > 0) {
            $parts[] = __(':count d', ['count' => $days]);
        }

        if ($remainingHours > 0 || $days === 0) {
            $parts[] = __(':count h', ['count' => $remainingHours]);
        }

        return implode(' ', $parts);
    }

    /** @return array{count: int, items: list<\App\Models\Task>} */
    public function myTasks(User $user, int $limit = 8): array
    {
        $query = $this->accessible($user)
            ->where('assignee_id', $user->id)
            ->whereIn('status', $this->openStatusValues());

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->orderByDesc('priority')
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->limit($limit)
                ->get(['id', 'number', 'title', 'priority', 'deadline', 'status']),
        ];
    }

    private function accessible(User $user)
    {
        return $this->visibility->accessibleQuery($user);
    }

    /** @return list<string> */
    private function openStatusValues(): array
    {
        return array_map(fn (TaskStatus $status) => $status->value, TaskStatus::open());
    }
}
