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

    public function fullCursorConfig(): string
    {
        $secret = $this->plainToken ?? '';

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

    public function cursorServerEntry(): string
    {
        $secret = $this->plainToken ?? '';

        return <<<JSON
"task-avant": {
  "url": "https://task.avant.od.ua/mcp",
  "headers": {
    "Authorization": "Bearer {$secret}"
  }
}
JSON;
    }
}; ?>

<section>
    <header>
        <h2 class="text-sm font-semibold text-gray-900">
            {{ __('api_token.guide_title') }}
        </h2>

        <p class="mt-1 text-sm text-gray-700">
            {{ __('api_token.guide_intro') }}
        </p>

        <p class="mt-1 text-xs text-gray-500">
            {{ __('api_token.guide_mcp') }}
        </p>
    </header>

    <h3 class="mt-5 text-sm font-semibold text-gray-900">
        {{ __('api_token.step_1_title') }}
    </h3>

    <p class="mt-1 text-xs text-gray-500">
        {{ __('api_token.description') }}
    </p>

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
        <div wire:key="api-token-setup" class="mt-5 space-y-5">
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <p class="font-medium">{{ __('api_token.shown_once') }}</p>
                <p class="mt-1 text-xs">{{ __('api_token.security_warning') }}</p>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900">
                    {{ __('api_token.step_2_title') }}
                </h3>
                <p class="mt-1 text-sm text-gray-700">{{ __('api_token.step_2_intro') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ __('api_token.not_sure') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-gray-200 p-4">
                    <h4 class="text-sm font-semibold text-gray-900">
                        {{ __('api_token.new_file_title') }}
                    </h4>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700">
                        <li>{{ __('api_token.open_file') }}</li>
                        <li>{{ __('api_token.new_file_step') }}</li>
                        <li>{{ __('api_token.save_file') }}</li>
                    </ol>

                    <details class="mt-3 text-xs text-gray-600">
                        <summary class="cursor-pointer font-medium text-indigo-600">
                            {{ __('api_token.where_file') }}
                        </summary>
                        <div class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3">
                            <p><strong>Mac:</strong> {{ __('api_token.path_mac') }}</p>
                            <p><strong>Windows:</strong> {{ __('api_token.path_windows') }}</p>
                            <p><strong>Linux:</strong> {{ __('api_token.path_linux') }}</p>
                        </div>
                    </details>

                    <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-800">{{ $this->fullCursorConfig() }}</pre>

                    <div class="mt-2" x-data="{ copied: false }">
                        <x-action-button
                            variant="secondary"
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($this->fullCursorConfig())); copied = true"
                        >
                            <span x-show="!copied">{{ __('api_token.copy_full_file') }}</span>
                            <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
                        </x-action-button>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 p-4">
                    <h4 class="text-sm font-semibold text-gray-900">
                        {{ __('api_token.existing_file_title') }}
                    </h4>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700">
                        <li>{{ __('api_token.open_file') }}</li>
                        <li>{{ __('api_token.existing_file_step_1') }}</li>
                        <li>{{ __('api_token.existing_file_step_2') }}</li>
                        <li>{{ __('api_token.save_file') }}</li>
                    </ol>

                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800">
                        {{ __('api_token.existing_warning') }}
                    </div>

                    <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-800">{{ $this->cursorServerEntry() }}</pre>

                    <div class="mt-2" x-data="{ copied: false }">
                        <x-action-button
                            variant="secondary"
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($this->cursorServerEntry())); copied = true"
                        >
                            <span x-show="!copied">{{ __('api_token.copy_server') }}</span>
                            <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
                        </x-action-button>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                <h3 class="text-sm font-semibold text-green-900">
                    {{ __('api_token.step_3_title') }}
                </h3>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-green-900">
                    <li>{{ __('api_token.restart_cursor') }}</li>
                    <li>{{ __('api_token.open_mcp_list') }}</li>
                    <li>{{ __('api_token.green_dot') }}</li>
                    <li>{{ __('api_token.test_prompt') }}</li>
                </ol>
            </div>

            <button type="button" wire:click="hideToken" class="text-sm text-indigo-600 hover:text-indigo-800">
                {{ __('api_token.hide') }}
            </button>
        </div>
    @endif

    <div class="mt-6 border-t border-gray-100 pt-4">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('api_token.existing_tokens_title') }}</h3>

        @if ($this->accessTokens->isEmpty())
            <p class="mt-2 text-sm text-gray-600">{{ __('api_token.empty') }}</p>
        @else
            <p class="mt-1 text-xs text-gray-500">{{ __('api_token.lost_token') }}</p>
            <ul class="mt-2 divide-y divide-gray-100">
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
