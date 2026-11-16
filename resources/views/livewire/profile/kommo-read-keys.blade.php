<?php

use App\Enums\Permission;
use App\Models\KommoReadKey;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public ?string $plainKey = null;

    public ?int $issuedKeyId = null;

    public function createKey(): void
    {
        $this->authorizeKommo();

        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $user = Auth::user();
        $limit = (int) config('tasktracker.kommo_read_key_limit');

        if ($user->kommoReadKeys()->count() >= $limit) {
            $this->addError('name', __('kommo_read.limit', ['max' => $limit]));

            return;
        }

        [$key, $plain] = KommoReadKey::issue($user, $validated['name']);
        $this->plainKey = $plain;
        $this->issuedKeyId = $key->getKey();
        $this->reset('name');
        unset($this->keys);
    }

    public function hideKey(): void
    {
        $this->plainKey = null;
        $this->issuedKeyId = null;
    }

    public function revoke(int $keyId): void
    {
        $this->authorizeKommo();

        $deleted = Auth::user()->kommoReadKeys()->whereKey($keyId)->delete();

        if ($deleted > 0 && $this->issuedKeyId === $keyId) {
            $this->hideKey();
        }

        unset($this->keys);
    }

    #[Computed]
    public function keys()
    {
        return Auth::user()
            ->kommoReadKeys()
            ->latest('id')
            ->get(['id', 'name', 'created_at', 'last_used_at']);
    }

    public function mcpUrl(): string
    {
        return (string) config('tasktracker.kommo_read_mcp_url');
    }

    public function bearerValue(): string
    {
        return 'Bearer '.($this->plainKey ?? '');
    }

    /** @return array{url: string, headers: array{Authorization: string}} */
    private function serverConfig(): array
    {
        return [
            'url' => $this->mcpUrl(),
            'headers' => ['Authorization' => $this->bearerValue()],
        ];
    }

    public function fullCursorConfig(): string
    {
        return json_encode(
            ['mcpServers' => ['kommo' => $this->serverConfig()]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function cursorServerEntry(): string
    {
        $entry = json_encode($this->serverConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '"kommo": '.$entry;
    }

    public function installLink(): string
    {
        $config = base64_encode(json_encode($this->serverConfig(), JSON_UNESCAPED_SLASHES));

        return 'cursor://anysphere.cursor-deeplink/mcp/install?name=kommo&config='.rawurlencode($config);
    }

    private function authorizeKommo(): void
    {
        abort_unless(Auth::user()?->hasPermission(Permission::KommoRead), 403);
    }
}; ?>

<section>
    <header>
        <p class="text-sm text-gray-700">
            {{ __('kommo_read.intro') }}
        </p>

        <p class="mt-1 text-xs text-gray-500">
            {{ __('kommo_read.scope_note') }}
        </p>
    </header>

    <h3 class="mt-5 text-sm font-semibold text-gray-900">
        {{ __('kommo_read.step_1_title') }}
    </h3>

    <p class="mt-1 text-xs text-gray-500">
        {{ __('kommo_read.step_1_hint') }}
    </p>

    <form wire:submit="createKey" class="mt-4 space-y-4">
        <div>
            <x-input-label for="kommo_read_key_name" :value="__('kommo_read.name')" class="text-xs text-gray-500 font-medium" />
            <x-text-input
                wire:model="name"
                id="kommo_read_key_name"
                name="name"
                type="text"
                class="mt-1 block w-full text-sm rounded-lg"
                :placeholder="__('kommo_read.name_placeholder')"
                maxlength="100"
                autocomplete="off"
            />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>

        <x-button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="createKey">
            {{ __('kommo_read.create') }}
        </x-button>
    </form>

    @if ($plainKey)
        <div wire:key="kommo-read-setup" class="mt-5 space-y-5">
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <p class="font-medium">{{ __('kommo_read.shown_once') }}</p>
                <p class="mt-1 text-xs">{{ __('kommo_read.security_warning') }}</p>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900">
                    {{ __('kommo_read.step_2_title') }}
                </h3>

                <x-button variant="primary" :href="$this->installLink()" class="mt-3">
                    {{ __('kommo_read.install') }}
                </x-button>
                <p class="mt-2 text-xs text-gray-500">{{ __('kommo_read.install_hint') }}</p>

                <details class="mt-4 rounded-lg border border-gray-200 p-4 text-sm text-gray-700">
                    <summary class="cursor-pointer font-medium text-primary-600">
                        {{ __('kommo_read.manual_title') }}
                    </summary>

                    <div class="mt-3 space-y-4">
                        <details class="text-xs text-gray-600">
                            <summary class="cursor-pointer font-medium text-primary-600">
                                {{ __('api_token.where_file') }}
                            </summary>
                            <div class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3">
                                <p><strong>Mac:</strong> {{ __('api_token.path_mac') }}</p>
                                <p><strong>Windows:</strong> {{ __('api_token.path_windows') }}</p>
                                <p><strong>Linux:</strong> {{ __('api_token.path_linux') }}</p>
                            </div>
                        </details>

                        <div>
                            <p>{{ __('kommo_read.manual_new_file') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-800">{{ $this->fullCursorConfig() }}</pre>
                            <div class="mt-2" x-data="{ copied: false, text: @js($this->fullCursorConfig()) }">
                                <x-button size="sm"
                                    variant="secondary"
                                    type="button"
                                    x-on:click="navigator.clipboard.writeText(text); copied = true"
                                >
                                    <span x-show="!copied">{{ __('kommo_read.copy_full_file') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
                                </x-button>
                            </div>
                        </div>

                        <div>
                            <p>{{ __('kommo_read.manual_existing_file') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-800">{{ $this->cursorServerEntry() }}</pre>
                            <div class="mt-2" x-data="{ copied: false, text: @js($this->cursorServerEntry()) }">
                                <x-button size="sm"
                                    variant="secondary"
                                    type="button"
                                    x-on:click="navigator.clipboard.writeText(text); copied = true"
                                >
                                    <span x-show="!copied">{{ __('kommo_read.copy_server') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
                                </x-button>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="mt-3 rounded-lg border border-gray-200 p-4 text-sm text-gray-700">
                    <summary class="cursor-pointer font-medium text-primary-600">
                        {{ __('kommo_read.cloud_title') }}
                    </summary>

                    <p class="mt-3">{{ __('kommo_read.cloud_steps') }}</p>

                    <div class="mt-3 space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ __('api_token.cloud_name_label') }}</p>
                            <code class="mt-1 block break-all rounded bg-surface px-2 py-1.5">kommo</code>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ __('api_token.cloud_url_label') }}</p>
                            <code class="mt-1 block break-all rounded bg-surface px-2 py-1.5">{{ $this->mcpUrl() }}</code>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ __('api_token.cloud_header_name_label') }}</p>
                            <code class="mt-1 block break-all rounded bg-surface px-2 py-1.5">Authorization</code>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ __('api_token.cloud_header_value_label') }}</p>
                            <code class="mt-1 block break-all rounded bg-surface px-2 py-1.5">{{ $this->bearerValue() }}</code>
                            <div class="mt-2" x-data="{ copied: false, text: @js($this->bearerValue()) }">
                                <x-button size="sm"
                                    variant="secondary"
                                    type="button"
                                    x-on:click="navigator.clipboard.writeText(text); copied = true"
                                >
                                    <span x-show="!copied">{{ __('api_token.copy_header_value') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('api_token.copied') }}</span>
                                </x-button>
                            </div>
                        </div>
                    </div>
                </details>
            </div>

            <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                <h3 class="text-sm font-semibold text-green-900">
                    {{ __('kommo_read.step_3_title') }}
                </h3>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-green-900">
                    <li>{{ __('kommo_read.check_green') }}</li>
                    <li>{{ __('kommo_read.test_prompt') }}</li>
                </ol>
            </div>

            <button type="button" wire:click="hideKey" class="text-sm text-primary-600 hover:text-primary-800">
                {{ __('api_token.hide') }}
            </button>
        </div>
    @endif

    <div class="mt-6 border-t border-gray-100 pt-4">
        <h3 class="text-sm font-semibold text-gray-900">{{ __('kommo_read.existing_title') }}</h3>

        @if ($this->keys->isEmpty())
            <p class="mt-2 text-sm text-gray-600">{{ __('kommo_read.empty') }}</p>
        @else
            <p class="mt-1 text-xs text-gray-500">{{ __('kommo_read.lost_key') }}</p>
            <ul class="mt-2 divide-y divide-gray-100">
                @foreach ($this->keys as $key)
                    <li wire:key="kommo-read-key-{{ $key->id }}" class="flex items-start justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-900 break-words">{{ $key->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ __('api_token.created') }}
                                {{ $key->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                                ·
                                {{ __('api_token.last_used') }}
                                @if ($key->last_used_at)
                                    {{ $key->last_used_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                                @else
                                    {{ __('api_token.never_used') }}
                                @endif
                            </p>
                        </div>

                        <x-button size="sm"
                            variant="danger"
                            type="button"
                            wire:click="revoke({{ $key->id }})"
                            wire:confirm="{{ __('kommo_read.revoke_confirm') }}"
                        >
                            {{ __('api_token.revoke') }}
                        </x-button>
                    </li>
                @endforeach
            </ul>

            @if ($this->keys->count() >= (int) config('tasktracker.kommo_read_key_limit'))
                <p class="mt-2 text-xs text-gray-500">
                    {{ __('kommo_read.limit', ['max' => (int) config('tasktracker.kommo_read_key_limit')]) }}
                </p>
            @endif
        @endif
    </div>
</section>
