<?php

use App\Models\Task;
use App\Services\TaskService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout')] class extends Component
{
    use WithPagination;

    public function restore(int $taskId, TaskService $tasks): void
    {
        $task = Task::onlyTrashed()->findOrFail($taskId);

        abort_unless(auth()->user()->can('restore', $task), 403);

        $tasks->restore($task, auth()->user());

        session()->flash('status', __('Task restored.'));
    }

    public function with(): array
    {
        return [
            'tasks' => Task::onlyTrashed()
                ->with(['initiator:id,name', 'assignee:id,name', 'department:id,name'])
                ->orderByDesc('deleted_at')
                ->paginate(25),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-auth-session-status :status="session('status')" />

    <x-card padding="p-0" class="overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100">
            <h2 class="text-sm font-semibold text-gray-900">{{ __('Deleted tasks') }}</h2>
            <p class="text-xs text-gray-500 mt-0.5">{{ __('Soft-deleted tasks can be restored.') }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50/80">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">#</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Title') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Assignee') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Deleted at') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($tasks as $task)
                        <tr class="hover:bg-gray-50/80" wire:key="deleted-task-{{ $task->id }}">
                            <td class="px-4 py-2.5 text-gray-700">{{ $task->number }}</td>
                            <td class="px-4 py-2.5 text-gray-900">{{ $task->title }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $task->assignee?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap">{{ $task->deleted_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button
                                    type="button"
                                    wire:click="restore({{ $task->id }})"
                                    wire:confirm="{{ __('Restore this task?') }}"
                                    class="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                >
                                    {{ __('Restore') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-2.5">
                                <x-empty-state>{{ __('No deleted tasks.') }}</x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tasks->hasPages())
            <div class="px-4 py-2.5 border-t border-gray-100">
                {{ $tasks->links() }}
            </div>
        @endif
    </x-card>
</div>
