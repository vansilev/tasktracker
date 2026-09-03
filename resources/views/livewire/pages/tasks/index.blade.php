<?php



use App\Enums\ContentSource;
use App\Enums\TaskStatus;

use App\Models\Category;

    use App\Models\Department;

use App\Models\SavedFilter;

use App\Models\Task;

use App\Models\User;

use App\Services\TaskActionQueueService;

use App\Services\TaskService;

use App\Services\TaskVisibilityService;

    use App\Services\TaskWorkflowService;

use Carbon\Carbon;

use Illuminate\Auth\Access\AuthorizationException;

use Illuminate\Validation\ValidationException;

use Livewire\Attributes\Layout;

    use Livewire\Attributes\On;

use Livewire\Attributes\Url;

use Livewire\Volt\Component;

use Livewire\WithPagination;



new #[Layout('components.tasks-layout')] class extends Component

{

    use WithPagination;



    #[Url]

    public string $tab = 'assigned';



    #[Url]

    public string $search = '';



    #[Url]

    public string $status = '';



    #[Url]

    public ?int $departmentId = null;



    #[Url]

    public ?int $categoryId = null;



    #[Url]

    public bool $urgentOnly = false;



    #[Url]

    public ?int $priorityMin = null;



    #[Url]

    public ?int $priorityMax = null;



    #[Url]

    public ?int $assigneeId = null;



    #[Url]

    public ?int $initiatorId = null;



    #[Url]

    public string $periodType = 'created_at';



    #[Url]

    public ?string $periodFrom = null;



    #[Url]

    public ?string $periodTo = null;



    #[Url]

    public bool $overdueOnly = false;



    #[Url]

    public string $sortBy = 'priority';



    #[Url]

    public string $sortDir = 'desc';



    public bool $filtersOpen = false;

    #[Url]
    public string $layout = 'list';

    #[Url]
    public ?int $peek = null;

    public array $selectedIds = [];

    public ?string $pendingBulkStatus = null;

    public ?int $bulkAssigneeId = null;

    public string $bulkComment = '';

    public string $savedFilterName = '';

    public ?int $activeSavedFilterId = null;

    public ?int $pendingKanbanId = null;

    public ?string $pendingKanbanStatus = null;

    public string $kanbanComment = '';

    public string $pendingKanbanTaskLabel = '';

    public string $pendingKanbanToLabel = '';

    public function mount(): void
    {
        if (! $this->requestHasListState()) {
            $this->restorePersistedUiState();
        }

        $default = SavedFilter::query()
            ->where('user_id', auth()->id())
            ->where('is_default', true)
            ->first();

        if ($default && $this->activeFilterCount() === 0 && $this->tab === 'assigned' && $this->layout === 'list' && $this->sortBy === 'priority' && $this->sortDir === 'desc') {
            $this->applySavedFilterValues($default);
        }

        $this->filtersOpen = $this->activeFilterCount() > 0;

        if ($this->peek) {
            $this->openPeek($this->peek);
        }
    }



    public function with(): array

    {

        $user = auth()->user();

        $query = app(TaskVisibilityService::class)->accessibleQuery($user)

            ->with(['initiator:id,name', 'assignee:id,name', 'department:id,name', 'category:id,name', 'parent:id,number,title', 'blockers:id,number,status']);



        $queue = app(TaskActionQueueService::class);

        $query = match ($this->tab) {

            'created' => $query->where('initiator_id', $user->id),

            'watching' => $query->whereHas('watchers', fn ($q) => $q->where('user_id', $user->id)),

            'department' => $user->headedDepartments()->exists()
                ? $query->whereIn('department_id', $user->headedDepartments()->pluck('id'))
                : ($user->department_id
                    ? $query->where('department_id', $user->department_id)
                    : $query->whereRaw('0 = 1')),

            'all' => $query,

            'action' => $queue->applyScope($query, $user),

            default => $query->where('assignee_id', $user->id),

        };



        if ($this->search !== '') {

            $s = '%'.$this->search.'%';

            $query->where(function ($q) use ($s) {

                $q->where('number', 'like', $s)
                    ->orWhere('title', 'like', $s)
                    ->orWhereRaw('COALESCE(description_text, description) LIKE ?', [$s])

                    ->orWhereHas('comments', fn ($cq) => $cq->whereRaw('COALESCE(body_text, body) LIKE ?', [$s]));

            });

        }



        if ($this->status !== '' && $this->layout !== 'board') {

            $query->where('status', $this->status);

        }



        if ($this->departmentId) {

            $query->where('department_id', $this->departmentId);

        }



        if ($this->categoryId) {

            $query->where('category_id', $this->categoryId);

        }



        if ($this->urgentOnly) {

            $query->where('priority', '>=', 9);

        }



        if ($this->priorityMin !== null) {

            $query->where('priority', '>=', $this->priorityMin);

        }



        if ($this->priorityMax !== null) {

            $query->where('priority', '<=', $this->priorityMax);

        }



        if ($this->assigneeId) {

            $query->where('assignee_id', $this->assigneeId);

        }



        if ($this->initiatorId) {

            $query->where('initiator_id', $this->initiatorId);

        }



        $periodFromDate = $this->parseDate($this->periodFrom);
        $periodToDate = $this->parseDate($this->periodTo);

        if ($periodFromDate !== null || $periodToDate !== null) {
            $column = $this->periodType === 'deadline' ? 'deadline' : 'created_at';

            if ($periodFromDate !== null) {
                $query->where($column, '>=', $periodFromDate->startOfDay());
            }

            if ($periodToDate !== null) {
                $query->where($column, '<=', $periodToDate->endOfDay());
            }
        }



        if ($this->overdueOnly) {

            $query->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))

                ->whereNotNull('deadline')

                ->where('deadline', '<', now());

        }



        $actionGroup = [];
        $actionSections = [];
        $actionCount = $queue->count($user);

        $boardColumns = [];
        if ($this->layout === 'board') {
            $this->applySorting($query);
            $boardColumns = $this->buildBoardColumns($query);
            $tasks = new \Illuminate\Pagination\LengthAwarePaginator(collect(), 0, 25);
        } elseif ($this->tab === 'action') {
            $built = $queue->buildSections($query, $user, function ($sectionQuery) {
                $this->applySorting($sectionQuery);
            });
            $actionGroup = $built['group'];
            $actionSections = collect($built['sections'])->keyBy('key');
            $tasks = new \Illuminate\Pagination\LengthAwarePaginator(
                $built['items'],
                $built['items']->count(),
                max($built['items']->count(), 1),
                1,
            );
        } else {
            $this->applySorting($query);
            $tasks = $query->paginate(25);
            $this->nestSubtasksOnPage($tasks);
        }
        $selectedTasks = $this->selectedTasks();

        return [

            'tasks' => $tasks,

            'boardColumns' => $boardColumns,

            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),

            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),

            'statuses' => TaskStatus::cases(),

            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),

            'activeFilterCount' => $this->activeFilterCount(),

            'actionGroup' => $actionGroup,

            'actionSections' => $actionSections,

            'actionCount' => $actionCount,

            'bulkTransitions' => $this->sharedTransitions($selectedTasks),

            'bulkAssigneeOptions' => $this->bulkAssigneeOptions($selectedTasks),

            'canBulkWatch' => $selectedTasks->contains(fn (Task $task) => $user->can('manageWatchers', $task)),

            'savedFilters' => SavedFilter::query()
                ->where('user_id', $user->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ];

    }



    private function applySorting($query): void
    {
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';

        match ($this->sortBy) {
            'title' => $query
                ->orderByRaw("CASE WHEN title IS NULL OR title = '' THEN 1 ELSE 0 END")
                ->orderBy('title', $dir)
                ->orderBy('id'),
            'deadline' => $query
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline', $dir)
                ->orderBy('id'),
            'created_at' => $query->orderBy('created_at', $dir)->orderBy('id'),
            'status' => $query
                ->orderByRaw($this->statusSortSql().' '.$dir)
                ->orderBy('id'),
            'department' => $query
                ->leftJoin('departments', 'departments.id', '=', 'tasks.department_id')
                ->select('tasks.*')
                ->orderBy('departments.name', $dir)
                ->orderBy('tasks.id'),
            default => $query
                ->orderBy('priority', $dir)
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->orderBy('id'),
        };
    }

    private function statusSortSql(): string
    {
        $whens = [];
        foreach (TaskStatus::cases() as $index => $status) {
            $whens[] = "WHEN '{$status->value}' THEN {$index}";
        }

        return 'CASE status '.implode(' ', $whens).' ELSE 99 END';
    }



    private function activeFilterCount(): int

    {

        $count = 0;



        if ($this->search !== '') {

            $count++;

        }

        if ($this->status !== '') {

            $count++;

        }

        if ($this->departmentId) {

            $count++;

        }

        if ($this->categoryId) {

            $count++;

        }

        if ($this->urgentOnly) {

            $count++;

        }

        if ($this->priorityMin !== null) {

            $count++;

        }

        if ($this->priorityMax !== null) {

            $count++;

        }

        if ($this->assigneeId) {

            $count++;

        }

        if ($this->initiatorId) {

            $count++;

        }

        if ($this->parseDate($this->periodFrom) !== null || $this->parseDate($this->periodTo) !== null) {
            $count++;
        }

        if ($this->overdueOnly) {

            $count++;

        }



        return $count;
    }

    /** @return list<TaskStatus> */
    private function boardStatuses(): array
    {
        return [
            TaskStatus::New,
            TaskStatus::InProgress,
            TaskStatus::AwaitingInitiator,
            TaskStatus::OnReview,
            TaskStatus::Rework,
            TaskStatus::Postponed,
            TaskStatus::Completed,
        ];
    }

    private function buildBoardColumns($query): array
    {
        $columns = [];

        foreach ($this->boardStatuses() as $status) {
            $columnQuery = (clone $query)->where('status', $status->value);
            $total = (clone $columnQuery)->toBase()->getCountForPagination();
            $items = $columnQuery->limit(40)->get();

            $columns[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'badge' => $status->badgeClasses(),
                'total' => $total,
                'tasks' => $items,
            ];
        }

        return $columns;
    }

    public function setLayout(string $layout): void
    {
        if (! in_array($layout, ['list', 'board'], true)) {
            return;
        }

        $this->layout = $layout;
        $this->pendingKanbanId = null;
        $this->pendingKanbanStatus = null;
        $this->pendingKanbanTaskLabel = '';
        $this->pendingKanbanToLabel = '';
        $this->kanbanComment = '';
        $this->resetPage();
        $this->clearSelection();
        $this->persistUiState();
    }

    public function kanbanMove(int $taskId, string $status): void
    {
        $task = $this->accessibleTask($taskId);
        $target = TaskStatus::from($status);

        if ($task->status === $target) {
            return;
        }

        if (TaskStatus::requiresComment($target, $task->status)) {
            $this->pendingKanbanId = $task->id;
            $this->pendingKanbanStatus = $target->value;
            $this->pendingKanbanTaskLabel = '#'.$task->number.' · '.($task->title ?: $task->plainDescription());
            $this->pendingKanbanToLabel = $target->label();
            $this->kanbanComment = '';

            return;
        }

        $this->applyKanbanTransition($task, $target, null);
    }

    public function confirmKanbanMove(): void
    {
        if (! $this->pendingKanbanId || ! $this->pendingKanbanStatus) {
            return;
        }

        $task = $this->accessibleTask($this->pendingKanbanId);
        $target = TaskStatus::from($this->pendingKanbanStatus);
        $comment = trim($this->kanbanComment);

        if (TaskStatus::requiresComment($target, $task->status) && $comment === '') {
            $this->js('window.uiToast('.json_encode(__('Add a comment for this status change')).')');

            return;
        }

        $this->applyKanbanTransition($task, $target, $comment);
        $this->clearKanbanPrompt();
    }

    public function cancelKanbanMove(): void
    {
        $this->clearKanbanPrompt();
    }

    private function clearKanbanPrompt(): void
    {
        $this->pendingKanbanId = null;
        $this->pendingKanbanStatus = null;
        $this->pendingKanbanTaskLabel = '';
        $this->pendingKanbanToLabel = '';
        $this->kanbanComment = '';
    }

    private function applyKanbanTransition(Task $task, TaskStatus $target, ?string $comment): void
    {
        try {
            $undo = app(TaskWorkflowService::class)->transition(
                $task,
                auth()->user(),
                $target,
                $comment,
                ContentSource::PlainText,
            );
            $this->js(app(TaskWorkflowService::class)->undoToastScript(
                __('Status changed to :status', ['status' => $target->label()]),
                auth()->user(),
                [$undo],
            ));
            $this->dispatch('task-peek-updated');
        } catch (\InvalidArgumentException|AuthorizationException $e) {
            $this->js('window.uiToast('.json_encode($e->getMessage()).')');
        }
    }

    private function nestSubtasksOnPage($paginator): void
    {
        $onPage = $paginator->getCollection();
        $idsOnPage = $onPage->pluck('id');

        $onPage->load([
            'subtasks' => fn ($q) => $q->with(['assignee:id,name', 'department:id,name', 'blockers:id,number,status']),
        ]);

        $roots = $onPage->reject(
            fn (Task $task) => $task->parent_id && $idsOnPage->contains($task->parent_id)
        )->values();

        $paginator->setCollection($roots);
    }



    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }



    public function resetFilters(): void

    {

        $this->search = '';

        $this->status = '';

        $this->departmentId = null;

        $this->categoryId = null;

        $this->urgentOnly = false;

        $this->priorityMin = null;

        $this->priorityMax = null;

        $this->assigneeId = null;

        $this->initiatorId = null;

        $this->periodType = 'created_at';

        $this->periodFrom = null;

        $this->periodTo = null;

        $this->overdueOnly = false;

        $this->sortBy = 'priority';

        $this->sortDir = 'desc';

        $this->resetPage();
        $this->persistUiState();

    }



    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
        $this->persistUiState();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['title', 'status', 'priority', 'department', 'deadline'];
        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'priority' ? 'desc' : 'asc';
        }

        $this->resetPage();
        $this->persistUiState();
    }

    #[On('task-quick-transition')]
    public function quickTransition(int $taskId, string $status, ?string $comment = null): void
    {
        $task = $this->accessibleTask($taskId);
        $target = TaskStatus::from($status);

        try {
            $undo = app(TaskWorkflowService::class)->transition(
                $task,
                auth()->user(),
                $target,
                $comment,
                ContentSource::PlainText,
            );
            $this->js(app(TaskWorkflowService::class)->undoToastScript(
                __('Status changed to :status', ['status' => $target->label()]),
                auth()->user(),
                [$undo],
            ));
        } catch (\InvalidArgumentException|AuthorizationException $e) {
            $this->js('window.uiToast('.json_encode($e->getMessage()).')');
        }
    }

    #[On('task-quick-assign')]
    public function quickAssign(int $taskId, int $userId, string $comment = ''): void
    {
        $task = $this->accessibleTask($taskId);
        abort_unless(auth()->user()->can('assign', $task), 403);

        $changing = (int) $task->assignee_id !== $userId;
        if ($changing && trim($comment) === '') {
            $this->js('window.uiToast('.json_encode(__('Reassignment comment')).')');

            return;
        }

        try {
            app(TaskService::class)->update($task, auth()->user(), [
                'assignee_id' => $userId,
            ]);

            if ($changing) {
                app(TaskService::class)->addComment($task, auth()->user(), $comment, ContentSource::PlainText);
            }

            $name = User::query()->whereKey($userId)->value('name') ?? '';
            $this->js('window.uiToast('.json_encode(__('Assigned to :name', ['name' => $name])).')');
        } catch (ValidationException $e) {
            $this->js('window.uiToast('.json_encode(collect($e->errors())->flatten()->first()).')');
        } catch (AuthorizationException $e) {
            $this->js('window.uiToast('.json_encode($e->getMessage()).')');
        }
    }

    public function rowActions(Task $task): array
    {
        $user = auth()->user();
        $transitions = collect(app(TaskWorkflowService::class)->allowedTransitions($user, $task))
            ->map(fn (TaskStatus $status) => [
                'value' => $status->value,
                'label' => $task->status === TaskStatus::Completed && $status === TaskStatus::InProgress
                    ? __('task.reopen')
                    : $status->label(),
                'needsComment' => TaskStatus::requiresComment($status, $task->status),
                'destructive' => in_array($status, [TaskStatus::Rejected, TaskStatus::Cancelled], true),
            ])
            ->values()
            ->all();

        $canAssign = $user->can('assign', $task);
        $assignees = [];
        if ($canAssign) {
            $assignees = User::query()
                ->where('is_active', true)
                ->where('department_id', $task->department_id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name])
                ->all();
        }

        return [
            'id' => $task->id,
            'number' => $task->number,
            'url' => route('tasks.show', $task),
            'copyMessage' => __('Link to #:number copied', ['number' => $task->number]),
            'transitions' => $transitions,
            'canAssign' => $canAssign,
            'assigneeId' => $task->assignee_id,
            'assignees' => $assignees,
        ];
    }

    private function accessibleTask(int $taskId): Task
    {
        $task = app(TaskVisibilityService::class)
            ->accessibleQuery(auth()->user())
            ->whereKey($taskId)
            ->first();

        abort_unless($task, 404);

        return $task;
    }

    /** @return list<int> */
    private function selectedIdList(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->selectedIds))));
    }

    private function selectedTasks(): \Illuminate\Support\Collection
    {
        $ids = $this->selectedIdList();
        if ($ids === []) {
            return collect();
        }

        return app(TaskVisibilityService::class)
            ->accessibleQuery(auth()->user())
            ->whereIn('id', $ids)
            ->get();
    }

    private function sharedTransitions(\Illuminate\Support\Collection $tasks): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }

        $user = auth()->user();
        $workflow = app(TaskWorkflowService::class);
        $sets = $tasks->map(function (Task $task) use ($user, $workflow) {
            return collect($workflow->allowedTransitions($user, $task))
                ->map(fn (TaskStatus $status) => $status->value);
        });

        $shared = $sets->reduce(
            fn ($carry, $set) => $carry === null ? $set : $carry->intersect($set)->values()
        );

        return collect($shared)->map(function (string $value) use ($tasks) {
            $status = TaskStatus::from($value);
            $needsComment = $tasks->contains(
                fn (Task $task) => TaskStatus::requiresComment($status, $task->status)
            );
            $reopen = $tasks->contains(
                fn (Task $task) => $task->status === TaskStatus::Completed && $status === TaskStatus::InProgress
            );

            return [
                'value' => $status->value,
                'label' => $reopen ? __('task.reopen') : $status->label(),
                'needsComment' => $needsComment,
                'destructive' => in_array($status, [TaskStatus::Rejected, TaskStatus::Cancelled], true),
            ];
        })->values()->all();
    }

    private function bulkAssigneeOptions(\Illuminate\Support\Collection $tasks): array
    {
        $user = auth()->user();
        $assignable = $tasks->filter(fn (Task $task) => $user->can('assign', $task));
        $departmentIds = $assignable->pluck('department_id')->unique()->filter()->values();

        if ($assignable->isEmpty() || $departmentIds->count() !== 1) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->where('department_id', $departmentIds->first())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $person) => ['value' => $person->id, 'label' => $person->name])
            ->all();
    }

    private function applyBulkTransition(string $status): void
    {
        $target = TaskStatus::from($status);
        $tasks = $this->selectedTasks();
        $comment = trim($this->bulkComment);
        $needsComment = $tasks->contains(
            fn (Task $task) => TaskStatus::requiresComment($target, $task->status)
        );

        if ($needsComment && $comment === '') {
            $this->js('window.uiToast('.json_encode(__('Add a comment for this status change')).')');

            return;
        }

        $done = 0;
        $user = auth()->user();
        $workflow = app(TaskWorkflowService::class);
        $undoItems = [];

        foreach ($tasks as $task) {
            try {
                $undoItems[] = $workflow->transition(
                    $task,
                    $user,
                    $target,
                    $needsComment ? $comment : null,
                    ContentSource::PlainText,
                );
                $done++;
            } catch (\InvalidArgumentException|AuthorizationException) {
                // Skip tasks that cannot take this transition.
            }
        }

        $this->toastBulkResult($done, $tasks->count(), $undoItems);

        if ($done > 0) {
            $this->clearSelection();
            $this->dispatch('task-peek-updated');
        }
    }

    private function applyBulkAssign(int $userId): void
    {
        $user = auth()->user();
        $tasks = $this->selectedTasks();
        $comment = trim($this->bulkComment);
        $changing = $tasks->filter(
            fn (Task $task) => $user->can('assign', $task) && (int) $task->assignee_id !== $userId
        );

        if ($changing->isNotEmpty() && $comment === '') {
            $this->js('window.uiToast('.json_encode(__('Reassignment comment')).')');

            return;
        }

        $done = 0;
        $service = app(TaskService::class);

        foreach ($tasks as $task) {
            if (! $user->can('assign', $task)) {
                continue;
            }

            $isChange = (int) $task->assignee_id !== $userId;

            try {
                $service->update($task, $user, ['assignee_id' => $userId]);

                if ($isChange) {
                    $service->addComment($task, $user, $comment, ContentSource::PlainText);
                }

                $done++;
            } catch (ValidationException|AuthorizationException) {
                // Skip tasks that cannot be assigned to this user.
            }
        }

        $this->toastBulkResult($done, $tasks->count());

        if ($done > 0) {
            $this->clearSelection();
            $this->dispatch('task-peek-updated');
        }
    }

    private function toastBulkResult(int $done, int $total, array $undoItems = []): void
    {
        $message = __('Updated :done of :total', [
            'done' => $done,
            'total' => $total,
        ]);

        if ($undoItems !== []) {
            $this->js(app(TaskWorkflowService::class)->undoToastScript($message, auth()->user(), $undoItems));

            return;
        }

        $this->js('window.uiToast('.json_encode($message).')');
    }

    #[On('task-open-peek')]
    public function openPeek(int $number): void
    {
        if ($number < 1) {
            $this->peek = null;

            return;
        }

        $visible = app(TaskVisibilityService::class)
            ->accessibleQuery(auth()->user())
            ->where('number', $number)
            ->exists();

        $this->peek = $visible ? $number : null;
    }

    #[On('task-close-peek')]
    public function closePeek(): void
    {
        $this->peek = null;
    }

    #[On('task-peek-updated')]
    public function onPeekUpdated(): void
    {
        // Re-render the list so status/assignee match the peek panel.
    }

    public function isSelected(int $id): bool
    {
        return in_array($id, $this->selectedIdList(), true);
    }

    public function toggleSelected(int $id): void
    {
        if ($id < 1) {
            return;
        }

        $selected = $this->selectedIdList();
        if (in_array($id, $selected, true)) {
            $selected = array_values(array_filter($selected, fn (int $item) => $item !== $id));
        } else {
            $selected[] = $id;
        }

        $this->selectedIds = array_map(fn (int $item) => (string) $item, $selected);
    }

    public function toggleSelectPage(string $ids): void
    {
        $pageIds = array_values(array_filter(array_map('intval', explode(',', $ids))));
        if ($pageIds === []) {
            return;
        }

        $selected = $this->selectedIdList();
        $allOnPage = collect($pageIds)->every(fn (int $id) => in_array($id, $selected, true));

        if ($allOnPage) {
            $selected = array_values(array_diff($selected, $pageIds));
        } else {
            $selected = array_values(array_unique([...$selected, ...$pageIds]));
        }

        $this->selectedIds = array_map(fn (int $id) => (string) $id, $selected);
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
        $this->pendingBulkStatus = null;
        $this->bulkAssigneeId = null;
        $this->bulkComment = '';
    }

    public function updatedBulkAssigneeId(mixed $value): void
    {
        if ($value) {
            $this->pendingBulkStatus = null;
        }
    }

    public function chooseBulkStatus(string $status): void
    {
        $this->bulkAssigneeId = null;
        $target = TaskStatus::from($status);
        $needsComment = $this->selectedTasks()
            ->contains(fn (Task $task) => TaskStatus::requiresComment($target, $task->status));

        if ($needsComment) {
            $this->pendingBulkStatus = $status;

            return;
        }

        $this->pendingBulkStatus = null;
        $this->applyBulkTransition($status);
    }

    public function confirmBulkAction(): void
    {
        if ($this->pendingBulkStatus) {
            $this->applyBulkTransition($this->pendingBulkStatus);

            return;
        }

        if ($this->bulkAssigneeId) {
            $this->applyBulkAssign((int) $this->bulkAssigneeId);
        }
    }

    public function bulkWatch(bool $watch = true): void
    {
        $user = auth()->user();
        $tasks = $this->selectedTasks();
        $done = 0;

        foreach ($tasks as $task) {
            if (! $user->can('manageWatchers', $task)) {
                continue;
            }

            if ($watch) {
                $task->watchers()->syncWithoutDetaching([$user->id]);
            } else {
                $task->watchers()->detach($user->id);
            }

            $done++;
        }

        $message = $watch
            ? __('Now watching :count tasks', ['count' => $done])
            : __('Stopped watching :count tasks', ['count' => $done]);
        $this->js('window.uiToast('.json_encode($message).')');

        if ($done > 0) {
            $this->clearSelection();
            $this->dispatch('task-peek-updated');
        }
    }

    public function bulkUnwatch(): void
    {
        $this->bulkWatch(false);
    }

    // ── Saved Filters ──────────────────────────────────────────

    private function currentFiltersPayload(): array
    {
        return [
            'tab' => $this->tab,
            'search' => $this->search,
            'status' => $this->status,
            'departmentId' => $this->departmentId,
            'categoryId' => $this->categoryId,
            'urgentOnly' => $this->urgentOnly,
            'priorityMin' => $this->priorityMin,
            'priorityMax' => $this->priorityMax,
            'assigneeId' => $this->assigneeId,
            'initiatorId' => $this->initiatorId,
            'periodType' => $this->periodType,
            'periodFrom' => $this->periodFrom,
            'periodTo' => $this->periodTo,
            'overdueOnly' => $this->overdueOnly,
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
            'layout' => $this->layout,
        ];
    }

    private function requestHasListState(): bool
    {
        return request()->hasAny([
            'tab', 'layout', 'search', 'status', 'departmentId', 'categoryId',
            'urgentOnly', 'priorityMin', 'priorityMax', 'assigneeId', 'initiatorId',
            'periodType', 'periodFrom', 'periodTo', 'overdueOnly', 'sortBy', 'sortDir',
        ]);
    }

    private function persistUiState(): void
    {
        $payload = $this->currentFiltersPayload();
        $payload['userId'] = auth()->id();
        cookie()->queue(cookie('tasktracker_tasks_ui', json_encode($payload), 60 * 24 * 400));
        $this->js('window.taskUiState && window.taskUiState.save('.json_encode($payload).')');
    }

    private function restorePersistedUiState(): void
    {
        $userId = (int) auth()->id();
        $raw = request()->cookie('tasktracker_tasks_ui');
        $fromCookie = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($fromCookie) && (int) ($fromCookie['userId'] ?? 0) === $userId) {
            $this->applyUiState($fromCookie);

            return;
        }

        $this->js('const saved = window.taskUiState?.load('.$userId.'); if (saved && typeof saved === "object") { $wire.restoreUiState(saved); }');
    }

    public function restoreUiState(array $state): void
    {
        $this->applyUiState($state);
        $this->filtersOpen = $this->activeFilterCount() > 0;
        $this->persistUiState();
    }

    private function applyUiState(array $f): void
    {
        $tabs = ['action', 'assigned', 'created', 'watching', 'department', 'all'];
        if (isset($f['tab']) && in_array($f['tab'], $tabs, true)) {
            $this->tab = $f['tab'];
        }
        $this->search = is_string($f['search'] ?? null) ? $f['search'] : $this->search;
        $statuses = array_map(fn (TaskStatus $status) => $status->value, TaskStatus::cases());
        if (array_key_exists('status', $f) && ($f['status'] === '' || in_array($f['status'], $statuses, true))) {
            $this->status = $f['status'];
        }
        $this->departmentId = isset($f['departmentId']) ? ($f['departmentId'] !== null ? (int) $f['departmentId'] : null) : $this->departmentId;
        $this->categoryId = isset($f['categoryId']) ? ($f['categoryId'] !== null ? (int) $f['categoryId'] : null) : $this->categoryId;
        $this->urgentOnly = array_key_exists('urgentOnly', $f) ? (bool) $f['urgentOnly'] : $this->urgentOnly;
        $this->priorityMin = array_key_exists('priorityMin', $f) ? ($f['priorityMin'] !== null ? (int) $f['priorityMin'] : null) : $this->priorityMin;
        $this->priorityMax = array_key_exists('priorityMax', $f) ? ($f['priorityMax'] !== null ? (int) $f['priorityMax'] : null) : $this->priorityMax;
        $this->assigneeId = isset($f['assigneeId']) ? ($f['assigneeId'] !== null ? (int) $f['assigneeId'] : null) : $this->assigneeId;
        $this->initiatorId = isset($f['initiatorId']) ? ($f['initiatorId'] !== null ? (int) $f['initiatorId'] : null) : $this->initiatorId;
        if (isset($f['periodType']) && in_array($f['periodType'], ['created_at', 'deadline'], true)) {
            $this->periodType = $f['periodType'];
        }
        $this->periodFrom = array_key_exists('periodFrom', $f) ? $f['periodFrom'] : $this->periodFrom;
        $this->periodTo = array_key_exists('periodTo', $f) ? $f['periodTo'] : $this->periodTo;
        $this->overdueOnly = array_key_exists('overdueOnly', $f) ? (bool) $f['overdueOnly'] : $this->overdueOnly;
        $sorts = ['priority', 'title', 'status', 'department', 'deadline'];
        if (isset($f['sortBy']) && in_array($f['sortBy'], $sorts, true)) {
            $this->sortBy = $f['sortBy'];
        }
        if (isset($f['sortDir']) && in_array($f['sortDir'], ['asc', 'desc'], true)) {
            $this->sortDir = $f['sortDir'];
        }
        $savedLayout = $f['layout'] ?? $this->layout;
        $this->layout = in_array($savedLayout, ['list', 'board'], true) ? $savedLayout : $this->layout;
        $this->resetPage();
        $this->clearSelection();
        $this->persistUiState();
    }

    private function applySavedFilterValues(SavedFilter $filter): void
    {
        $this->applyUiState($filter->filters ?? []);
        $this->activeSavedFilterId = $filter->id;
        $this->filtersOpen = $this->activeFilterCount() > 0;
        $this->persistUiState();
    }

    public function saveCurrentFilter(): void
    {
        $name = trim($this->savedFilterName);
        if ($name === '') {
            return;
        }

        $filter = SavedFilter::create([
            'user_id' => auth()->id(),
            'name' => $name,
            'filters' => $this->currentFiltersPayload(),
        ]);

        $this->savedFilterName = '';
        $this->activeSavedFilterId = $filter->id;
        $this->js('window.uiToast('.json_encode(__('View saved')).')');
    }

    public function loadSavedFilter(int $id): void
    {
        $filter = SavedFilter::query()
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->first();

        if (! $filter) {
            return;
        }

        $this->applySavedFilterValues($filter);
    }

    public function updateSavedFilter(int $id): void
    {
        $filter = SavedFilter::query()
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->first();

        if (! $filter) {
            return;
        }

        $filter->update(['filters' => $this->currentFiltersPayload()]);
        $this->activeSavedFilterId = $filter->id;
        $this->js('window.uiToast('.json_encode(__('View updated')).')');
    }

    public function toggleDefaultFilter(int $id): void
    {
        $filter = SavedFilter::query()
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->first();

        if (! $filter) {
            return;
        }

        SavedFilter::query()
            ->where('user_id', auth()->id())
            ->where('id', '!=', $id)
            ->update(['is_default' => false]);

        $filter->update(['is_default' => ! $filter->is_default]);
    }

    public function deleteSavedFilter(int $id): void
    {
        SavedFilter::query()
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->delete();

        if ($this->activeSavedFilterId === $id) {
            $this->activeSavedFilterId = null;
        }

        $this->js('window.uiToast('.json_encode(__('View deleted')).')');
    }

    public function renameSavedFilter(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        SavedFilter::query()
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->update(['name' => $name]);
    }

    public function updated($property): void

    {

        if (in_array($property, [

            'tab', 'search', 'status', 'departmentId', 'categoryId', 'urgentOnly',

            'priorityMin', 'priorityMax', 'assigneeId', 'initiatorId',

            'periodType', 'periodFrom', 'periodTo', 'overdueOnly', 'sortBy', 'sortDir',

        ], true)) {

            $this->resetPage();
            $this->clearSelection();
            $this->persistUiState();

        }

    }



    /** @return array{text: string, class: string} */
    public function deadlineMeta(Task $task): array
    {
        if ($task->deadline === null) {
            return ['text' => '—', 'class' => 'text-gray-700'];
        }

        $deadline = $task->deadline->timezone(config('app.timezone'));
        $formatted = $deadline->format('d.m.Y');

        if (! $task->status->isOpen()) {
            return ['text' => $formatted, 'class' => 'text-gray-700'];
        }

        $now = now()->timezone(config('app.timezone'));

        if ($deadline->isPast()) {
            $days = (int) $deadline->diffInDays($now);

            return [
                'text' => __(':date (overdue :count days)', ['date' => $formatted, 'count' => $days]),
                'class' => 'text-red-600 font-medium',
            ];
        }

        if ((int) $now->diffInDays($deadline) <= 3) {
            return [
                'text' => $deadline->diffForHumans(),
                'class' => 'text-amber-600 font-medium',
            ];
        }

        return ['text' => $formatted, 'class' => 'text-gray-700'];
    }



    public function clearFilter(string $key): void
    {
        switch ($key) {
            case 'status':
                $this->status = '';
                break;
            case 'departmentId':
                $this->departmentId = null;
                break;
            case 'categoryId':
                $this->categoryId = null;
                break;
            case 'assigneeId':
                $this->assigneeId = null;
                break;
            case 'initiatorId':
                $this->initiatorId = null;
                break;
            case 'period':
                $this->periodType = 'created_at';
                $this->periodFrom = null;
                $this->periodTo = null;
                break;
            case 'overdueOnly':
                $this->overdueOnly = false;
                break;
            case 'priorityMin':
                $this->priorityMin = null;
                break;
            case 'priorityMax':
                $this->priorityMax = null;
                break;
            case 'urgentOnly':
                $this->urgentOnly = false;
                break;
            default:
                return;
        }

        $this->resetPage();
    }



    /** @return list<array{key: string, label: string}> */
    public function filterChips(): array
    {
        $chips = [];

        if ($this->status !== '') {
            $status = TaskStatus::tryFrom($this->status);
            if ($status) {
                $chips[] = ['key' => 'status', 'label' => __('Status').': '.$status->label()];
            }
        }

        if ($this->departmentId) {
            $name = Department::query()->whereKey($this->departmentId)->value('name');
            if ($name) {
                $chips[] = ['key' => 'departmentId', 'label' => __('Department').': '.$name];
            }
        }

        if ($this->categoryId) {
            $name = Category::query()->whereKey($this->categoryId)->value('name');
            if ($name) {
                $chips[] = ['key' => 'categoryId', 'label' => __('Category').': '.$name];
            }
        }

        if ($this->assigneeId) {
            $name = User::query()->whereKey($this->assigneeId)->value('name');
            if ($name) {
                $chips[] = ['key' => 'assigneeId', 'label' => __('Assignee').': '.$name];
            }
        }

        if ($this->initiatorId) {
            $name = User::query()->whereKey($this->initiatorId)->value('name');
            if ($name) {
                $chips[] = ['key' => 'initiatorId', 'label' => __('Initiator').': '.$name];
            }
        }

        $periodFrom = $this->parseDate($this->periodFrom);
        $periodTo = $this->parseDate($this->periodTo);
        if ($periodFrom !== null || $periodTo !== null) {
            $typeLabel = $this->periodType === 'deadline' ? __('Deadline') : __('Created at');
            $range = collect([$periodFrom?->format('d.m.Y'), $periodTo?->format('d.m.Y')])->filter()->implode(' — ');
            $chips[] = ['key' => 'period', 'label' => $typeLabel.': '.$range];
        }

        if ($this->overdueOnly) {
            $chips[] = ['key' => 'overdueOnly', 'label' => __('Only overdue')];
        }

        if ($this->urgentOnly) {
            $chips[] = ['key' => 'urgentOnly', 'label' => __('Only urgent')];
        }

        if ($this->priorityMin !== null) {
            $chips[] = ['key' => 'priorityMin', 'label' => __('Priority from').' '.$this->priorityMin];
        }

        if ($this->priorityMax !== null) {
            $chips[] = ['key' => 'priorityMax', 'label' => __('Priority to').' '.$this->priorityMax];
        }

        return $chips;
    }

}; ?>



