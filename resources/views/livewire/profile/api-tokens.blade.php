<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public ?string $plainToken = null;

    public ?int $issuedTokenId = null;

    public function createToken(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $user = Auth::user();
        $limit = (int) config('tasktracker.api_token_limit');

        if ($user->tokens()->count() >= $limit) {
            $this->addError('name', __('api_token.limit', ['max' => $limit]));

            return;
        }

        $issued = $user->createToken($validated['name']);
        $this->plainToken = $issued->plainTextToken;
        $this->issuedTokenId = $issued->accessToken->getKey();
        $this->reset('name');
        unset($this->accessTokens);
    }

    public function hideToken(): void
    {
        $this->plainToken = null;
        $this->issuedTokenId = null;
    }

    public function revoke(int $tokenId): void
    {
        $deleted = Auth::user()->tokens()->whereKey($tokenId)->delete();

        if ($deleted > 0 && $this->issuedTokenId === $tokenId) {
            $this->hideToken();
        }

        unset($this->accessTokens);
    }

    #[Computed]
    public function accessTokens()
    {
        return Auth::user()
            ->tokens()
            ->latest('id')
            ->get(['id', 'name', 'created_at', 'last_used_at']);
    }

    public function cursorSnippet(): string
    {
        $secret = $this->plainToken ?: __('api_token.token_placeholder');

        return <<<JSON
{
  "mcpServers": {
    "task-avant": {
      "url": "https://task.avant.od.ua/mcp",
      "headers": {
        "Authorization": "Bearer {$secret}"
      }
    }
  }
}
JSON;
    }
}; ?>

<section>
    <div>
        <h2 class="text-sm font-semibold text-gray-900">
            {{ __('api_token.guide_title') }}
        </h2>

        <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700">
            <li>{{ __('api_token.guide_step_1') }}</li>
            <li>{{ __('api_token.guide_step_2') }}</li>
            <li>{{ __('api_token.guide_step_3') }}</li>
            <li>{{ __('api_token.guide_step_4', ['placeholder' => __('api_token.token_placeholder')]) }}</li>
            <li>{{ __('api_token.guide_step_5') }}</li>
        </ol>

        <p class="mt-2 text-xs text-gray-500">
            {{ __('api_token.guide_existing') }}
        </p>

        @if ($plainToken)
            <p class="mt-2 text-sm text-gray-700">{{ __('api_token.guide_ready') }}</p>
        @endif

        <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-800">{{ $this->cursorSnippet() }}</pre>

        <div class="mt-2" x-data="{ copied: false }">
            <x-action-button
                variant="secondary"
                type="button"
                x-on:click="navigator.clipboard.writeText(@js($this->cursorSnippet())); copied = true"
            >
                <span x-show="!copied">{{ __('api_token.copy_block') }}</span>
                <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
            </x-action-button>
        </div>
    </div>

    <header class="mt-6">
        <h2 class="text-sm font-semibold text-gray-900">
            {{ __('api_token.title') }}
        </h2>

        <p class="mt-1 text-xs text-gray-500">
            {{ __('api_token.description') }}
        </p>
    </header>

    <form wire:submit="createToken" class="mt-4 space-y-4">
        <div>
            <x-input-label for="api_token_name" :value="__('api_token.name')" class="text-xs text-gray-500 font-medium" />
            <x-text-input
                wire:model="name"
                id="api_token_name"
                name="name"
                type="text"
                class="mt-1 block w-full text-sm rounded-lg"
                :placeholder="__('api_token.name_placeholder')"
                maxlength="100"
                autocomplete="off"
            />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>

        <x-primary-button wire:loading.attr="disabled" wire:target="createToken">
            {{ __('api_token.create') }}
        </x-primary-button>
    </form>

    @if ($plainToken)
        <div wire:key="api-token-secret" class="mt-4 rounded-md bg-gray-50 p-3 text-sm text-gray-700 space-y-2">
            <p>{{ __('api_token.shown_once') }}</p>
            <p class="text-xs text-gray-500">{{ __('api_token.setup', ['url' => url('/mcp')]) }}</p>

            <x-text-input
                type="text"
                readonly
                :value="$plainToken"
                class="block w-full text-sm rounded-lg font-mono"
                onclick="this.select()"
                autocomplete="off"
                spellcheck="false"
            />

            <div class="flex flex-wrap items-center gap-3" x-data="{ copied: false }">
                <x-action-button
                    variant="secondary"
                    type="button"
                    x-on:click="navigator.clipboard.writeText(@js($plainToken)); copied = true"
                >
                    <span x-show="!copied">{{ __('api_token.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
                </x-action-button>

                <button type="button" wire:click="hideToken" class="text-sm text-indigo-600 hover:text-indigo-800">
                    {{ __('api_token.hide') }}
                </button>
            </div>
        </div>
    @endif

    <div class="mt-4">
        @if ($this->accessTokens->isEmpty())
            <p class="text-sm text-gray-600">{{ __('api_token.empty') }}</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($this->accessTokens as $token)
                    <li wire:key="api-token-{{ $token->id }}" class="flex items-start justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-900 break-words">{{ $token->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ __('api_token.created') }}
                                {{ $token->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                                ·
                                {{ __('api_token.last_used') }}
                                @if ($token->last_used_at)
                                    {{ $token->last_used_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                                @else
                                    {{ __('api_token.never_used') }}
                                @endif
                            </p>
                        </div>

                        <x-action-button
                            variant="danger"
                            type="button"
                            wire:click="revoke({{ $token->id }})"
                            wire:confirm="{{ __('api_token.revoke_confirm') }}"
                        >
                            {{ __('api_token.revoke') }}
                        </x-action-button>
                    </li>
                @endforeach
            </ul>

            @if ($this->accessTokens->count() >= (int) config('tasktracker.api_token_limit'))
                <p class="mt-2 text-xs text-gray-500">
                    {{ __('api_token.limit', ['max' => (int) config('tasktracker.api_token_limit')]) }}
                </p>
            @endif
        @endif
    </div>
</section>
