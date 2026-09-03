<?php

use App\Support\InAppNotification;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.tasks-layout', ['title' => 'Notifications'])] class extends Component
{
    use WithPagination;

    #[Url]
    public string $inbox = 'unread';

    public function showInbox(string $inbox): void
    {
        $this->inbox = in_array($inbox, ['unread', 'all'], true) ? $inbox : 'unread';
        $this->resetPage();
    }

    public function open(string $id): void
    {
        /** @var DatabaseNotification|null $notification */
        $notification = auth()->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return;
        }

        $notification->markAsRead();

        $data = $notification->data ?? [];
        $number = InAppNotification::taskNumber($data);

        if ($number !== null) {
            $this->redirect(route('tasks.index', ['peek' => $number]), navigate: true);

            return;
        }

        $billingId = InAppNotification::billingItemId($data);

        if ($billingId !== null) {
            $this->redirect(route('billing.index', ['item' => $billingId]), navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function with(): array
    {
        $user = auth()->user();
        $query = $user->notifications()->latest();

        if ($this->inbox === 'unread') {
            $query->whereNull('read_at');
        }

        return [
            'notifications' => $query->paginate(20),
            'unreadCount' => $user->unreadNotifications()->count(),
        ];
    }
}; ?>

<div data-ui="notifications-page" class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
            <button
                type="button"
                wire:click="showInbox('unread')"
                class="rounded-md px-3 py-1.5 text-sm font-medium {{ $inbox === 'unread' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}"
            >
                {{ __('notification.unread') }}
                @if ($unreadCount > 0)
                    <span class="tabular-nums">{{ $unreadCount }}</span>
                @endif
            </button>
            <button
                type="button"
                wire:click="showInbox('all')"
                class="rounded-md px-3 py-1.5 text-sm font-medium {{ $inbox === 'all' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}"
            >
                {{ __('notification.all') }}
            </button>
        </div>

        @if ($unreadCount > 0)
            <button type="button" wire:click="markAllAsRead" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                {{ __('notification.mark_all_read') }}
            </button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
        @forelse ($notifications as $notification)
            @php
                $data = $notification->data ?? [];
            @endphp
            <button
                type="button"
                data-ui="notification-item"
                wire:click="open('{{ $notification->id }}')"
                class="flex w-full items-start gap-3 border-b border-gray-50 px-4 py-3 text-start last:border-b-0 hover:bg-gray-50 {{ $notification->read_at ? '' : 'bg-indigo-50/40' }}"
            >
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-gray-200' : 'bg-indigo-500' }}" aria-hidden="true"></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-gray-900">{{ InAppNotification::heading($data) }}</span>
                    <span class="mt-0.5 block text-sm text-gray-600">{{ InAppNotification::line($data) }}</span>
                    <span class="mt-1 block text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</span>
                </span>
            </button>
        @empty
            <x-empty-state :title="$inbox === 'unread' ? __('notification.caught_up') : __('notification.no_notifications')">
                {{ $inbox === 'unread' ? __('notification.caught_up_hint') : __('notification.no_notifications_hint') }}
            </x-empty-state>
        @endforelse

        @if ($notifications->hasPages())
            <div class="border-t border-gray-100 bg-gray-50/50 px-4 py-3">{{ $notifications->links() }}</div>
        @endif
    </div>
</div>