<div
    class="space-y-4 {{ count($selectedIds) > 0 ? 'pb-24' : '' }}"
    x-on:keydown.escape.window="
        if ($event.defaultPrevented) return;
        if (document.querySelector('[data-ui=command-palette],[data-ui=sheet],[data-ui=combobox-panel].is-open')) return;
        if (window.uiContext?.open) return;
        if (!$wire.selectedIds?.length) return;
        $event.preventDefault();
        $wire.clearSelection();
    "
>

    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">

        <nav class="flex items-center gap-1 overflow-x-auto" aria-label="{{ __('Tasks') }}">

            @foreach ([

                'action' => __('Needs my action'),

                'assigned' => __('Assigned to me'),

                'created' => __('Created by me'),

                'watching' => __('Watching'),

                'department' => __('My department'),

                'all' => __('All accessible'),

            ] as $key => $label)

                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                        @if ($key === 'action') data-ui="action-tab" @endif

                        class="shrink-0 inline-flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 rounded-lg text-sm font-medium transition-colors

                            {{ $tab === $key ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">

                    {{ $label }}
                    @if ($key === 'action' && $actionCount > 0)
                        <span data-ui="action-count" class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-indigo-600 px-1.5 text-[11px] font-semibold text-white">{{ $actionCount }}</span>
                    @endif

                </button>

            @endforeach

        </nav>

        @can('create', \App\Models\Task::class)

            <x-action-button variant="primary" size="md" type="button"

                             data-shortcut="create-task"

                             onclick="Livewire.navigate('{{ route('tasks.create') }}')">

                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>

                {{ __('Create task') }}

                <x-kbd class="hidden sm:inline-flex border-indigo-400/40 bg-indigo-500 text-white">C</x-kbd>

            </x-action-button>

        @endcan

    </div>



    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">

        <div class="flex flex-col lg:flex-row lg:items-center gap-3">

            <div class="relative w-full lg:flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <x-text-input
                    wire:model.live.debounce.300ms="search"
                    data-shortcut="task-search"
                    class="w-full rounded-lg pl-9 pr-12"
                    placeholder="{{ __('Search tasks...') }}"
                />
                <x-kbd class="absolute right-2.5 top-1/2 -translate-y-1/2">/</x-kbd>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">

                {{-- Saved Views --}}
                <div x-data="{ svOpen: false }" class="relative">
                    <button type="button"
                            @click="svOpen = !svOpen"
                            class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg transition-colors
                                {{ $activeSavedFilterId ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50' }}">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
                        {{ $activeSavedFilterId ? $savedFilters->firstWhere('id', $activeSavedFilterId)?->name ?? __('Views') : __('Views') }}
                        <svg class="w-3 h-3 shrink-0 transition-transform" :class="svOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div x-show="svOpen" x-cloak @click.outside="svOpen = false" x-transition
                         class="absolute right-0 z-50 mt-2 w-72 rounded-xl border border-gray-200 bg-white shadow-lg">
                        <div class="max-h-64 overflow-y-auto divide-y divide-gray-100">
                            @forelse ($savedFilters as $sf)
                                <div class="group flex items-center gap-2 px-3 py-2 hover:bg-gray-50 {{ $activeSavedFilterId === $sf->id ? 'bg-indigo-50' : '' }}">
                                    <button type="button"
                                            wire:click="loadSavedFilter({{ $sf->id }})"
                                            @click="svOpen = false"
                                            class="min-w-0 flex-1 truncate text-left text-sm font-medium {{ $activeSavedFilterId === $sf->id ? 'text-indigo-700' : 'text-gray-900' }}">
                                        {{ $sf->name }}
                                        @if ($sf->is_default)
                                            <span class="ml-1 text-xs text-indigo-500">★</span>
                                        @endif
                                    </button>
                                    <div class="hidden shrink-0 items-center gap-0.5 group-hover:flex">
                                        <button type="button" wire:click="updateSavedFilter({{ $sf->id }})"
                                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                                title="{{ __('Overwrite with current filters') }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H16"/></svg>
                                        </button>
                                        <button type="button" wire:click="toggleDefaultFilter({{ $sf->id }})"
                                                class="rounded p-1 {{ $sf->is_default ? 'text-indigo-500' : 'text-gray-400' }} hover:bg-gray-100 hover:text-indigo-600"
                                                title="{{ $sf->is_default ? __('Remove default') : __('Set as default') }}">
                                            <svg class="w-3.5 h-3.5" fill="{{ $sf->is_default ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                        </button>
                                        <button type="button" wire:click="deleteSavedFilter({{ $sf->id }})"
                                                wire:confirm="{{ __('Delete this view?') }}"
                                                class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                                title="{{ __('Delete view') }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <p class="px-3 py-4 text-center text-sm text-gray-500">{{ __('No saved views yet') }}</p>
                            @endforelse
                        </div>
                        <div class="border-t border-gray-100 p-2">
                            <form wire:submit="saveCurrentFilter" class="flex items-center gap-2">
                                <input type="text"
                                       wire:model="savedFilterName"
                                       class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                       placeholder="{{ __('Save current view as...') }}" />
                                <x-action-button variant="primary" size="sm" type="submit">
                                    {{ __('Save') }}
                                </x-action-button>
                            </form>
                        </div>
                    </div>
                </div>

                <button type="button" wire:click="$toggle('filtersOpen')"

                        class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg transition-colors

                            {{ $filtersOpen ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50' }}">

                    {{ __('Filters') }}

                    @if ($activeFilterCount > 0)

                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 text-xs font-semibold rounded-full bg-indigo-600 text-white">{{ $activeFilterCount }}</span>

                    @endif

                </button>

                <div class="flex items-center gap-1.5">

                    <select wire:model.live="sortBy" class="border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="priority">{{ __('Priority') }}</option>
                        <option value="title">{{ __('Title') }}</option>
                        <option value="status">{{ __('Status') }}</option>
                        <option value="department">{{ __('Department') }}</option>
                        <option value="deadline">{{ __('Deadline') }}</option>
                        <option value="created_at">{{ __('Created at') }}</option>
                    </select>

                    <button type="button" wire:click="toggleSortDir"

                            class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 transition-colors"

                            title="{{ $sortDir === 'asc' ? __('Ascending') : __('Descending') }}">

                        @if ($sortDir === 'asc')

                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>

                        @else

                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>

                        @endif

                    </button>

                </div>

                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
                    <button
                        type="button"
                        wire:click="setLayout('list')"
                        class="rounded-md px-2.5 py-1.5 text-sm font-medium {{ $layout === 'list' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}"
                    >
                        {{ __('List') }}
                    </button>
                    <button
                        type="button"
                        wire:click="setLayout('board')"
                        data-ui="kanban-toggle"
                        class="rounded-md px-2.5 py-1.5 text-sm font-medium {{ $layout === 'board' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}"
                    >
                        {{ __('Board') }}
                    </button>
                </div>

            </div>

        </div>



        @if ($filtersOpen)

            <div class="space-y-4 pt-2 border-t border-gray-100">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Status') }}</label>

                        <x-combobox
                            wire:model.live="status"
                            :options="collect([['value' => '', 'label' => __('All statuses')]])->concat(collect($statuses)->map(fn ($st) => ['value' => $st->value, 'label' => $st->label()]))->all()"
                            :placeholder="__('All statuses')"
                        />

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Department') }}</label>

                        <x-combobox
                            wire:model.live="departmentId"
                            :options="collect([['value' => null, 'label' => __('All departments')]])->concat($departments->map(fn ($dept) => ['value' => $dept->id, 'label' => $dept->name]))->all()"
                            :placeholder="__('All departments')"
                        />

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Category') }}</label>

                        <x-combobox
                            wire:model.live="categoryId"
                            :options="collect([['value' => null, 'label' => __('All categories')]])->concat($categories->map(fn ($cat) => ['value' => $cat->id, 'label' => $cat->name]))->all()"
                            :placeholder="__('All categories')"
                        />

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Assignee') }}</label>

                        <x-combobox
                            wire:model.live="assigneeId"
                            :options="collect([['value' => null, 'label' => __('All assignees')]])->concat($users->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]))->all()"
                            :placeholder="__('All assignees')"
                        />

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Initiator') }}</label>

                        <x-combobox
                            wire:model.live="initiatorId"
                            :options="collect([['value' => null, 'label' => __('All initiators')]])->concat($users->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]))->all()"
                            :placeholder="__('All initiators')"
                        />

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Period filter') }}</label>

                        <select wire:model.live="periodType" class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">

                            <option value="created_at">{{ __('Created at') }}</option>

                            <option value="deadline">{{ __('Deadline') }}</option>

                        </select>

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('From') }}</label>

                        <input type="date" wire:model.live="periodFrom"

                               class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('To') }}</label>

                        <input type="date" wire:model.live="periodTo"

                               class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>

                </div>



                <div class="flex flex-wrap items-center gap-4">

                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">

                        <input type="checkbox" wire:model.live="urgentOnly" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />

                        <span class="text-sm text-gray-700">{{ __('Only urgent') }}</span>

                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">

                        <input type="checkbox" wire:model.live="overdueOnly" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />

                        <span class="text-sm text-gray-700">{{ __('Only overdue') }}</span>

                    </label>

                    <div class="flex items-center gap-2 text-sm text-gray-600">

                        <span class="text-xs text-gray-500">{{ __('Priority from') }}</span>

                        <input type="number" wire:model.live.debounce.300ms="priorityMin" min="1" max="10" placeholder="1"

                               class="w-16 border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />

                        <span class="text-xs text-gray-500">{{ __('Priority to') }}</span>

                        <input type="number" wire:model.live.debounce.300ms="priorityMax" min="1" max="10" placeholder="10"

                               class="w-16 border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />

                    </div>

                </div>

            </div>

        @endif

    </div>



    @php $filterChips = $this->filterChips(); @endphp

    @if (count($filterChips) > 0)

        <div class="flex flex-wrap items-center gap-2">

            @foreach ($filterChips as $chip)

                <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-indigo-50 text-indigo-700">

                    {{ $chip['label'] }}

                    <button type="button" wire:click="clearFilter('{{ $chip['key'] }}')"

                            class="inline-flex items-center justify-center w-4 h-4 rounded-full text-indigo-500 hover:bg-indigo-100 hover:text-indigo-800 transition-colors"

                            aria-label="{{ __('Reset filters') }}">

                        <span aria-hidden="true">&times;</span>

                    </button>

                </span>

            @endforeach

            <button type="button" wire:click="resetFilters"

                    class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">

                {{ __('Reset filters') }}

            </button>

        </div>

    @endif



    @if ($layout === 'board')
        @include('livewire.pages.tasks.partials.kanban')

        @if ($pendingKanbanId && $pendingKanbanStatus)
            <div
                data-ui="kanban-comment-dialog"
                class="fixed inset-0 z-50 overflow-y-auto"
                wire:keydown.escape.window="cancelKanbanMove"
                role="dialog"
                aria-modal="true"
                aria-labelledby="kanban-comment-title"
            >
                <div class="fixed inset-0 bg-gray-900/50" wire:click="cancelKanbanMove"></div>
                <div class="relative mx-auto mt-[18vh] w-full max-w-md px-4">
                    <form wire:submit="confirmKanbanMove" class="relative rounded-xl border border-gray-100 bg-white p-5 shadow-xl">
                        <h2 id="kanban-comment-title" class="text-base font-semibold text-gray-900">
                            {{ __('Comment required') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('To move :task to :status, write why.', [
                                'task' => $pendingKanbanTaskLabel,
                                'status' => $pendingKanbanToLabel,
                            ]) }}
                        </p>
                        <label class="mt-4 block">
                            <span class="mb-1 block text-xs font-medium text-gray-700">{{ __('Comment') }}</span>
                            <textarea
                                wire:model="kanbanComment"
                                rows="4"
                                required
                                autofocus
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="{{ __('Write a comment...') }}"
                            ></textarea>
                        </label>
                        <div class="mt-4 flex justify-end gap-2">
                            <x-action-button variant="ghost" type="button" wire:click="cancelKanbanMove">
                                {{ __('Cancel') }}
                            </x-action-button>
                            <x-action-button variant="primary" type="submit">
                                {{ __('Confirm') }}
                            </x-action-button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @else
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        @if ($tasks->isEmpty())

            <x-empty-state :title="$tab === 'action' ? __('Nothing needs your action') : ($activeFilterCount > 0 ? __('No matching tasks') : __('No tasks yet'))">

                <x-slot name="icon">

                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>

                </x-slot>

                {{ $tab === 'action'
                    ? __('Tasks waiting on others stay in Assigned and Created.')
                    : ($activeFilterCount > 0 ? __('Clear filters to see more tasks.') : __('Create a task to get started.')) }}

                <x-slot name="action">

                    @if ($activeFilterCount > 0)

                        <x-action-button variant="secondary" type="button" wire:click="resetFilters">

                            {{ __('Reset filters') }}

                        </x-action-button>

                    @elseif (auth()->user()->can('create', \App\Models\Task::class))

                        <x-action-button variant="primary" type="button"

                                         data-shortcut="create-task"

                                         onclick="Livewire.navigate('{{ route('tasks.create') }}')">

                            {{ __('Create task') }}

                        </x-action-button>

                    @endif

                </x-slot>

            </x-empty-state>

        @else

            <div class="relative" @if ($tab === 'action') data-ui="action-queue" @endif>

            <div wire:loading.class.remove="hidden" wire:loading.class="flex" wire:target="tab,status,departmentId,categoryId,assigneeId,initiatorId,urgentOnly,overdueOnly,sortBy,sortDir" class="absolute inset-0 z-10 hidden bg-white/90">
                <x-skeleton class="w-full" />
            </div>

            <div class="hidden md:block overflow-x-auto">

                @php
                    $pageIds = $tasks->pluck('id')->map(fn ($id) => (int) $id)->all();
                @endphp

                <table class="min-w-full divide-y divide-gray-100 text-sm">

                    <thead class="bg-gray-50/80">
                        <tr>
                            <th class="w-10 px-2 py-2.5">
                                <input
                                    type="checkbox"
                                    data-ui="task-select-all"
                                    wire:key="select-all-md"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    x-data
                                    x-bind:checked="{{ \Illuminate\Support\Js::from($pageIds) }}.length > 0 && {{ \Illuminate\Support\Js::from($pageIds) }}.every((id) => ($wire.selectedIds || []).map(Number).includes(Number(id)))"
                                    x-on:click.stop="$wire.toggleSelectPage('{{ implode(',', $pageIds) }}')"
                                    aria-label="{{ __('Select all on this page') }}"
                                >
                            </th>
                            @foreach ([
                                ['title', __('Title'), ''],
                                ['status', __('Status'), ''],
                                ['priority', __('Priority'), 'min-width:10rem'],
                                ['department', __('Department'), ''],
                                ['deadline', __('Deadline'), ''],
                            ] as [$column, $label, $thStyle])
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide whitespace-nowrap {{ $sortBy === $column ? 'text-indigo-700' : 'text-gray-500' }}"
                                    @if ($thStyle !== '') style="{{ $thStyle }}" @endif>
                                    <button type="button"
                                            wire:click="sortByColumn('{{ $column }}')"
                                            class="inline-flex items-center gap-1 whitespace-nowrap hover:text-indigo-700">
                                        {{ $label }}
                                        @if ($sortBy === $column)
                                            @if ($sortDir === 'asc')
                                                <svg class="w-4 h-4 shrink-0" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                            @else
                                                <svg class="w-4 h-4 shrink-0" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                            @endif
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    @php $lastActionSection = null; @endphp
                    @foreach ($tasks as $task)
                        @php
                            $deadline = $this->deadlineMeta($task);
                            $rowMenu = \Illuminate\Support\Js::from($this->rowActions($task));
                            $sectionKey = $actionGroup[$task->id] ?? null;
                        @endphp
                        @if ($sectionKey && $sectionKey !== $lastActionSection)
                            @php $lastActionSection = $sectionKey; @endphp
                            <tbody>
                                <tr data-ui="action-section" data-action-section="{{ $sectionKey }}">
                                    <td colspan="6" class="bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {{ $actionSections[$sectionKey]['label'] ?? $sectionKey }}
                                        <span class="font-medium normal-case text-slate-400">· {{ $actionSections[$sectionKey]['count'] ?? 0 }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        @endif
                        <tbody class="divide-y divide-gray-50" x-data="{ open: false }">
                            <tr class="cursor-pointer transition-colors group {{ (int) $peek === (int) $task->number || $this->isSelected($task->id) ? 'bg-indigo-50 hover:bg-indigo-50' : 'odd:bg-white even:bg-gray-50/50 hover:bg-gray-50' }}"
                                x-on:click="if (window.getSelection()?.toString() || window.uiContext?.suppressClick) return; $wire.openPeek({{ $task->number }})"
                                x-on:contextmenu.prevent="window.uiContext.show($event, {{ $rowMenu }})"
                                x-on:touchstart="window.uiContext.touchStart($event, {{ $rowMenu }})"
                                x-on:touchmove="window.uiContext.touchCancel()"
                                x-on:touchend="window.uiContext.touchCancel()">
                                <td class="w-10 px-2 py-2.5" @click.stop>
                                    <input
                                        type="checkbox"
                                        data-ui="task-select"
                                        wire:key="select-md-{{ $task->id }}"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        x-bind:checked="($wire.selectedIds || []).map(Number).includes({{ $task->id }})"
                                        x-on:click.stop="$wire.toggleSelected({{ $task->id }})"
                                        aria-label="{{ __('Select #:number', ['number' => $task->number]) }}"
                                    >
                                </td>
                                <td class="px-4 py-2.5 max-w-md">
                                    <div class="flex items-center gap-1.5 min-w-0 leading-5">
                                        @if ($tab !== 'action' && ! $task->isSubtask() && $task->subtasks->isNotEmpty())
                                            <button type="button"
                                                    class="inline-flex shrink-0 items-center justify-center rounded-md shadow-sm"
                                                    style="background:#4f46e5;color:#fff;width:22px;height:22px;"
                                                    @click.stop="open = !open"
                                                    :aria-expanded="open"
                                                    aria-label="{{ __('Subtasks') }}">
                                                <svg width="12" height="12"
                                                     style="transition: transform 0.15s ease"
                                                     :style="open ? 'transform: rotate(90deg)' : 'transform: none'"
                                                     fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                                </svg>
                                            </button>
                                        @else
                                            <span class="inline-block shrink-0" style="width:22px;height:22px;" aria-hidden="true"></span>
                                        @endif
                                        <x-task-number :task="$task" />
                                        <span class="text-gray-400" aria-hidden="true">&middot;</span>
                                        <span class="truncate font-medium text-gray-900">{{ $task->title ?: Str::limit($task->plainDescription(), 80) }}</span>
                                        @if (($waitingOn = $task->waitingOnLabel()) !== '')
                                            <x-waiting-chip>{{ $waitingOn }}</x-waiting-chip>
                                        @endif
                                        @if (! $task->parent_id && ($subtaskProgress = $task->subtaskProgress()) !== '')
                                            <span class="shrink-0 text-sm tabular-nums text-indigo-600">{{ $subtaskProgress }}</span>
                                        @endif
                                    </div>
                                    @if ($task->parent)
                                        <p class="mt-0.5 pl-5 text-xs text-indigo-600 truncate">
                                            {{ __('Part of #:number · :title', ['number' => $task->parent->number, 'title' => $task->parent->title]) }}
                                        </p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-gray-500 truncate" style="padding-left: 1.75rem;">
                                        {{ $task->initiator?->name }}
                                        <span aria-hidden="true">&rarr;</span>
                                        {{ $task->assignee?->name }}
                                        @if ($task->category?->name)
                                            <span aria-hidden="true">&middot;</span>
                                            {{ $task->category->name }}
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <x-status-badge :status="$task->status" />
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap w-28">
                                    <x-priority-bar :priority="$task->priority" />
                                </td>
                                <td class="px-4 py-2.5 text-gray-600">{{ $task->department?->name }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap {{ $deadline['class'] }}">
                                    {{ $deadline['text'] }}
                                </td>
                            </tr>
                            @foreach ($tab === 'action' ? [] : $task->subtasks as $subtask)
                                @php
                                    $childDeadline = $this->deadlineMeta($subtask);
                                    $childMenu = \Illuminate\Support\Js::from($this->rowActions($subtask));
                                @endphp
                                <tr class="cursor-pointer {{ (int) $peek === (int) $subtask->number || $this->isSelected($subtask->id) ? 'ring-1 ring-inset ring-indigo-300' : '' }}"
                                    style="display: none; background:#eef2ff;"
                                    :style="open ? 'display: table-row; background:#eef2ff;' : 'display: none;'"
                                    x-on:click="if (window.getSelection()?.toString() || window.uiContext?.suppressClick) return; $wire.openPeek({{ $subtask->number }})"
                                    x-on:contextmenu.prevent="window.uiContext.show($event, {{ $childMenu }})"
                                    x-on:touchstart="window.uiContext.touchStart($event, {{ $childMenu }})"
                                    x-on:touchmove="window.uiContext.touchCancel()"
                                    x-on:touchend="window.uiContext.touchCancel()">
                                    <td class="w-10 px-2 py-2" @click.stop>
                                        <input
                                            type="checkbox"
                                            data-ui="task-select"
                                            wire:key="select-md-sub-{{ $subtask->id }}"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            x-bind:checked="($wire.selectedIds || []).map(Number).includes({{ $subtask->id }})"
                                            x-on:click.stop="$wire.toggleSelected({{ $subtask->id }})"
                                            aria-label="{{ __('Select #:number', ['number' => $subtask->number]) }}"
                                        >
                                    </td>
                                    <td class="px-4 py-2 max-w-md">
                                        <div class="relative flex items-center gap-1.5 min-w-0 leading-5" style="padding-left: 6rem;">
                                            <span aria-hidden="true" style="position:absolute;left:0.7rem;top:-10px;width:1.5px;background:#818cf8;{{ $loop->last ? 'height:calc(50% + 10px);' : 'bottom:-10px;' }}"></span>
                                            <span aria-hidden="true" style="position:absolute;left:0.7rem;top:50%;width:5.1rem;height:1.5px;background:#818cf8;margin-top:-0.75px;"></span>
                                            <x-task-number :task="$subtask" />
                                            <span class="text-gray-400" aria-hidden="true">&middot;</span>
                                            <span class="truncate text-gray-700">{{ $subtask->title }}</span>
                                            @if (($waitingOn = $subtask->waitingOnLabel()) !== '')
                                                <x-waiting-chip>{{ $waitingOn }}</x-waiting-chip>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <x-status-badge :status="$subtask->status" />
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap w-28"></td>
                                    <td class="px-4 py-2 text-gray-600 truncate">{{ $subtask->assignee?->name }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap {{ $childDeadline['class'] }}">
                                        {{ $childDeadline['text'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach

                </table>

            </div>



            <div class="md:hidden divide-y divide-gray-100">

                @php $lastMobileActionSection = null; @endphp
                @foreach ($tasks as $task)

                    @php
                        $deadline = $this->deadlineMeta($task);
                        $rowMenu = \Illuminate\Support\Js::from($this->rowActions($task));
                        $sectionKey = $actionGroup[$task->id] ?? null;
                    @endphp

                    @if ($sectionKey && $sectionKey !== $lastMobileActionSection)
                        @php $lastMobileActionSection = $sectionKey; @endphp
                        <div data-ui="action-section" data-action-section="{{ $sectionKey }}" class="bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $actionSections[$sectionKey]['label'] ?? $sectionKey }}
                            <span class="font-medium normal-case text-slate-400">· {{ $actionSections[$sectionKey]['count'] ?? 0 }}</span>
                        </div>
                    @endif

                    <div class="transition-colors {{ (int) $peek === (int) $task->number || $this->isSelected($task->id) ? 'bg-indigo-50' : 'hover:bg-gray-50' }}" x-data="{ open: false }">

                        <div class="cursor-pointer"
                             x-on:click="if (window.getSelection()?.toString() || window.uiContext?.suppressClick) return; $wire.openPeek({{ $task->number }})"
                             x-on:contextmenu.prevent="window.uiContext.show($event, {{ $rowMenu }})"
                             x-on:touchstart="window.uiContext.touchStart($event, {{ $rowMenu }})"
                             x-on:touchmove="window.uiContext.touchCancel()"
                             x-on:touchend="window.uiContext.touchCancel()">

                        <x-card padding="p-4" class="border-0 shadow-none rounded-none">

                            <div class="space-y-2">

                                <div class="flex items-start justify-between gap-2">

                                    <div class="shrink-0 pt-0.5" @click.stop>
                                        <input
                                            type="checkbox"
                                            data-ui="task-select"
                                            wire:key="select-xs-{{ $task->id }}"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            x-bind:checked="($wire.selectedIds || []).map(Number).includes({{ $task->id }})"
                                            x-on:click.stop="$wire.toggleSelected({{ $task->id }})"
                                            aria-label="{{ __('Select #:number', ['number' => $task->number]) }}"
                                        >
                                    </div>

                                    <div class="min-w-0 flex-1">

                                        <div class="flex items-center gap-1.5 min-w-0 leading-5">

                                            @if ($tab !== 'action' && ! $task->isSubtask() && $task->subtasks->isNotEmpty())

                                                <button type="button"
                                                        class="inline-flex shrink-0 items-center justify-center rounded-md shadow-sm"
                                                        style="background:#4f46e5;color:#fff;width:22px;height:22px;"
                                                        @click.stop="open = !open"
                                                        :aria-expanded="open"
                                                        aria-label="{{ __('Subtasks') }}">
                                                    <svg width="12" height="12"
                                                         style="transition: transform 0.15s ease"
                                                         :style="open ? 'transform: rotate(90deg)' : 'transform: none'"
                                                         fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                                    </svg>
                                                </button>

                                            @endif

                                            <x-task-number :task="$task" />

                                            <span class="text-gray-400" aria-hidden="true">&middot;</span>

                                            <span class="truncate text-sm font-medium text-gray-900">{{ $task->title ?: Str::limit($task->plainDescription(), 80) }}</span>
                                            @if (($waitingOn = $task->waitingOnLabel()) !== '')
                                                <x-waiting-chip>{{ $waitingOn }}</x-waiting-chip>
                                            @endif

                                            @if (! $task->parent_id && ($subtaskProgress = $task->subtaskProgress()) !== '')

                                                <span class="shrink-0 text-sm tabular-nums text-indigo-600">{{ $subtaskProgress }}</span>

                                            @endif

                                        </div>

                                        @if ($task->parent)

                                            <p class="mt-0.5 text-xs text-indigo-600 truncate">

                                                {{ __('Part of #:number · :title', ['number' => $task->parent->number, 'title' => $task->parent->title]) }}

                                            </p>

                                        @endif

                                    </div>

                                    <x-status-badge :status="$task->status" class="shrink-0" />

                                </div>

                                <div class="flex flex-wrap items-center gap-3">

                                    <x-priority-bar :priority="$task->priority" />

                                    <span class="text-xs {{ $deadline['class'] }}">{{ $deadline['text'] }}</span>

                                </div>

                                <p class="text-xs text-gray-500">

                                    <span>{{ $task->assignee?->name }}</span>

                                    @if ($task->department?->name)

                                        <span aria-hidden="true">&middot;</span>

                                        <span>{{ $task->department->name }}</span>

                                    @endif

                                </p>

                            </div>

                        </x-card>

                        </div>

                        @foreach ($tab === 'action' ? [] : $task->subtasks as $subtask)

                            @php
                                $childDeadline = $this->deadlineMeta($subtask);
                                $childMenu = \Illuminate\Support\Js::from($this->rowActions($subtask));
                            @endphp

                            <div class="relative cursor-pointer px-4 py-3 {{ (int) $peek === (int) $subtask->number || $this->isSelected($subtask->id) ? 'ring-1 ring-inset ring-indigo-300' : '' }}"
                                 style="display: none; padding-left: 5rem; background:#eef2ff;"
                                 :style="open ? 'display: block; padding-left: 5rem; background:#eef2ff;' : 'display: none;'"
                                 x-on:click="if (window.getSelection()?.toString() || window.uiContext?.suppressClick) return; $wire.openPeek({{ $subtask->number }})"
                                 x-on:contextmenu.prevent="window.uiContext.show($event, {{ $childMenu }})"
                                 x-on:touchstart="window.uiContext.touchStart($event, {{ $childMenu }})"
                                 x-on:touchmove="window.uiContext.touchCancel()"
                                 x-on:touchend="window.uiContext.touchCancel()">

                                <span aria-hidden="true" style="position:absolute;left:1.75rem;top:-8px;width:1.5px;background:#818cf8;{{ $loop->last ? 'height:calc(50% + 8px);' : 'bottom:-8px;' }}"></span>
                                <span aria-hidden="true" style="position:absolute;left:1.75rem;top:1.25rem;width:2.75rem;height:1.5px;background:#818cf8;"></span>

                                <div class="flex items-start justify-between gap-2">

                                    <div class="shrink-0 pt-0.5" @click.stop>
                                        <input
                                            type="checkbox"
                                            data-ui="task-select"
                                            wire:key="select-xs-sub-{{ $subtask->id }}"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            x-bind:checked="($wire.selectedIds || []).map(Number).includes({{ $subtask->id }})"
                                            x-on:click.stop="$wire.toggleSelected({{ $subtask->id }})"
                                            aria-label="{{ __('Select #:number', ['number' => $subtask->number]) }}"
                                        >
                                    </div>

                                    <div class="min-w-0">

                                        <div class="flex items-center gap-1.5 min-w-0 leading-5">

                                            <x-task-number :task="$subtask" />

                                            <span class="text-gray-400" aria-hidden="true">&middot;</span>

                                            <span class="truncate text-sm text-gray-700">{{ $subtask->title }}</span>
                                            @if (($waitingOn = $subtask->waitingOnLabel()) !== '')
                                                <x-waiting-chip>{{ $waitingOn }}</x-waiting-chip>
                                            @endif

                                        </div>

                                        <p class="mt-0.5 text-xs text-gray-500">
                                            {{ $subtask->assignee?->name }}
                                            <span aria-hidden="true">&middot;</span>
                                            <span class="{{ $childDeadline['class'] }}">{{ $childDeadline['text'] }}</span>
                                        </p>

                                    </div>

                                    <x-status-badge :status="$subtask->status" class="shrink-0" />

                                </div>

                            </div>

                        @endforeach

                    </div>

                @endforeach

            </div>

            </div>

        @endif

        @if ($tasks->hasPages())

            <div class="px-4 py-3 border-t border-gray-100 bg-gray-50/50">{{ $tasks->links() }}</div>

        @endif

    </div>
    @endif

    @if (count($selectedIds) > 0)
        <div
            data-ui="bulk-bar"
            class="fixed inset-x-0 bottom-0 z-[45] border-t border-gray-200 bg-white/95 shadow-[0_-8px_24px_rgba(15,23,42,0.08)] backdrop-blur"
        >
            <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-gray-900">
                        {{ __(':count selected', ['count' => count($selectedIds)]) }}
                    </span>
                    <x-action-button variant="ghost" wire:click="clearSelection">
                        {{ __('Clear selection') }}
                    </x-action-button>

                    @foreach ($bulkTransitions as $item)
                        <x-action-button
                            variant="{{ $item['destructive'] ? 'danger' : 'secondary' }}"
                            wire:click="chooseBulkStatus('{{ $item['value'] }}')"
                            @class(['ring-2 ring-indigo-500 ring-offset-1' => $pendingBulkStatus === $item['value']])
                        >
                            {{ $item['label'] }}
                        </x-action-button>
                    @endforeach

                    @if (count($bulkAssigneeOptions) > 0)
                        <div class="w-52">
                            <x-combobox
                                wire:model.live="bulkAssigneeId"
                                :options="$bulkAssigneeOptions"
                                :placeholder="__('Assign')"
                                :up="true"
                            />
                        </div>
                    @endif

                    @if ($canBulkWatch)
                        <x-action-button variant="secondary" wire:click="bulkWatch">
                            {{ __('Watch') }}
                        </x-action-button>
                        <x-action-button variant="secondary" wire:click="bulkUnwatch">
                            {{ __('Unwatch') }}
                        </x-action-button>
                    @endif
                </div>

                @if ($pendingBulkStatus || $bulkAssigneeId)
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="min-w-0 flex-1">
                            <span class="sr-only">{{ __('Comment') }}</span>
                            <textarea
                                wire:model="bulkComment"
                                rows="2"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="{{ $pendingBulkStatus ? __('Add a comment for this status change') : __('Explain why the task is reassigned') }}"
                            ></textarea>
                        </label>
                        <x-action-button variant="primary" wire:click="confirmBulkAction">
                            {{ __('Confirm') }}
                        </x-action-button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($peek)
        <x-sheet :open="true">
            <livewire:pages.tasks.peek :number="$peek" :key="'peek-'.$peek" />
        </x-sheet>
    @endif

</div>

