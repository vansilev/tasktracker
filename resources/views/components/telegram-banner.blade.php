@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $reminders = app(\App\Services\ReminderSettings::class);
    // Shown only while it is true: with reminders off, or for someone outside the pilot, nothing would arrive anyway.
    $show = $user !== null
        && $reminders->enabled()
        && $reminders->allowsPersonalMessages($user->id)
        && $user->is_active
        && ! filled($user->telegram_chat_id)
        && filled(config('services.telegram.bot_username'))
        && ! app(\App\Services\BillingBot::class)->is($user)
        && ! in_array($user->id, $reminders->excludedUserIds(), true)
        && ! request()->routeIs('profile');
@endphp

@if ($show)
    {{-- Hidden until the next day once dismissed; the choice lives in the browser only. --}}
    <div
        x-data="{
            key: 'telegram-banner-hidden-{{ $user->id }}',
            today: new Date().toISOString().slice(0, 10),
            hidden: false,
            init() { this.hidden = localStorage.getItem(this.key) === this.today },
            hide() { localStorage.setItem(this.key, this.today); this.hidden = true },
        }"
        x-show="! hidden"
        x-cloak
        data-ui="telegram-banner"
        class="bg-amber-50 border-b border-amber-200"
        role="status"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-amber-900">
            <span class="min-w-0 flex-1">
                <span class="font-medium">{{ __('Telegram is not connected.') }}</span>
                {{ __('Reminders about deadlines, mentions and comments do not reach you.') }}
            </span>
            <a href="{{ route('profile') }}" wire:navigate class="shrink-0 rounded-lg bg-amber-600 px-3 py-1 text-xs font-medium text-white hover:bg-amber-700">
                {{ __('Connect Telegram') }}
            </a>
            <button type="button" x-on:click="hide()" class="shrink-0 text-xs text-amber-800 underline hover:text-amber-950">
                {{ __('Hide until tomorrow') }}
            </button>
        </div>
    </div>
@endif
