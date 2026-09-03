<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TaskActionQueueService
{
    public const REVIEW = 'review';

    public const AWAITING = 'awaiting';

    public const OVERDUE = 'overdue';

    public const TODO = 'todo';

    /** @var list<string> */
    public const SECTIONS = [self::REVIEW, self::AWAITING, self::OVERDUE, self::TODO];

    public function __construct(
        private TaskVisibilityService $visibility,
    ) {}

    public function count(User $user): int
    {
        return $this->applyScope($this->visibility->accessibleQuery($user), $user)->count();
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function applyScope(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where(function (Builder $review) use ($user): void {
                $this->applyReview($review, $user);
            })->orWhere(function (Builder $awaiting) use ($user): void {
                $this->applyAwaiting($awaiting, $user);
            })->orWhere(function (Builder $work) use ($user): void {
                $this->applyAssigneeWork($work, $user);
            });
        });
    }

    /**
     * @param  Builder<Task>  $query
     * @param  callable(Builder<Task>): void  $sort
     * @return array{
     *     items: Collection<int, Task>,
     *     group: array<int, string>,
     *     sections: list<array{key: string, label: string, count: int}>,
     *     count: int
     * }
     */
    public function buildSections(Builder $query, User $user, callable $sort): array
    {
        $items = collect();
        $group = [];
        $sections = [];

        foreach (self::SECTIONS as $key) {
            $sectionQuery = clone $query;
            $this->applySection($sectionQuery, $user, $key);
            $sort($sectionQuery);
            $tasks = $sectionQuery->limit(50)->get();

            $sections[] = [
                'key' => $key,
                'label' => $this->label($key),
                'count' => $tasks->count(),
            ];

            foreach ($tasks as $task) {
                $items->push($task);
                $group[$task->id] = $key;
            }
        }

        return [
            'items' => $items,
            'group' => $group,
            'sections' => $sections,
            'count' => $items->count(),
        ];
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function applySection(Builder $query, User $user, string $section): Builder
    {
        return match ($section) {
            self::REVIEW => $this->applyReview($query, $user),
            self::AWAITING => $this->applyAwaiting($query, $user),
            self::OVERDUE => $this->applyOverdue($query, $user),
            self::TODO => $this->applyTodo($query, $user),
            default => $query->whereRaw('0 = 1'),
        };
    }

    public function label(string $section): string
    {
        return match ($section) {
            self::REVIEW => __('Needs review'),
            self::AWAITING => __('Waiting on me'),
            self::OVERDUE => __('Overdue'),
            self::TODO => __('My work'),
            default => $section,
        };
    }

    /** @param  Builder<Task>  $query */
    private function applyReview(Builder $query, User $user): Builder
    {
        return $query
            ->where('status', TaskStatus::OnReview)
            ->where('initiator_id', $user->id);
    }

    /** @param  Builder<Task>  $query */
    private function applyAwaiting(Builder $query, User $user): Builder
    {
        return $query
            ->where('status', TaskStatus::AwaitingInitiator)
            ->where('initiator_id', $user->id);
    }

    /** @param  Builder<Task>  $query */
    private function applyAssigneeWork(Builder $query, User $user): Builder
    {
        return $query
            ->where('assignee_id', $user->id)
            ->whereIn('status', $this->assigneeWorkStatuses());
    }

    /** @param  Builder<Task>  $query */
    private function applyOverdue(Builder $query, User $user): Builder
    {
        return $this->applyAssigneeWork($query, $user)
            ->whereNotNull('deadline')
            ->where('deadline', '<', now());
    }

    /** @param  Builder<Task>  $query */
    private function applyTodo(Builder $query, User $user): Builder
    {
        return $this->applyAssigneeWork($query, $user)
            ->where(function (Builder $q): void {
                $q->whereNull('deadline')
                    ->orWhere('deadline', '>=', now());
            });
    }

    /** @return list<string> */
    private function assigneeWorkStatuses(): array
    {
        return [
            TaskStatus::New->value,
            TaskStatus::InProgress->value,
            TaskStatus::Rework->value,
        ];
    }
}
