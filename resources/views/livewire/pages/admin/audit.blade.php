<?php

use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogPresenter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $filterAction = '';

    #[Url]
    public string $filterActorId = '';

    #[Url]
    public string $filterDateFrom = '';

    #[Url]
    public string $filterDateTo = '';

    /** @var list<int> */
    public array $expandedLogIds = [];

    public function updatedFilterAction(): void
    {
        $this->resetPage();
    }

    public function updatedFilterActorId(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDateTo(): void
    {
        $this->resetPage();
    }

    public function toggleDetails(int $logId): void
    {
        if (in_array($logId, $this->expandedLogIds, true)) {
            $this->expandedLogIds = array_values(array_diff($this->expandedLogIds, [$logId]));
        } else {
            $this->expandedLogIds[] = $logId;
        }
    }

    public function with(AuditLogPresenter $presenter): array
    {
        $query = AuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at');

        if ($this->filterAction !== '') {
            $query->where('action', $this->filterAction);
        }

        if ($this->filterActorId !== '') {
            $query->where('actor_id', (int) $this->filterActorId);
        }

        if ($this->filterDateFrom !== '') {
            $query->whereDate('created_at', '>=', $this->filterDateFrom);
        }

        if ($this->filterDateTo !== '') {
            $query->whereDate('created_at', '<=', $this->filterDateTo);
        }

        $logs = $query->paginate(25);

        $taskIds = $logs->getCollection()
            ->filter(fn (AuditLog $log) => $log->entity_type === Task::class && $log->entity_id)
            ->pluck('entity_id')
            ->unique()
            ->values();

        $taskNumbers = $taskIds->isEmpty()
            ? []
            : Task::query()->whereIn('id', $taskIds)->pluck('number', 'id')->all();

        return [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'actors' => User::query()->orderBy('name')->get(['id', 'name']),
            'presenter' => $presenter,
            'taskNumbers' => $taskNumbers,
        ];
    }
}; ?>

<div class="space-y-4">
    <x-card padding="p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[140px] flex-1">
                <x-input-label :value="__('Action')" class="text-xs" />
                <select wire:model.live="filterAction" class="w-full mt-1 border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <option value="">{{ __('All actions') }}</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}">{{ $presenter->actionLabel($action) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[140px] flex-1">
                <x-input-label :value="__('Actor')" class="text-xs" />
                <select wire:model.live="filterActorId" class="w-full mt-1 border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <option value="">{{ __('All actors') }}</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[130px]">
                <x-input-label :value="__('Date from')" class="text-xs" />
                <x-text-input wire:model.live="filterDateFrom" type="date" class="w-full mt-1" />
            </div>
            <div class="min-w-[130px]">
                <x-input-label :value="__('Date to')" class="text-xs" />
                <x-text-input wire:model.live="filterDateTo" type="date" class="w-full mt-1" />
            </div>
        </div>
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50/80">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Date') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Actor') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('IP') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Action') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Entity') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Changes') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-gray-50/80 transition-colors" wire:key="audit-log-{{ $log->id }}">
                            <td class="px-4 py-2.5 whitespace-nowrap text-gray-700">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-gray-800">{{ $log->actor?->name ?? __('System') }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 font-mono text-xs">{{ $log->ip ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-800">{{ $presenter->actionLabel($log->action) }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $presenter->entityLabel($log->entity_type, $log->entity_id, $taskNumbers) }}</td>
                            <td class="px-4 py-2.5 text-gray-600 max-w-md">{{ $presenter->summarize($log->old_values, $log->new_values, $log->action) }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap">
                                @if ($log->old_values !== null || $log->new_values !== null)
                                    <button
                                        type="button"
                                        wire:click="toggleDetails({{ $log->id }})"
                                        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                                    >
                                        {{ in_array($log->id, $expandedLogIds, true) ? __('Hide details') : __('Details') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @if (in_array($log->id, $expandedLogIds, true))
                            <tr wire:key="audit-log-details-{{ $log->id }}">
                                <td colspan="7" class="px-4 py-3 bg-gray-50/80 border-t border-gray-100">
                                    <pre class="text-xs font-mono text-gray-700 whitespace-pre-wrap break-words overflow-x-auto">{{ $presenter->detailJson($log->old_values, $log->new_values) }}</pre>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-2.5">
                                <x-empty-state>{{ __('No audit entries yet.') }}</x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="px-4 py-2.5 border-t border-gray-100">
                {{ $logs->links() }}
            </div>
        @endif
    </x-card>
</div>
