<?php

use App\Models\UserNotificationPreference;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    /** @var array<string, string> */
    private const EVENTS = [
        'task_assigned' => 'task.assigned',
        'task_status_changed' => 'task.status_changed',
        'task_commented' => 'task.commented',
        'task_mentioned' => 'task.mentioned',
        'task_deadline_approaching' => 'task.deadline_approaching',
        'task_overdue' => 'task.overdue',
        'task_review_sla_expired' => 'task.review_sla_expired',
        'billing_due_7' => 'billing.due_7',
        'billing_due_3' => 'billing.due_3',
        'billing_overdue' => 'billing.overdue',
    ];

    /** @var list<string> */
    private const CHANNELS = ['database', 'email', 'telegram'];

    /** @var array<string, array<string, bool>> */
    public array $preferences = [];

    public function mount(): void
    {
        $stored = UserNotificationPreference::query()
            ->where('user_id', Auth::id())
            ->get()
            ->groupBy('event')
            ->map(fn ($rows) => $rows->keyBy('channel'));

        foreach (self::EVENTS as $eventKey => $eventDot) {
            foreach (self::CHANNELS as $channel) {
                $preference = $stored->get($eventDot)?->get($channel);
                $this->preferences[$eventKey][$channel] = $preference === null
                    ? true
                    : $preference->enabled;
            }
        }
    }

    public function save(): void
    {
        $userId = Auth::id();
        $dmEnabled = $this->telegramDmEnabled();

        foreach (self::EVENTS as $eventKey => $eventDot) {
            foreach (self::CHANNELS as $channel) {
                if ($channel === 'telegram' && ! $dmEnabled) {
                    continue;
                }

                UserNotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $userId,
                        'event' => $eventDot,
                        'channel' => $channel,
                    ],
                    [
                        'enabled' => (bool) ($this->preferences[$eventKey][$channel] ?? true),
                    ],
                );
            }
        }

        $this->dispatch('notification-preferences-saved');
    }

    public function telegramDmEnabled(): bool
    {
        return (bool) config('services.telegram.dm_enabled');
    }

    /**
     * @return array<string, string>
     */
    public function events(): array
    {
        return self::EVENTS;
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return self::CHANNELS;
    }
}; ?>

<section>
    <header>
        <h2 class="text-sm font-semibold text-gray-900">
            {{ __('notification.preferences_title') }}
        </h2>

        <p class="mt-1 text-xs text-gray-500">
            {{ __('notification.preferences_description') }}
        </p>
    </header>

    <form wire:submit="save" class="mt-4 space-y-4">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('notification.event') }}</th>
                        @foreach ($this->channels() as $channel)
                            <th class="px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wide text-center whitespace-nowrap">
                                {{ __('notification.channels.'.$channel) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($this->events() as $eventKey => $eventDot)
                        <tr wire:key="pref-{{ $eventKey }}">
                            <td class="px-4 py-2 text-sm text-gray-700">
                                {{ __('notification.events.'.$eventKey) }}
                            </td>
                            @foreach ($this->channels() as $channel)
                                <td class="px-4 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        wire:model="preferences.{{ $eventKey }}.{{ $channel }}"
                                        @disabled($channel === 'telegram' && ! $this->telegramDmEnabled())
                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 disabled:opacity-40"
                                    />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500">
            {{ $this->telegramDmEnabled()
                ? __('notification.channels_hint')
                : __('notification.channels_hint_group') }}
        </p>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            <x-action-message class="me-3" on="notification-preferences-saved">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</section>
