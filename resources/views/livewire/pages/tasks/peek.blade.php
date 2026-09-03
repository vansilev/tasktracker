<?php

use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskUnreadService;
use App\Services\TaskVisibilityService;
use App\Services\TaskWorkflowService;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public int $number;

    public ?string $pendingStatus = null;

    public string $comment = '';

    public function with(): array
    {
        $task = $this->task();
        $user = auth()->user();
        $transitions = $task
            ? app(TaskWorkflowService::class)->allowedTransitions($user, $task)
            : [];

        $comments = $task
            ? $task->comments()->with('author:id,name')->latest()->limit(5)->get()
            : collect();

        if ($task) {
            app(TaskUnreadService::class)->markSeen($user, $task);
        }

        return [
            'task' => $task,
            'transitions' => $transitions,
            'comments' => $comments,
        ];
    }

    public function selectTransition(string $status): void
    {
        $task = $this->task();
        abort_unless($task, 404);
        $target = TaskStatus::from($status);

        if (TaskStatus::requiresComment($target, $task->status)) {
            $this->pendingStatus = $status;
            $this->comment = '';

            return;
        }

        $this->runTransition($task, $target);
    }

    public function confirmTransition(): void
    {
        $task = $this->task();
        abort_unless($task && $this->pendingStatus, 404);
        $this->validate([
            'comment' => 'required|string|min:1|max:20000',
        ]);
        $this->runTransition($task, TaskStatus::from($this->pendingStatus), $this->comment);
    }

    public function cancelTransition(): void
    {
        $this->pendingStatus = null;
        $this->comment = '';
    }

    #[On('task-peek-updated')]
    public function refreshPeek(): void
    {
        $this->pendingStatus = null;
        $this->comment = '';
    }

    private function runTransition(Task $task, TaskStatus $to, ?string $comment = null): void
    {
        try {
            $undo = app(TaskWorkflowService::class)->transition(
                $task,
                auth()->user(),
                $to,
                $comment,
                ContentSource::PlainText,
            );
            $this->pendingStatus = null;
            $this->comment = '';
            $this->js(app(TaskWorkflowService::class)->undoToastScript(
                __('Status changed to :status', ['status' => $to->label()]),
                auth()->user(),
                [$undo],
            ));
            $this->dispatch('task-peek-updated');
        } catch (\InvalidArgumentException|\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->js('window.uiToast('.json_encode($e->getMessage()).')');
        }
    }

    private function task(): ?Task
    {
        return app(TaskVisibilityService::class)
            ->accessibleQuery(auth()->user())
            ->with([
                'initiator:id,name',
                'assignee:id,name',
                'department:id,name',
                'category:id,name',
                'parent:id,number,title',
                'subtasks:id,parent_id,number,title,status,assignee_id',
                'subtasks.assignee:id,name',
            ])
            ->where('number', $this->number)
            ->first();
    }
}; ?>

<div class="flex h-full min-h-0 flex-col">
@if (! $task)
    <div class="flex h-full items-center justify-center p-6 text-sm text-gray-500">{{ __('No matching tasks') }}</div>
@else
        <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <x-task-number :task="$task" class="text-base font-semibold text-gray-400 hover:text-indigo-700" />
                    <h2 class="truncate text-base font-semibold text-gray-900">{{ $task->title }}</h2>
                    <x-status-badge :status="$task->status" />
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    {{ $task->initiator?->name }}
                    <span aria-hidden="true">→</span>
                    {{ $task->assignee?->name }}
                    @if ($task->department?->name)
                        <span aria-hidden="true">·</span>
                        {{ $task->department->name }}
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="rounded-md px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50">
                    {{ __('Open full page') }}
                </a>
                <button type="button" wire:click="$dispatch('task-close-peek')" class="rounded-md p-1.5 text-gray-400 hover:bg-gray-50 hover:text-gray-700" aria-label="{{ __('Close') }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3">
            @if ($transitions !== [])
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($transitions as $transition)
                        @php
                            $isDestructive = in_array($transition, [TaskStatus::Cancelled, TaskStatus::Rejected], true);
                        @endphp
                        <x-action-button
                            :variant="$isDestructive ? 'danger' : 'secondary'"
                            wire:click="selectTransition('{{ $transition->value }}')"
                        >
                            {{ $task->status === TaskStatus::Completed && $transition === TaskStatus::InProgress ? __('task.reopen') : $transition->label() }}
                        </x-action-button>
                    @endforeach
                </div>
            @endif

            @if ($pendingStatus)
                <form wire:submit="confirmTransition" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <p class="text-xs font-medium text-amber-900">{{ __('Add a comment for this status change') }}</p>
                    <textarea wire:model="comment" rows="3" class="w-full rounded-md border-gray-200 text-sm" required></textarea>
                    <div class="flex gap-2">
                        <x-action-button variant="primary" type="submit">{{ __('Confirm') }}</x-action-button>
                        <x-action-button variant="ghost" type="button" wire:click="cancelTransition">{{ __('Cancel') }}</x-action-button>
                    </div>
                </form>
            @endif

            <div class="prose prose-sm max-w-none text-gray-800">
                {!! $task->renderedDescription() !!}
            </div>

            @if ($task->subtasks->isNotEmpty())
                <div>
                    <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ __('Subtasks') }}</p>
                    <ul class="space-y-1">
                        @foreach ($task->subtasks as $subtask)
                            <li>
                                <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-gray-50" wire:click="$dispatch('task-open-peek', { number: {{ $subtask->number }} })">
                                    <span class="tabular-nums text-gray-400">#{{ $subtask->number }}</span>
                                    <span class="min-w-0 truncate">{{ $subtask->title }}</span>
                                    <x-status-badge :status="$subtask->status" class="ml-auto shrink-0" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ __('Comments') }}</p>
                @php
                    $thread = $comments->sortBy('created_at')->values();
                @endphp
                <div class="min-w-0 space-y-2.5 overflow-x-hidden">
                    @forelse ($thread as $comment)
                        @php
                            $prev = $loop->index > 0 ? $thread[$loop->index - 1] : null;
                            $stacked = $prev
                                && (int) $prev->author_id === (int) $comment->author_id
                                && $prev->created_at && $comment->created_at
                                && $comment->created_at->diffInMinutes($prev->created_at) <= 8;
                        @endphp
                        <x-comment-message
                            :comment="$comment"
                            :mine="(int) $comment->author_id === (int) auth()->id()"
                            :stacked="$stacked"
                            compact
                        />
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No comments yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
@endif
</div>
