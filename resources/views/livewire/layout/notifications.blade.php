<?php

use App\Enums\TaskStatus;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Volt\Component;

new class extends Component
{
    public function with(): array
    {
        $user = auth()->user();

        $notifications = $user->notifications()
            ->orderByRaw('read_at IS NULL DESC')
            ->latest()
            ->limit(10)
            ->get();

        return [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ];
    }

    public function markAsRead(string $id): void
    {
        /** @var DatabaseNotification|null $notification */
        $notification = auth()->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return;
        }

        $notification->markAsRead();

        $taskId = $notification->data['task_id'] ?? null;

        if ($taskId) {
            $this->redirect(route('tasks.show', $taskId), navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    /** @param  array<string, mixed>  $data */
    public function renderText(array $data): string
    {
        $params = [
            'number' => $data['task_number'] ?? '',
            'title' => $data['task_title'] ?? '',
            'actor' => $data['actor_name'] ?? '',
            'excerpt' => $data['comment_excerpt'] ?? '',
        ];

        return match ($data['event'] ?? '') {
            'task.assigned' => __('notification.task_assigned', $params),
            'task.status_changed' => __('notification.task_status_changed', array_merge($params, [
                'old' => isset($data['old_status'])
                    ? TaskStatus::from($data['old_status'])->label()
                    : '',
                'new' => isset($data['new_status'])
                    ? TaskStatus::from($data['new_status'])->label()
                    : '',
            ])),
            'task.commented' => __('notification.task_commented', $params),
            'task.mentioned' => __('notification.task_mentioned', $params),
            'task.deadline_approaching' => __('notification.task_deadline_approaching', array_merge($params, [
                'deadline' => $this->formatDateTime($data['deadline'] ?? null),
            ])),
            'task.overdue' => __('notification.task_overdue', array_merge($params, [
                'deadline' => $this->formatDateTime($data['deadline'] ?? null),
            ])),
            'task.review_sla_expired' => __('notification.task_review_sla_expired', array_merge($params, [
                'review_due_at' => $this->formatDateTime($data['review_due_at'] ?? null),
            ])),
            default => '',
        };
    }

    public function formatDateTime(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return Carbon::parse($value)->timezone(config('app.timezone'))->format('d.m.Y H:i');
    }
}; ?>

<x-dropdown align="right" width="w-80" contentClasses="py-1 bg-white max-h-96 overflow-y-auto">
    <x-slot name="trigger">
        <button type="button" class="relative inline-flex items-center justify-center p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition-colors" aria-label="{{ __('notification.title') }}">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
            </svg>
            @if ($unreadCount > 0)
                <span class="absolute -top-0.5 -end-0.5 inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 text-[10px] font-bold leading-none text-white bg-red-500 rounded-full">
                    {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                </span>
            @endif
        </button>
    </x-slot>

    <x-slot name="content">
        <div class="px-4 py-2 border-b border-gray-100 flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-gray-900">{{ __('notification.title') }}</span>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllAsRead" class="text-xs text-indigo-600 hover:text-indigo-800 whitespace-nowrap">
                    {{ __('notification.mark_all_read') }}
                </button>
            @endif
        </div>

        @forelse ($notifications as $notification)
            <button
                type="button"
                wire:click="markAsRead('{{ $notification->id }}')"
                class="w-full text-start px-4 py-3 border-b border-gray-50 hover:bg-gray-50 transition-colors {{ $notification->read_at ? 'opacity-70' : 'bg-indigo-50/50' }}"
            >
                <p class="text-sm text-gray-800 leading-snug">{{ $this->renderText($notification->data) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
            </button>
        @empty
            <p class="px-4 py-6 text-sm text-gray-500 text-center">{{ __('notification.no_notifications') }}</p>
        @endforelse
    </x-slot>
</x-dropdown>
