<?php

use App\Services\AuditLogService;
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

    /** @var list<string> */
    private const SETTING_KEYS = [
        'google_sso_enabled',
        'password_login_enabled',
        'allowed_email_domains',
        'sla_review_days',
        'attachment_max_kb',
    ];

    public function mount(SettingsService $settings): void
    {
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
                            <input type="checkbox" wire:model="googleSsoEnabled" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        </label>
                        <x-input-error :messages="$errors->get('googleSsoEnabled')" />

                        <label class="flex items-center justify-between gap-3 py-1">
                            <span class="text-xs text-gray-700">{{ __('Password login') }}</span>
                            <input type="checkbox" wire:model="passwordLoginEnabled" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
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
            <x-primary-button>{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</div>
