<?php

use App\Models\Task;
use App\Services\TaskVisibilityService;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $open = false;

    public string $query = '';

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->show();
    }

    public function show(): void
    {
        $this->open = true;
        $this->query = '';
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    public function with(): array
    {
        $user = auth()->user();
        $query = app(TaskVisibilityService::class)->accessibleQuery($user)
            ->with(['assignee:id,name']);

        $term = trim($this->query);
        if ($term !== '') {
            $number = ltrim($term, '#');
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like, $number) {
                $q->where('number', $number)
                    ->orWhere('number', 'like', $number.'%')
                    ->orWhere('title', 'like', $like);
            });
        }

        $tasks = $query
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'number', 'title', 'status', 'assignee_id', 'updated_at']);

        return [
            'tasks' => $tasks,
            'canCreate' => $user->can('create', Task::class),
        ];
    }
}; ?>

<div x-data x-on:ui-command-toggle.window="$wire.toggle()">
    @if ($open)
        <div
            data-ui="command-palette"
            class="fixed inset-0 z-[95] flex items-start justify-center bg-gray-900/40 px-4 pt-[12vh]"
            wire:click="close"
            wire:keydown.escape.window="close"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Command palette') }}"
        >
            <div class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl" wire:click.stop>
                <div class="flex items-center gap-2 border-b border-gray-100 px-3 py-2">
                    <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        type="search"
                        wire:model.live.debounce.200ms="query"
                        autofocus
                        class="w-full border-0 bg-transparent py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0"
                        placeholder="{{ __('Search tasks or type a number...') }}"
                        autocomplete="off"
                    >
                    <x-kbd>Esc</x-kbd>
                </div>

                <div class="max-h-80 overflow-y-auto p-1">
                    @if ($canCreate)
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm text-gray-900 hover:bg-indigo-50"
                            onclick="Livewire.navigate('{{ route('tasks.create') }}')"
                        >
                            <span>{{ __('Create task') }}</span>
                            <x-kbd>C</x-kbd>
                        </button>
                    @endif

                    <p class="px-3 py-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ __('Tasks') }}</p>

                    @forelse ($tasks as $task)
                        <button
                            type="button"
                            wire:key="command-task-{{ $task->id }}"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-indigo-50"
                            onclick="Livewire.navigate('{{ route('tasks.show', $task) }}')"
                        >
                            <span class="shrink-0 tabular-nums text-gray-400">#{{ $task->number }}</span>
                            <span class="min-w-0 truncate font-medium text-gray-900">{{ $task->title ?: '#'.$task->number }}</span>
                            <span class="ml-auto shrink-0 text-xs text-gray-400">{{ $task->assignee?->name }}</span>
                        </button>
                    @empty
                        <p class="px-3 py-2 text-sm text-gray-500">{{ __('No matching tasks') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
