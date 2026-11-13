<?php

use App\Models\NotificationDelivery;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $filterStatus = '';

    #[Url]
    public string $filterChannel = '';

    #[Url]
    public string $filterUserId = '';

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $query = NotificationDelivery::query()
            ->with(['user:id,name', 'task:id,number,title'])
            ->when(
                in_array($this->filterStatus, [NotificationDelivery::STATUS_SENT, NotificationDelivery::STATUS_FAILED], true),
                fn ($q) => $q->where('status', $this->filterStatus),
            )
            ->when($this->filterChannel !== '', fn ($q) => $q->where('channel', $this->filterChannel))
            ->when(ctype_digit($this->filterUserId), fn ($q) => $q->where('user_id', (int) $this->filterUserId))
            ->orderByDesc('id');

        return [
            'deliveries' => $query->paginate(50),
            'channels' => NotificationDelivery::query()->distinct()->orderBy('channel')->pluck('channel'),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-card padding="p-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <select wire:model.live="filterStatus" class="rounded-lg border-gray-300 text-sm" aria-label="{{ __('Result') }}">
                <option value="">{{ __('All results') }}</option>
                <option value="{{ NotificationDelivery::STATUS_SENT }}">{{ __('Delivered') }}</option>
                <option value="{{ NotificationDelivery::STATUS_FAILED }}">{{ __('Failed') }}</option>
            </select>
            <select wire:model.live="filterChannel" class="rounded-lg border-gray-300 text-sm" aria-label="{{ __('Channel') }}">
                <option value="">{{ __('All channels') }}</option>
                @foreach ($channels as $channel)
                    <option value="{{ $channel }}">{{ $channel }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterUserId" class="rounded-lg border-gray-300 text-sm" aria-label="{{ __('Recipient') }}">
                <option value="">{{ __('All users') }}</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
    </x-card>

    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm" data-ui="delivery-log">
                <thead class="bg-gray-50/80">
                    <tr>
                        @foreach ([__('Date'), __('Recipient'), __('Notification'), __('Channel'), __('Task'), __('Result')] as $heading)
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($deliveries as $delivery)
                        <tr class="hover:bg-gray-50/80 transition-colors" wire:key="delivery-{{ $delivery->id }}">
                            <td class="px-4 py-2.5 whitespace-nowrap text-gray-700">{{ $delivery->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-gray-800">{{ $delivery->user?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-700 font-mono text-xs">{{ $delivery->event }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $delivery->channel }}</td>
                            <td class="px-4 py-2.5 text-gray-700 max-w-xs truncate">
                                @if ($delivery->task)
                                    <a href="{{ route('tasks.show', $delivery->task) }}" class="text-primary-600 hover:text-primary-800" wire:navigate>#{{ $delivery->task->number }}</a>
                                    {{ $delivery->task->title }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($delivery->status === NotificationDelivery::STATUS_FAILED)
                                    <span class="text-red-600 font-medium">{{ __('Failed') }}</span>
                                    @if ($delivery->error)
                                        <p class="mt-0.5 text-xs text-gray-500 max-w-md break-words">{{ $delivery->error }}</p>
                                    @endif
                                @else
                                    <span class="text-green-700">{{ __('Delivered') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-2.5">
                                <x-empty-state>{{ __('Nothing has been sent yet.') }}</x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{ $deliveries->links() }}
</div>
