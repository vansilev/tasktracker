<?php

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ReminderSettings;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    public bool $googleSsoEnabled = false;

    public bool $passwordLoginEnabled = true;

    public string $allowedEmailDomains = '';

    public int $slaReviewDays = 3;

    public int $attachmentMaxKb = 10240;

    public bool $reminderEnabled = false;

    public bool $reminderCommentNotices = true;

    public string $reminderThresholdDays = '3, 1, 0';

    public string $reminderMorningTime = '09:30';

    public string $reminderDigestTime = '10:00';

    public string $reminderWorkStart = '09:00';

    public string $reminderWorkEnd = '19:00';

    /** @var list<int|string> */
    public array $reminderWorkDays = [1, 2, 3, 4, 5];

    public int $reminderEscalationAfterDays = 3;

    public int $reminderEscalationRepeatDays = 7;

    public int $reminderStaleWorkDays = 5;

    public int $reminderMentionRepeatWorkDays = 1;

    public int $reminderMentionDigestWorkDays = 2;

    /** @var list<int|string> */
    public array $reminderDigestRecipientIds = [];

    /** @var list<int|string> */
    public array $reminderExcludedUserIds = [];

    /** @var list<int|string> */
    public array $reminderNoEscalationHeadIds = [];

    /** @var list<int|string> */
    public array $reminderPilotUserIds = [];

    /** @var list<string> */
    private const SETTING_KEYS = [
        'google_sso_enabled',
        'password_login_enabled',
        'allowed_email_domains',
        'sla_review_days',
        'attachment_max_kb',
    ];

    public function mount(SettingsService $settings, ReminderSettings $reminders): void
    {
        $this->reminderEnabled = $reminders->enabled();
        $this->reminderCommentNotices = $reminders->commentNoticesEnabled();
        $this->reminderThresholdDays = implode(', ', $reminders->thresholdDays());
        $this->reminderMorningTime = $reminders->morningTime();
        $this->reminderDigestTime = $reminders->digestTime();
        $this->reminderWorkStart = $reminders->workStart();
        $this->reminderWorkEnd = $reminders->workEnd();
        $this->reminderWorkDays = $reminders->workDays();
        $this->reminderEscalationAfterDays = $reminders->escalationAfterDays();
        $this->reminderEscalationRepeatDays = $reminders->escalationRepeatDays();
        $this->reminderStaleWorkDays = $reminders->staleWorkDays();
        $this->reminderMentionRepeatWorkDays = $reminders->mentionRepeatWorkDays();
        $this->reminderMentionDigestWorkDays = $reminders->mentionDigestWorkDays();
        $this->reminderDigestRecipientIds = $reminders->digestRecipientIds();
        $this->reminderExcludedUserIds = $reminders->excludedUserIds();
        $this->reminderNoEscalationHeadIds = $reminders->noEscalationHeadIds();
        $this->reminderPilotUserIds = $reminders->pilotUserIds();

        $this->googleSsoEnabled = (bool) $settings->get('google_sso_enabled');
        $this->passwordLoginEnabled = (bool) $settings->get('password_login_enabled', true);

        $domains = $settings->get('allowed_email_domains', []);
        $this->allowedEmailDomains = is_array($domains) ? implode(', ', $domains) : (string) $domains;

        $this->slaReviewDays = (int) $settings->get('sla_review_days', 3);
        $this->attachmentMaxKb = (int) $settings->get('attachment_max_kb', 10240);
    }

    public function save(SettingsService $settings, AuditLogService $audit): void
    {
        $this->validate([
            'googleSsoEnabled' => 'boolean',
            'passwordLoginEnabled' => 'boolean',
            'allowedEmailDomains' => 'required|string',
            'slaReviewDays' => 'required|integer|min:1|max:30',
            'attachmentMaxKb' => 'required|integer|min:128|max:102400',
        ]);

        if (! $this->googleSsoEnabled && ! $this->passwordLoginEnabled) {
            throw ValidationException::withMessages([
                'passwordLoginEnabled' => [__('At least one sign-in method must remain enabled.')],
            ]);
        }

        $domains = array_values(array_filter(array_map('trim', explode(',', $this->allowedEmailDomains))));

        if ($domains === []) {
            throw ValidationException::withMessages([
                'allowedEmailDomains' => [__('At least one email domain is required.')],
            ]);
        }

        foreach ($domains as $domain) {
            if ($domain === '' || str_contains($domain, ' ') || str_contains($domain, '@')) {
                throw ValidationException::withMessages([
                    'allowedEmailDomains' => [__('Each domain must be a non-empty value without spaces or @.')],
                ]);
            }
        }

        $oldValues = [];
        foreach (self::SETTING_KEYS as $key) {
            $oldValues[$key] = $settings->get($key);
        }

        $newValues = [
            'google_sso_enabled' => $this->googleSsoEnabled,
            'password_login_enabled' => $this->passwordLoginEnabled,
            'allowed_email_domains' => $domains,
            'sla_review_days' => $this->slaReviewDays,
            'attachment_max_kb' => $this->attachmentMaxKb,
        ];

        foreach ($newValues as $key => $value) {
            $settings->set($key, $value);
        }

        $audit->log(
            'settings.updated',
            auth()->user(),
            oldValues: $oldValues,
            newValues: $newValues,
        );

        session()->flash('status', __('Settings saved.'));
    }

    public function saveReminders(SettingsService $settings, AuditLogService $audit): void
    {
        $this->validate([
            'reminderEnabled' => 'boolean',
            'reminderCommentNotices' => 'boolean',
            'reminderThresholdDays' => ['required', 'string', 'regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'],
            'reminderMorningTime' => 'required|date_format:H:i',
            'reminderDigestTime' => 'required|date_format:H:i',
            'reminderWorkStart' => 'required|date_format:H:i',
            'reminderWorkEnd' => 'required|date_format:H:i|after:reminderWorkStart',
            'reminderWorkDays' => 'required|array|min:1',
            'reminderWorkDays.*' => 'integer|between:1,7',
            'reminderEscalationAfterDays' => 'required|integer|min:1|max:60',
            'reminderEscalationRepeatDays' => 'required|integer|min:1|max:60',
            'reminderStaleWorkDays' => 'required|integer|min:1|max:60',
            'reminderMentionRepeatWorkDays' => 'required|integer|min:1|max:30',
            'reminderMentionDigestWorkDays' => 'required|integer|min:1|max:30|gte:reminderMentionRepeatWorkDays',
            'reminderDigestRecipientIds' => 'array',
            'reminderDigestRecipientIds.*' => 'integer|exists:users,id',
            'reminderExcludedUserIds' => 'array',
            'reminderExcludedUserIds.*' => 'integer|exists:users,id',
            'reminderNoEscalationHeadIds' => 'array',
            'reminderNoEscalationHeadIds.*' => 'integer|exists:users,id',
            'reminderPilotUserIds' => 'array',
            'reminderPilotUserIds.*' => 'integer|exists:users,id',
        ]);

        foreach (['reminderMorningTime', 'reminderDigestTime'] as $field) {
            if ($this->{$field} < $this->reminderWorkStart || $this->{$field} > $this->reminderWorkEnd) {
                throw ValidationException::withMessages([
                    $field => [__('The time must be within working hours.')],
                ]);
            }
        }

        $oldValues = [];
        foreach (ReminderSettings::KEYS as $key) {
            $oldValues[$key] = $settings->get($key);
        }

        $newValues = [
            'reminder_enabled' => $this->reminderEnabled,
            'reminder_comment_notices' => $this->reminderCommentNotices,
            'reminder_threshold_days' => $this->intList(explode(',', $this->reminderThresholdDays), descending: true),
            'reminder_morning_time' => $this->reminderMorningTime,
            'reminder_digest_time' => $this->reminderDigestTime,
            'reminder_work_start' => $this->reminderWorkStart,
            'reminder_work_end' => $this->reminderWorkEnd,
            'reminder_work_days' => $this->intList($this->reminderWorkDays),
            'reminder_escalation_after_days' => $this->reminderEscalationAfterDays,
            'reminder_escalation_repeat_days' => $this->reminderEscalationRepeatDays,
            'reminder_stale_work_days' => $this->reminderStaleWorkDays,
            'reminder_mention_repeat_work_days' => $this->reminderMentionRepeatWorkDays,
            'reminder_mention_digest_work_days' => $this->reminderMentionDigestWorkDays,
            'reminder_digest_recipient_ids' => $this->intList($this->reminderDigestRecipientIds),
            'reminder_excluded_user_ids' => $this->intList($this->reminderExcludedUserIds),
            'reminder_no_escalation_head_ids' => $this->intList($this->reminderNoEscalationHeadIds),
            'reminder_pilot_user_ids' => $this->intList($this->reminderPilotUserIds),
        ];

        foreach ($newValues as $key => $value) {
            $settings->set($key, $value);
        }

        $audit->log(
            'settings.updated',
            auth()->user(),
            oldValues: $oldValues,
            newValues: $newValues,
        );

        session()->flash('reminders-status', __('Reminder settings saved.'));
    }

    /** @return array{users: \Illuminate\Support\Collection<int, User>} */
    public function with(): array
    {
        return [
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function intList(array $values, bool $descending = false): array
    {
        $list = array_values(array_unique(array_map('intval', array_map('trim', array_map('strval', $values)))));
        $descending ? rsort($list) : sort($list);

        return $list;
    }
}; ?>

<div class="space-y-4">
    <x-auth-session-status :status="session('status')" />

    <form wire:submit="save">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <x-card class="space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Sign-in methods') }}</h3>
                    <div class="space-y-3">
                        <label class="flex items-center justify-between gap-3 py-1">
                            <span class="text-xs text-gray-700">{{ __('Google SSO') }}</span>
                            <input type="checkbox" wire:model="googleSsoEnabled" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                        </label>
                        <x-input-error :messages="$errors->get('googleSsoEnabled')" />

                        <label class="flex items-center justify-between gap-3 py-1">
                            <span class="text-xs text-gray-700">{{ __('Password login') }}</span>
                            <input type="checkbox" wire:model="passwordLoginEnabled" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                        </label>
                        <x-input-error :messages="$errors->get('passwordLoginEnabled')" />
                    </div>
                </div>

                <div>
                    <x-input-label for="allowedEmailDomains" :value="__('Allowed email domains')" class="text-xs" />
                    <x-text-input wire:model="allowedEmailDomains" id="allowedEmailDomains" class="block mt-1 w-full" placeholder="tcsavant.com, example.com" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Comma-separated list of allowed email domains') }}</p>
                    <x-input-error :messages="$errors->get('allowedEmailDomains')" class="mt-1" />
                </div>
            </x-card>

            <x-card class="space-y-4">
                <div>
                    <x-input-label for="slaReviewDays" :value="__('SLA review period (days)')" class="text-xs" />
                    <x-text-input wire:model="slaReviewDays" id="slaReviewDays" type="number" min="1" max="30" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('slaReviewDays')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="attachmentMaxKb" :value="__('Attachment size limit (KB)')" class="text-xs" />
                    <x-text-input wire:model="attachmentMaxKb" id="attachmentMaxKb" type="number" min="128" max="102400" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('attachmentMaxKb')" class="mt-1" />
                </div>
            </x-card>
        </div>

        <div class="mt-4 flex justify-end">
            <x-button variant="primary" type="submit">{{ __('Save') }}</x-button>
        </div>
    </form>

    <form wire:submit="saveReminders">
        <x-card class="mt-4 space-y-5" data-ui="reminder-settings">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">{{ __('Telegram reminders') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('Times are Kyiv time. Working days skip weekends only.') }}</p>
            </div>

            <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3" data-ui="reminder-enabled">
                <input type="checkbox" wire:model="reminderEnabled" class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">{{ __('Send morning deadline reminders') }}</span>
                    <span class="block text-xs text-gray-500">{{ __('Each working morning everyone gets one Telegram message with their deadlines; overdue tasks are escalated to the department head. While this is off, nothing below is used.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3" data-ui="reminder-comment-notices">
                <input type="checkbox" wire:model="reminderCommentNotices" class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">{{ __('Send new comments personally') }}</span>
                    <span class="block text-xs text-gray-500">{{ __('The assignee, the initiator and the watchers get each new comment in Telegram during working hours. Mentions are always sent while reminders are on.') }}</span>
                </span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <x-input-label for="reminderThresholdDays" :value="__('Remind before the deadline (days)')" class="text-xs" />
                    <x-text-input wire:model="reminderThresholdDays" id="reminderThresholdDays" class="block mt-1 w-full" placeholder="3, 1, 0" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Comma-separated; 0 is the deadline day.') }}</p>
                    <x-input-error :messages="$errors->get('reminderThresholdDays')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reminderMorningTime" :value="__('Morning reminders at')" class="text-xs" />
                    <x-text-input wire:model="reminderMorningTime" id="reminderMorningTime" type="time" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderMorningTime')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reminderDigestTime" :value="__('Daily digest at')" class="text-xs" />
                    <x-text-input wire:model="reminderDigestTime" id="reminderDigestTime" type="time" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderDigestTime')" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <x-input-label for="reminderWorkStart" :value="__('Working hours from')" class="text-xs" />
                        <x-text-input wire:model="reminderWorkStart" id="reminderWorkStart" type="time" class="block mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label for="reminderWorkEnd" :value="__('to')" class="text-xs" />
                        <x-text-input wire:model="reminderWorkEnd" id="reminderWorkEnd" type="time" class="block mt-1 w-full" />
                    </div>
                    <x-input-error :messages="$errors->get('reminderWorkStart')" class="col-span-2" />
                    <x-input-error :messages="$errors->get('reminderWorkEnd')" class="col-span-2" />
                </div>
            </div>

            <div>
                <span class="block text-xs font-medium text-gray-700">{{ __('Working days') }}</span>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ([1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')] as $dayNumber => $dayLabel)
                        <label class="inline-flex items-center gap-1.5 text-xs text-gray-700">
                            <input type="checkbox" wire:model="reminderWorkDays" value="{{ $dayNumber }}" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                            {{ $dayLabel }}
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('reminderWorkDays')" class="mt-1" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <x-input-label for="reminderEscalationAfterDays" :value="__('Escalate after (days overdue)')" class="text-xs" />
                    <x-text-input wire:model="reminderEscalationAfterDays" id="reminderEscalationAfterDays" type="number" min="1" max="60" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderEscalationAfterDays')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reminderEscalationRepeatDays" :value="__('Repeat escalation every (days)')" class="text-xs" />
                    <x-text-input wire:model="reminderEscalationRepeatDays" id="reminderEscalationRepeatDays" type="number" min="1" max="60" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderEscalationRepeatDays')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reminderStaleWorkDays" :value="__('No activity for (working days)')" class="text-xs" />
                    <x-text-input wire:model="reminderStaleWorkDays" id="reminderStaleWorkDays" type="number" min="1" max="60" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderStaleWorkDays')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reminderMentionRepeatWorkDays" :value="__('Unanswered mention: remind after (working days)')" class="text-xs" />
                    <x-text-input wire:model="reminderMentionRepeatWorkDays" id="reminderMentionRepeatWorkDays" type="number" min="1" max="30" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderMentionRepeatWorkDays')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reminderMentionDigestWorkDays" :value="__('Unanswered mention: to digest after (working days)')" class="text-xs" />
                    <x-text-input wire:model="reminderMentionDigestWorkDays" id="reminderMentionDigestWorkDays" type="number" min="1" max="30" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reminderMentionDigestWorkDays')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ([
                    'reminderDigestRecipientIds' => [__('Daily digest recipients'), __('They receive the company-wide digest and escalations that have no department head to go to.')],
                    'reminderExcludedUserIds' => [__('Excluded from reminders'), __('Service accounts: no reminders, not shown as overdue people.')],
                    'reminderNoEscalationHeadIds' => [__('Heads who do not get escalations'), __('Their escalations go to the digest recipients instead.')],
                    'reminderPilotUserIds' => [__('Pilot: write only to these people'), __('While anyone is ticked here, only they get personal Telegram messages. Leave empty to write to everyone.')],
                ] as $listField => [$listTitle, $listHint])
                    <div>
                        <span class="block text-xs font-medium text-gray-700">{{ $listTitle }}</span>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $listHint }}</p>
                        <div class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 p-2 space-y-1">
                            @foreach ($users as $listUser)
                                <label class="flex items-center gap-2 text-xs text-gray-700" wire:key="{{ $listField }}-{{ $listUser->id }}">
                                    <input type="checkbox" wire:model="{{ $listField }}" value="{{ $listUser->id }}" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500">
                                    {{ $listUser->name }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get($listField)" class="mt-1" />
                    </div>
                @endforeach
            </div>
        </x-card>

        <div class="mt-4 flex items-center justify-end gap-3">
            <x-auth-session-status :status="session('reminders-status')" />
            <x-button variant="primary" type="submit">{{ __('Save reminder settings') }}</x-button>
        </div>
    </form>
</div>
