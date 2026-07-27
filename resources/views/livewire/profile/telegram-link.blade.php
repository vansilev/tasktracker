<?php

use App\Services\TelegramLinkService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public ?string $linkCode = null;

    public ?string $deepLinkUrl = null;

    public bool $linked = false;

    public function mount(TelegramLinkService $linkService): void
    {
        $user = Auth::user();
        $this->linked = $user->hasTelegramLinked();
    }

    public function generateLink(TelegramLinkService $linkService): void
    {
        $code = $linkService->createLinkCode(Auth::user());
        $this->linkCode = $code->code;
        $this->deepLinkUrl = $linkService->deepLinkUrl($code->code);
        $this->linked = Auth::user()->fresh()->hasTelegramLinked();
        $this->dispatch('telegram-link-code-generated');
    }

    public function unlink(TelegramLinkService $linkService): void
    {
        $linkService->unlink(Auth::user());
        $this->linkCode = null;
        $this->deepLinkUrl = null;
        $this->linked = false;
        $this->dispatch('telegram-unlinked');
    }

    public function refreshStatus(): void
    {
        $this->linked = Auth::user()->fresh()->hasTelegramLinked();

        if ($this->linked) {
            $this->linkCode = null;
            $this->deepLinkUrl = null;
        }
    }
}; ?>

<section>
    <header>
        <h2 class="text-sm font-semibold text-gray-900">
            {{ __('notification.telegram_link_title') }}
        </h2>

        <p class="mt-1 text-xs text-gray-500">
            {{ __('notification.telegram_link_description') }}
        </p>
    </header>

    <div class="mt-4 space-y-3">
        @if ($linked)
            <p class="text-sm text-green-700">
                {{ __('notification.telegram_linked') }}
            </p>

            <div class="flex items-center gap-3">
                <x-danger-button wire:click="unlink" wire:confirm="{{ __('notification.telegram_unlink_confirm') }}">
                    {{ __('notification.telegram_unlink') }}
                </x-danger-button>

                <x-action-message class="me-3" on="telegram-unlinked">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        @else
            <p class="text-sm text-gray-600">
                {{ __('notification.telegram_not_linked') }}
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <x-primary-button type="button" wire:click="generateLink">
                    {{ __('notification.telegram_generate_link') }}
                </x-primary-button>

                @if ($linkCode)
                    <button
                        type="button"
                        wire:click="refreshStatus"
                        class="text-sm text-indigo-600 hover:text-indigo-800"
                    >
                        {{ __('notification.telegram_check_link') }}
                    </button>
                @endif
            </div>

            @if ($linkCode)
                <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-700 space-y-2">
                    @if ($deepLinkUrl)
                        <p>
                            <a href="{{ $deepLinkUrl }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                {{ __('notification.telegram_open_bot') }}
                            </a>
                        </p>
                    @endif

                    <p>{{ __('notification.telegram_or_send_code') }}</p>
                    <code class="block break-all rounded bg-white px-2 py-1 border border-gray-200 text-xs">/start {{ $linkCode }}</code>
                    <p class="text-xs text-gray-500">{{ __('notification.telegram_code_ttl') }}</p>
                </div>
            @endif
        @endif
    </div>
</section>
