<?php

namespace App\Services;

use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TaskApiService
{
    public function __construct(
        private TaskQueryService $query,
        private TaskPresenter $presenter,
        private TaskService $tasks,
        private TaskWorkflowService $workflow,
    ) {}

    public function actor(?User $user): User
    {
        if ($user === null) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $user, array $filters = []): array
    {
        $page = $this->query->paginate($user, $this->normalizeListFilters($filters));

        return [
            'data' => collect($page->items())->map(fn (Task $task) => $this->presenter->summary($task))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $number): array
    {
        return $this->presenter->detail($this->query->findByNumber($user, $number), $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload): array
    {
        $data = Validator::validate($payload, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'assignee_email' => ['nullable', 'email'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'deadline' => ['nullable', 'date'],
            'spec_url' => ['nullable', 'url', 'max:2048'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:500'],
            'watcher_ids' => ['nullable', 'array'],
            'watcher_ids.*' => ['integer', 'exists:users,id'],
            'watcher_emails' => ['nullable', 'array'],
            'watcher_emails.*' => ['email'],
            'parent_number' => ['nullable', 'integer'],
        ]);

        $departmentId = $data['department_id'] ?? $this->resolveDepartmentId($data['department'] ?? null);
        $categoryId = $data['category_id'] ?? $this->resolveCategoryId($data['category'] ?? null);

        $missing = [];
        if ($departmentId === null) {
            $missing['department'] = [__('validation.required', ['attribute' => 'department'])];
        }
        if ($categoryId === null) {
            $missing['category'] = [__('validation.required', ['attribute' => 'category'])];
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $assigneeId = $data['assignee_id'] ?? null;
        if ($assigneeId === null && ! empty($data['assignee_email'])) {
            $assigneeId = User::query()->where('email', $data['assignee_email'])->value('id');
            if ($assigneeId === null) {
                throw ValidationException::withMessages([
                    'assignee_email' => [__('validation.exists', ['attribute' => 'assignee_email'])],
                ]);
            }
        }

        $watcherIds = $data['watcher_ids'] ?? [];
        foreach ($data['watcher_emails'] ?? [] as $email) {
            $id = User::query()->where('email', $email)->value('id');
            if ($id === null) {
                throw ValidationException::withMessages([
                    'watcher_emails' => [__('validation.exists', ['attribute' => 'watcher_emails'])],
                ]);
            }
            $watcherIds[] = $id;
        }

        $parentId = null;
        if (! empty($data['parent_number'])) {
            $parentId = $this->query->findByNumber($user, (int) $data['parent_number'])->id;
        }

        $task = $this->tasks->create($user, [
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'assignee_id' => $assigneeId,
            'priority' => $data['priority'] ?? 5,
            'deadline' => $data['deadline'] ?? null,
            'spec_url' => $data['spec_url'] ?? null,
            'parent_id' => $parentId,
        ], $data['checklist'] ?? [], array_values(array_unique($watcherIds)), ContentSource::PlainText);

        return $this->show($user, $task->number);
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(User $user, int $number, string $body): array
    {
        $task = $this->query->findByNumber($user, $number);
        $comment = $this->tasks->addComment($task, $user, $body, ContentSource::PlainText);

        return $this->presenter->comment($comment);
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(User $user, int $number, string $status, ?string $comment = null): array
    {
        $to = TaskStatus::tryFrom($status);
        if ($to === null) {
            throw ValidationException::withMessages([
                'status' => [__('validation.in', ['attribute' => 'status'])],
            ]);
        }

        $task = $this->query->findByNumber($user, $number);

        try {
            $this->workflow->transition($task, $user, $to, $comment, ContentSource::PlainText);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'status' => [$e->getMessage()],
            ]);
        }

        return $this->show($user, $number);
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogs(): array
    {
        return [
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->all(),
            'categories' => Category::query()->active()->ordered()->get(['id', 'name'])
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'statuses' => array_map(fn (TaskStatus $s) => $s->value, TaskStatus::cases()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(): array
    {
        $users = User::query()
            ->where('is_active', true)
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id', 'system_type']);

        return $this->presenter->users($users);
    }

    private function normalizeListFilters(array $filters): array
    {
        if (empty($filters['assignee_id']) && ! empty($filters['assignee_email'])) {
            $filters['assignee_id'] = User::query()->where('email', $filters['assignee_email'])->value('id');
        }

        if (empty($filters['initiator_id']) && ! empty($filters['initiator_email'])) {
            $filters['initiator_id'] = User::query()->where('email', $filters['initiator_email'])->value('id');
        }

        if (empty($filters['department_id']) && ! empty($filters['department'])) {
            $filters['department_id'] = $this->resolveDepartmentId($filters['department']);
        }

        if (empty($filters['category_id']) && ! empty($filters['category'])) {
            $filters['category_id'] = $this->resolveCategoryId($filters['category']);
        }

        unset($filters['assignee_email'], $filters['initiator_email'], $filters['department'], $filters['category']);

        return $filters;
    }

    private function resolveDepartmentId(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return Department::query()->active()->where('name', $name)->value('id');
    }

    private function resolveCategoryId(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return Category::query()->active()->where('name', $name)->value('id');
    }
}
