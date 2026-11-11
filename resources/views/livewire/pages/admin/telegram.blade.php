<?php

use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\AuditLogService;
use App\Services\BillingBot;
use App\Services\ReminderSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    /** Personal Telegram messages a person can switch off in the profile, and their names here. */
    public const EVENTS = [
        'task.reminder' => 'task_reminder',
        'task.mentioned' => 'task_mentioned',
        'task.commented' => 'task_commented',
    ];

    #[Url]
    public string $show = 'all';

    public function with(ReminderSettings $settings, BillingBot $billingBot): array
    {
        $excluded = $settings->excludedUserIds();
        $switchedOff = UserNotificationPreference::query()
            ->where('channel', 'telegram')
            ->where('enabled', false)
            ->whereIn('event', array_keys(self::EVENTS))
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('event')->all());

        $people = User::query()
            ->where('is_active', true)
            ->with('department:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'user' => $user,
                'linked' => filled($user->telegram_chat_id),
                'service' => in_array($user->id, $excluded, true) || $billingBot->is($user),
                'off' => $switchedOff->get($user->id, []),
            ]);

        $countable = $people->where('service', false);

        return [
            'people' => $people->filter(fn (array $row) => match ($this->show) {
                'not_linked' => ! $row['linked'] && ! $row['service'],
                'switched_off' => $row['off'] !== [],
                default => true,
            })->values(),
            'total' => $countable->count(),
            'linked' => $countable->where('linked', true)->count(),
            'notLinked' => $countable->where('linked', false)->count(),
            'switchedOff' => $people->filter(fn (array $row) => $row['off'] !== [])->count(),
            'remindersOn' => $settings->enabled(),
        ];
    }

    /**
     * Puts back the personal Telegram messages the person switched off — on a department
     * head's request. The person can switch them off again; it is written to the audit log.
     */
    public function enableAll(int $userId, AuditLogService $audit): void
    {
        $user = User::query()->where('is_active', true)->findOrFail($userId);

        $events = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', 'telegram')
            ->where('enabled', false)
            ->whereIn('event', array_keys(self::EVENTS))
            ->pluck('event')
            ->all();

        if ($events === []) {
            return;
        }

        UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', 'telegram')
            ->whereIn('event', $events)
            ->update(['enabled' => true]);

        $audit->log('notifications.enabled_by_admin', auth()->user(), $user, ['telegram_off' => $events], ['telegram_off' => []]);

        session()->flash('status', __('Telegram notifications are switched back on for :name.', ['name' => $user->name]));
    }
}; ?>

<div class="space-y-4">
    <x-auth-session-status :status="session('status')" />

    @unless ($remindersOn)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
            {{ __('Morning reminders are switched off in the system settings: nobody gets them yet.') }}
        </div>
    @endunless

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3" data-ui="telegram-counters">
        @foreach ([
            ['all', __('People'), $total, 'text-gray-900'],
            ['all', __('Connected'), $linked, 'text-green-700'],
            ['not_linked', __('Not connected'), $notLinked, $notLinked > 0 ? 'text-red-600' : 'text-gray-900'],
            ['switched_off', __('Switched something off'), $switchedOff, $switchedOff > 0 ? 'text-amber-600' : 'text-gray-900'],
        ] as [$filter, $label, $value, $colour])
            <button type="button" wire:click="$set('show', '{{ $filter }}')"
                class="rounded-xl border bg-white p-4 text-left shadow-sm transition-colors hover:bg-gray-50 {{ $show === $filter && $loop->index !== 1 ? 'border-indigo-300' : 'border-gray-100' }}">
                <span class="block text-xs text-gray-500">{{ $label }}</span>
                <span class="block text-2xl font-semibold tabular-nums {{ $colour }}">{{ $value }}</span>
            </button>
        @endforeach
    </div>

    <x-card padding="p-0">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm" data-ui="telegram-people">
                <thead class="bg-gray-50/80">
                    <tr>
                        @foreach ([__('Employee'), __('Department'), 'Telegram', __('Switched off by the person'), ''] as $heading)
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($people as $row)
                        <tr class="hover:bg-gray-50/80 transition-colors" wire:key="telegram-user-{{ $row['user']->id }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900">
                                {{ $row['user']->name }}
                                @if ($row['service'])
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-normal text-gray-500">{{ __('excluded from reminders') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $row['user']->department?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                @if ($row['linked'])
                                    <span class="text-green-700">{{ __('Connected') }}</span>
                                    @if ($row['user']->telegram_username)
                                        <span class="text-xs text-gray-500">{{ '@'.$row['user']->telegram_username }}</span>
                                    @endif
                                @else
                                    <span class="{{ $row['service'] ? 'text-gray-500' : 'text-red-600 font-medium' }}">{{ __('Not connected') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-gray-700">
                                @forelse ($row['off'] as $event)
                                    <span class="mr-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800 ring-1 ring-amber-200">{{ __('notification.events.'.self::EVENTS[$event]) }}</span>
                                @empty
                                    <span class="text-gray-400">—</span>
                                @endforelse
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                @if ($row['off'] !== [])
                                    <button type="button" wire:click="enableAll({{ $row['user']->id }})"
                                        wire:confirm="{{ __('Switch Telegram notifications back on for :name?', ['name' => $row['user']->name]) }}"
                                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                                        {{ __('Switch back on') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-2.5"><x-empty-state>{{ __('Nobody here.') }}</x-empty-state></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
