<?php



use App\Enums\TaskStatus;

use App\Models\Category;

use App\Models\Department;

use App\Models\Task;

use App\Models\User;

use App\Services\TaskVisibilityService;

use Carbon\Carbon;

use Livewire\Attributes\Layout;

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



    public function mount(): void
    {
        $this->filtersOpen = $this->activeFilterCount() > 0;
    }



    public function with(): array

    {

        $user = auth()->user();

        $query = app(TaskVisibilityService::class)->accessibleQuery($user)

            ->with(['initiator:id,name', 'assignee:id,name', 'department:id,name', 'category:id,name', 'parent:id,number,title', 'subtasks:id,parent_id,status']);



        $query = match ($this->tab) {

            'created' => $query->where('initiator_id', $user->id),

            'watching' => $query->whereHas('watchers', fn ($q) => $q->where('user_id', $user->id)),

            'department' => $user->headedDepartments()->exists()
                ? $query->whereIn('department_id', $user->headedDepartments()->pluck('id'))
                : ($user->department_id
                    ? $query->where('department_id', $user->department_id)
                    : $query->whereRaw('0 = 1')),

            'all' => $query,

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



        if ($this->status !== '') {

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



        $this->applySorting($query);



        return [

            'tasks' => $query->paginate(25),

            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),

            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),

            'statuses' => TaskStatus::cases(),

            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),

            'activeFilterCount' => $this->activeFilterCount(),

        ];

    }



    private function applySorting($query): void

    {

        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';



        match ($this->sortBy) {

            'deadline' => $query

                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')

                ->orderBy('deadline', $dir),

            'created_at' => $query->orderBy('created_at', $dir),

            'status' => $query->orderBy('status', $dir),

            default => $query

                ->orderBy('priority', $dir)

                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')

                ->orderBy('deadline'),

        };

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

    }



    public function toggleSortDir(): void

    {

        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';

        $this->resetPage();

    }



    public function updated($property): void

    {

        if (in_array($property, [

            'tab', 'search', 'status', 'departmentId', 'categoryId', 'urgentOnly',

            'priorityMin', 'priorityMax', 'assigneeId', 'initiatorId',

            'periodType', 'periodFrom', 'periodTo', 'overdueOnly', 'sortBy', 'sortDir',

        ], true)) {

            $this->resetPage();

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



<div class="space-y-4">

    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">

        <nav class="flex items-center gap-1 overflow-x-auto" aria-label="{{ __('Tasks') }}">

            @foreach ([

                'assigned' => __('Assigned to me'),

                'created' => __('Created by me'),

                'watching' => __('Watching'),

                'department' => __('My department'),

                'all' => __('All accessible'),

            ] as $key => $label)

                <button type="button" wire:click="$set('tab', '{{ $key }}')"

                        class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-lg text-sm font-medium transition-colors

                            {{ $tab === $key ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">

                    {{ $label }}

                </button>

            @endforeach

        </nav>

        @can('create', \App\Models\Task::class)

            <x-action-button variant="primary" size="md" type="button"

                             onclick="Livewire.navigate('{{ route('tasks.create') }}')">

                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>

                {{ __('Create task') }}

            </x-action-button>

        @endcan

    </div>



    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">

        <div class="flex flex-col lg:flex-row lg:items-center gap-3">

            <x-text-input wire:model.live.debounce.300ms="search" class="w-full lg:flex-1 rounded-lg" placeholder="{{ __('Search tasks...') }}" />

            <div class="flex flex-wrap items-center gap-2 shrink-0">

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

                        <option value="deadline">{{ __('Deadline') }}</option>

                        <option value="created_at">{{ __('Created at') }}</option>

                        <option value="status">{{ __('Status') }}</option>

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

            </div>

        </div>



        @if ($filtersOpen)

            <div class="space-y-4 pt-2 border-t border-gray-100">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Status') }}</label>

                        <select wire:model.live="status" class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">

                            <option value="">{{ __('All statuses') }}</option>

                            @foreach ($statuses as $st)

                                <option value="{{ $st->value }}">{{ $st->label() }}</option>

                            @endforeach

                        </select>

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Department') }}</label>

                        <select wire:model.live="departmentId" class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">

                            <option value="">{{ __('All departments') }}</option>

                            @foreach ($departments as $dept)

                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>

                            @endforeach

                        </select>

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Category') }}</label>

                        <select wire:model.live="categoryId" class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">

                            <option value="">{{ __('All categories') }}</option>

                            @foreach ($categories as $cat)

                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>

                            @endforeach

                        </select>

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Assignee') }}</label>

                        <select wire:model.live="assigneeId" class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">

                            <option value="">{{ __('All assignees') }}</option>

                            @foreach ($users as $u)

                                <option value="{{ $u->id }}">{{ $u->name }}</option>

                            @endforeach

                        </select>

                    </div>

                    <div>

                        <label class="block text-xs text-gray-500 mb-1">{{ __('Initiator') }}</label>

                        <select wire:model.live="initiatorId" class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">

                            <option value="">{{ __('All initiators') }}</option>

                            @foreach ($users as $u)

                                <option value="{{ $u->id }}">{{ $u->name }}</option>

                            @endforeach

                        </select>

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



    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        @if ($tasks->isEmpty())

            <x-empty-state>

                <x-slot name="icon">

                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>

                </x-slot>

                {{ __('No tasks found.') }}

                @can('create', \App\Models\Task::class)

                    <x-slot name="action">

                        <x-action-button variant="primary" type="button"

                                         onclick="Livewire.navigate('{{ route('tasks.create') }}')">

                            {{ __('Create task') }}

                        </x-action-button>

                    </x-slot>

                @endcan

            </x-empty-state>

        @else

            <div class="hidden md:block overflow-x-auto">

                <table class="min-w-full divide-y divide-gray-100 text-sm">

                    <thead class="bg-gray-50/80">

                        <tr>

                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Title') }}</th>

                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Status') }}</th>

                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide w-28">{{ __('Priority') }}</th>

                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Department') }}</th>

                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Deadline') }}</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-gray-50">

                        @foreach ($tasks as $task)

                            @php $deadline = $this->deadlineMeta($task); @endphp

                            <tr class="odd:bg-white even:bg-gray-50/50 hover:bg-gray-50 cursor-pointer transition-colors group"

                                onclick="Livewire.navigate('{{ route('tasks.show', $task) }}')">

                                <td class="px-4 py-2.5 max-w-md">

                                    <div class="flex items-baseline gap-1.5 min-w-0">

                                        <span class="shrink-0 text-xs text-gray-500">#{{ $task->number }}</span>

                                        <span class="text-gray-400" aria-hidden="true">&middot;</span>

                                        <span class="truncate font-medium text-gray-900">{{ $task->title ?: Str::limit($task->plainDescription(), 80) }}</span>

                                        @if (! $task->parent_id && ($subtaskProgress = $task->subtaskProgress()) !== '')

                                            <span class="shrink-0 text-xs text-gray-500 tabular-nums">{{ $subtaskProgress }}</span>

                                        @endif

                                    </div>

                                    @if ($task->parent)

                                        <p class="mt-0.5 text-xs text-indigo-600 truncate">

                                            {{ __('Part of #:number · :title', ['number' => $task->parent->number, 'title' => $task->parent->title]) }}

                                        </p>

                                    @endif

                                    <p class="mt-0.5 text-xs text-gray-500 truncate">

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

                        @endforeach

                    </tbody>

                </table>

            </div>



            <div class="md:hidden divide-y divide-gray-100">

                @foreach ($tasks as $task)

                    @php $deadline = $this->deadlineMeta($task); @endphp

                    <div class="cursor-pointer hover:bg-gray-50 transition-colors"

                         onclick="Livewire.navigate('{{ route('tasks.show', $task) }}')">

                        <x-card padding="p-4" class="border-0 shadow-none rounded-none">

                            <div class="space-y-2">

                                <div class="flex items-start justify-between gap-2">

                                    <div class="min-w-0 flex-1">

                                        <div class="flex items-baseline gap-1.5 min-w-0">

                                            <span class="shrink-0 text-xs text-gray-500">#{{ $task->number }}</span>

                                            <span class="text-gray-400" aria-hidden="true">&middot;</span>

                                            <span class="truncate text-sm font-medium text-gray-900">{{ $task->title ?: Str::limit($task->plainDescription(), 80) }}</span>

                                            @if (! $task->parent_id && ($subtaskProgress = $task->subtaskProgress()) !== '')

                                                <span class="shrink-0 text-xs text-gray-500 tabular-nums">{{ $subtaskProgress }}</span>

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

                @endforeach

            </div>

        @endif

        @if ($tasks->hasPages())

            <div class="px-4 py-3 border-t border-gray-100 bg-gray-50/50">{{ $tasks->links() }}</div>

        @endif

    </div>

</div>

