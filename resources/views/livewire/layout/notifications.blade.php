<?php

use App\Support\InAppNotification;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Volt\Component;

new class extends Component
{
    public string $inbox = 'unread';

    public function with(): array
    {
        $user = auth()->user();
        $query = $user->notifications()->latest();

        if ($this->inbox === 'unread') {
            $query->whereNull('read_at');
        }

        return [
            'notifications' => $query->limit(25)->get(),
            'unreadCount' => $user->unreadNotifications()->count(),
        ];
    }

    public function showInbox(string $inbox): void
    {
        $this->inbox = in_array($inbox, ['unread', 'all'], true) ? $inbox : 'unread';
    }

    public function open(string $id): void
    {
        /** @var DatabaseNotification|null $notification */
        $notification = auth()->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return;
        }

        $notification->markAsRead();
        $this->openTarget($notification->data ?? []);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    /** @param  array<string, mixed>  $data */
    private function openTarget(array $data): void
    {
        $number = InAppNotification::taskNumber($data);

        if ($number !== null) {
            $this->dispatch('task-open-peek', number: $number);
            $this->js('if (!/^\\/tasks\\/?$/.test(window.location.pathname)) { Livewire.navigate('.json_encode(route('tasks.index', ['peek' => $number])).'); }');

            return;
        }

        $billingId = InAppNotification::billingItemId($data);

        if ($billingId !== null) {
            $this->redirect(route('billing.index', ['item' => $billingId]), navigate: true);
        }
    }
}; ?>

<div data-ui="notifications" class="relative">
    <x-dropdown align="right" width="w-96" contentClasses="py-1 bg-white">
        <x-slot name="trigger">
            <button type="button" class="relative inline-flex items-center justify-center p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition-colors" aria-label="{{ __('notification.title') }}">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
                @if ($unreadCount > 0)
                    <span data-ui="notifications-unread-count" class="absolute -top-0.5 -end-0.5 inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 text-[10px] font-bold leading-none text-white bg-red-500 rounded-full">
                        {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                    </span>
                @endif
            </button>
        </x-slot>

        <x-slot name="content">
            <div class="border-b border-gray-100 px-3 py-2" @click.stop>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-gray-900">{{ __('notification.inbox') }}</span>
                    @if ($unreadCount > 0)
                        <button type="button" wire:click="markAllAsRead" class="text-xs text-indigo-600 hover:text-indigo-800 whitespace-nowrap">
                            {{ __('notification.mark_all_read') }}
                        </button>
                    @endif
                </div>
                <div class="mt-2 inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5">
                    <button
                        type="button"
                        wire:click="showInbox('unread')"
                        class="rounded-md px-2.5 py-1 text-xs font-medium {{ $inbox === 'unread' ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
                    >
                        {{ __('notification.unread') }}
                        @if ($unreadCount > 0)
                            <span class="tabular-nums">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                        @endif
                    </button>
                    <button
                        type="button"
                        wire:click="showInbox('all')"
                        class="rounded-md px-2.5 py-1 text-xs font-medium {{ $inbox === 'all' ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
                    >
                        {{ __('notification.all') }}
                    </button>
                </div>
            </div>

            <div class="max-h-96 overflow-y-auto">
                @forelse ($notifications as $notification)
                    @php
                        $data = $notification->data ?? [];
                    @endphp
                    <button
                        type="button"
                        data-ui="notification-item"
                        wire:click="open('{{ $notification->id }}')"
                        class="flex w-full items-start gap-2.5 px-3 py-2.5 text-start border-b border-gray-50 hover:bg-gray-50 transition-colors {{ $notification->read_at ? 'opacity-70' : 'bg-indigo-50/50' }}"
                    >
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-indigo-500' }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ InAppNotification::heading($data) }}</span>
                            <span class="mt-0.5 block text-xs leading-4 text-gray-600">{{ InAppNotification::line($data) }}</span>
                            <span class="mt-1 block text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-gray-500">
                        {{ $inbox === 'unread' ? __('notification.caught_up') : __('notification.no_notifications') }}
                    </p>
                @endforelse
            </div>

            <div class="border-t border-gray-100 px-3 py-2" @click.stop>
                <a href="{{ route('notifications.index') }}" wire:navigate class="block text-center text-xs font-medium text-indigo-600 hover:text-indigo-800">
                    {{ __('notification.view_all') }}
                </a>
            </div>
        </x-slot>
    </x-dropdown>
</div>
