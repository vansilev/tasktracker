<?php

use App\Enums\TaskStatus;
use App\Services\DashboardService;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.tasks-layout', ['title' => 'Dashboard'])] class extends Component
{
    #[Url]
    public string $period = 'month';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateTo = null;

    public function mount(): void
    {
        $this->syncCustomDates();
        $this->dispatchCharts();
    }

    public function updatedPeriod(): void
    {
        $this->syncCustomDates();
        $this->dispatchCharts();
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
        $this->dispatchCharts();
    }

    public function updatedDateTo(): void
    {
        $this->period = 'custom';
        $this->dispatchCharts();
    }

    public function with(): array
    {
        $user = auth()->user();
        $service = app(DashboardService::class);
        [$from, $to] = $this->periodBounds();

        $departmentData = $service->byDepartment($user, $from, $to);
        $categoryData = $service->byCategory($user, $from, $to);
        $avgClosingHours = $service->avgClosingTime($user, $from, $to);

        return [
            'openByStatus' => $service->openByStatus($user),
            'overdue' => $service->overdue($user),
            'onReview' => $service->onReviewForInitiator($user),
            'urgent' => $service->urgent($user),
            'avgClosingHours' => $avgClosingHours,
            'avgClosingLabel' => DashboardService::formatDurationHours($avgClosingHours),
            'myTasks' => $service->myTasks($user),
            'openStatuses' => TaskStatus::open(),
            'departmentChart' => $this->departmentChartConfig($departmentData),
            'categoryChart' => $this->categoryChartConfig($categoryData),
            'periodFrom' => $from,
            'periodTo' => $to,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodBounds(): array
    {
        $now = now();

        if ($this->period === 'week') {
            return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()->endOfDay()];
        }

        if ($this->period === 'quarter') {
            return [$now->copy()->firstOfQuarter(), $now->copy()->lastOfQuarter()->endOfDay()];
        }

        if ($this->period === 'custom') {
            $from = Carbon::parse($this->dateFrom ?? $now->copy()->startOfMonth()->toDateString())->startOfDay();
            $to = Carbon::parse($this->dateTo ?? $now->toDateString())->endOfDay();

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return [$from, $to];
        }

        return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()->endOfDay()];
    }

    private function syncCustomDates(): void
    {
        if ($this->period === 'custom') {
            return;
        }

        [$from, $to] = $this->periodBounds();
        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
    }

    private function dispatchCharts(): void
    {
        $user = auth()->user();
        $service = app(DashboardService::class);
        [$from, $to] = $this->periodBounds();

        $this->dispatch(
            'dashboard-charts-updated',
            departmentChart: $this->departmentChartConfig($service->byDepartment($user, $from, $to)),
            categoryChart: $this->categoryChartConfig($service->byCategory($user, $from, $to)),
        );
    }

  /** @param list<array{id: int, name: string, created: int, completed: int}> $data */
    private function departmentChartConfig(array $data): array
    {
        return [
            'type' => 'bar',
            'data' => [
                'labels' => array_column($data, 'name'),
                'datasets' => [
                    [
                        'label' => __('Created'),
                        'data' => array_column($data, 'created'),
                        'backgroundColor' => '#1F3A8A', // primary-600
                    ],
                    [
                        'label' => __('Completed'),
                        'data' => array_column($data, 'completed'),
                        'backgroundColor' => '#16A34A', // success-600
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'top'],
                ],
                'scales' => [
                    'x' => ['stacked' => false],
                    'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                ],
            ],
        ];
    }

    /** @param list<array{id: int, name: string, count: int}> $data */
    private function categoryChartConfig(array $data): array
    {
        $palette = [
            // Design tokens: primary, accent, success, info, then lighter steps.
            '#1F3A8A', // primary-600
            '#C9A227', // accent-500
            '#16A34A', // success-600
            '#3B82F6', // info-500
            '#8EA6D6', // primary-300
            '#DFBD52', // accent-300
            '#86EFAC', // success-300
            '#9CA3AF', // gray-400
        ];

        return [
            'type' => 'doughnut',
            'data' => [
                'labels' => array_column($data, 'name'),
                'datasets' => [
                    [
                        'data' => array_column($data, 'count'),
                        'backgroundColor' => array_slice(
                            array_merge($palette, $palette),
                            0,
                            max(count($data), 1),
                        ),
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'right'],
                ],
            ],
        ];
    }
}; ?>

<x-slot name="headerActions">
    <x-button variant="primary" :href="route('tasks.create')" wire:navigate>
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        {{ __('Create task') }}
    </x-button>
</x-slot>

<div class="space-y-4">
    <span class="sr-only">{{ __('Quick links') }}</span>

    {{-- KPI strip: one surface, four cells, no empty tiles. --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-px overflow-hidden rounded-surface border border-gray-200 bg-gray-200">
        <div class="bg-surface p-4">
            <a href="{{ route('tasks.index', ['tab' => 'all']) }}" wire:navigate class="group block">
                <p class="text-display font-semibold tabular-nums text-gray-900 group-hover:text-primary-700">{{ $openByStatus['total'] }}</p>
                <p class="text-caption text-gray-600">{{ __('Open tasks') }}</p>
            </a>
            <div class="mt-2 flex flex-wrap gap-1">
                @foreach ($openStatuses as $status)
                    @php $count = $openByStatus['by_status'][$status->value] ?? 0; @endphp
                    @if ($count > 0)
                        <a href="{{ route('tasks.index', ['tab' => 'all', 'status' => $status->value]) }}" wire:navigate
                           class="inline-flex items-center gap-1 rounded-control px-1.5 py-0.5 text-caption font-medium {{ $status->badgeClasses() }} hover:opacity-80">
                            {{ $status->label() }} <span class="tabular-nums">{{ $count }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
        <a href="{{ route('tasks.index', ['tab' => 'assigned']) }}" wire:navigate class="group block bg-surface p-4 hover:bg-gray-50">
            <p class="text-display font-semibold tabular-nums text-gray-900 group-hover:text-primary-700">{{ $myTasks['count'] }}</p>
            <p class="text-caption text-gray-600">{{ __('My tasks') }}</p>
        </a>
        <a href="{{ route('tasks.index', ['tab' => 'all', 'urgentOnly' => true]) }}" wire:navigate class="group block bg-surface p-4 hover:bg-gray-50">
            <p class="text-display font-semibold tabular-nums text-gray-900 group-hover:text-primary-700">{{ $urgent['count'] }}</p>
            <p class="text-caption text-gray-600 inline-flex items-center gap-1">{{ __('Urgent') }} <x-icon.fire class="w-3.5 h-3.5 text-accent-600" /></p>
        </a>
        <a href="{{ route('tasks.index', ['tab' => 'all', 'overdueOnly' => true]) }}" wire:navigate class="group block bg-surface p-4 hover:bg-gray-50">
            <p class="text-display font-semibold tabular-nums {{ $overdue['count'] > 0 ? 'text-danger-700' : 'text-gray-900' }} group-hover:text-primary-700">{{ $overdue['count'] }}</p>
            <p class="text-caption text-gray-600">{{ __('Overdue tasks') }}</p>
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-card class="flex flex-col">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Overdue tasks') }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ __('Current snapshot, not affected by period filter.') }}</p>
            <div class="mt-3 flex-1">
                @if ($overdue['items'] === [])
                    <x-empty-state>{{ __('No data yet.') }}</x-empty-state>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($overdue['items'] as $task)
                            <li class="py-2 flex items-center justify-between gap-2">
                                <a href="{{ route('tasks.show', $task['id']) }}" wire:navigate class="min-w-0 group flex-1">
                                    <p class="text-sm text-gray-900 group-hover:text-primary-700 truncate">
                                        #{{ $task['number'] }} {{ $task['title'] }}
                                    </p>
                                </a>
                                <span class="shrink-0 text-xs tabular-nums text-danger-700">
                                    {{ $task['deadline']?->timezone(config('app.timezone'))->format('d.m.Y') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100">
                <a href="{{ route('tasks.index', ['tab' => 'all', 'overdueOnly' => true]) }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800">
                    {{ __('Show all') }} ({{ $overdue['count'] }})
                </a>
            </div>
        </x-card>

        <x-card class="flex flex-col">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('On review') }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ __('Awaiting acceptance by you as initiator.') }}</p>
            <div class="mt-3 flex-1">
                @if ($onReview['items']->isEmpty())
                    <x-empty-state>{{ __('No data yet.') }}</x-empty-state>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($onReview['items'] as $task)
                            <li class="py-2">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="group block">
                                    <p class="text-sm text-gray-900 group-hover:text-primary-700 truncate">
                                        #{{ $task->number }} {{ $task->title }}
                                    </p>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100">
                <a href="{{ route('tasks.index', ['tab' => 'action']) }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800">
                    {{ __('Show all') }} ({{ $onReview['count'] }})
                </a>
            </div>
        </x-card>

        <x-card class="flex flex-col">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('My tasks') }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ __('Open tasks assigned to you.') }}</p>
            <div class="mt-3 flex-1">
                @if ($myTasks['items']->isEmpty())
                    <x-empty-state>{{ __('No data yet.') }}</x-empty-state>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($myTasks['items'] as $task)
                            <li class="py-2 flex items-center justify-between gap-2">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 group flex-1">
                                    <p class="text-sm text-gray-900 group-hover:text-primary-700 truncate">
                                        #{{ $task->number }} {{ $task->title }}
                                    </p>
                                </a>
                                <div class="shrink-0 flex items-center gap-2">
                                    @if ($task->deadline)
                                        <span class="text-xs {{ $task->deadline->isPast() ? 'text-danger-700' : 'text-gray-600' }}">
                                            {{ $task->deadline->timezone(config('app.timezone'))->format('d.m.Y') }}
                                        </span>
                                    @endif
                                    <x-priority-bar :priority="$task->priority" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="mt-3 pt-3 border-t border-gray-100">
                <a href="{{ route('tasks.index', ['tab' => 'assigned']) }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800">
                    {{ __('Show all') }} ({{ $myTasks['count'] }})
                </a>
            </div>
        </x-card>
    </div>

    <section class="space-y-3 pt-2" aria-label="{{ __('Statistics for the period') }}">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-title-sm font-semibold text-gray-900 me-2">{{ __('Statistics for the period') }}</h2>
            <div class="inline-flex flex-wrap rounded-control bg-gray-100 p-0.5">
                @foreach (['week' => __('Week'), 'month' => __('Month'), 'quarter' => __('Quarter'), 'custom' => __('Custom range')] as $value => $label)
                    <button type="button" wire:click="$set('period', '{{ $value }}')"
                            class="shrink-0 whitespace-nowrap px-2.5 py-1 text-xs font-medium rounded-md transition-colors {{ $period === $value ? 'bg-surface text-primary-700 ring-1 ring-gray-200' : 'text-gray-600 hover:text-gray-900' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <input type="date" wire:model.live="dateFrom" aria-label="{{ __('Period start') }}"
                   class="h-8 rounded-control border-gray-300 bg-surface text-xs focus:border-primary-600 focus:ring-primary-600">
            <span class="text-gray-500 text-xs">—</span>
            <input type="date" wire:model.live="dateTo" aria-label="{{ __('Period end') }}"
                   class="h-8 rounded-control border-gray-300 bg-surface text-xs focus:border-primary-600 focus:ring-primary-600">
            <span class="sr-only">
                {{ __('Period: :from — :to', [
                    'from' => $periodFrom->timezone(config('app.timezone'))->format('d.m.Y'),
                    'to' => $periodTo->timezone(config('app.timezone'))->format('d.m.Y'),
                ]) }}
            </span>
        </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-card>
            <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('By department') }}</h3>
            @php($chartEmpty = collect($departmentChart['data']['datasets'] ?? [])->flatMap(fn ($d) => $d['data'] ?? [])->sum() == 0)
            <div wire:ignore class="h-64 relative">
                <canvas id="dashboard-department-chart" data-chart='@json($departmentChart)' @if ($chartEmpty) hidden @endif></canvas>
                <div data-chart-empty="dashboard-department-chart" class="absolute inset-0" @unless ($chartEmpty) hidden @endunless><p class="h-full flex items-center justify-center text-sm text-gray-600">{{ __('No data for this period.') }}</p></div>
            </div>
        </x-card>
        <x-card>
            <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('By category') }}</h3>
            @php($chartEmpty = collect($categoryChart['data']['datasets'] ?? [])->flatMap(fn ($d) => $d['data'] ?? [])->sum() == 0)
            <div wire:ignore class="h-64 relative">
                <canvas id="dashboard-category-chart" data-chart='@json($categoryChart)' @if ($chartEmpty) hidden @endif></canvas>
                <div data-chart-empty="dashboard-category-chart" class="absolute inset-0" @unless ($chartEmpty) hidden @endunless><p class="h-full flex items-center justify-center text-sm text-gray-600">{{ __('No data for this period.') }}</p></div>
            </div>
        </x-card>
        <x-card class="flex flex-col justify-center">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('Average closing time') }}</h3>
            <p class="text-xs text-gray-500 mt-1">{{ __('For tasks completed in the selected period.') }}</p>
            @if ($avgClosingLabel)
                <p class="text-display font-semibold text-gray-900 mt-4">{{ $avgClosingLabel }}</p>
            @else
                <p class="text-sm text-gray-600 mt-4">{{ __('No data yet.') }}</p>
            @endif
            <p class="text-xs text-gray-500 mt-2">
                {{ __('Period: :from — :to', [
                    'from' => $periodFrom->timezone(config('app.timezone'))->format('d.m.Y'),
                    'to' => $periodTo->timezone(config('app.timezone'))->format('d.m.Y'),
                ]) }}
            </p>
        </x-card>
    </div>
    </section>
</div>
