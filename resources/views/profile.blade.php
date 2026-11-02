<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Profile')" />
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                <div class="space-y-4">
                    <x-card>
                        <livewire:profile.update-profile-information-form />
                    </x-card>

                    <x-card>
                        <livewire:profile.update-password-form />
                    </x-card>

                    <x-card>
                        <livewire:profile.telegram-link />
                    </x-card>
                </div>

                <x-card>
                    <livewire:profile.notification-preferences />
                </x-card>
            </div>

            <x-card>
                <h2 class="text-sm font-semibold text-gray-900">
                    {{ __('api_token.guide_title') }}
                </h2>

                @if (auth()->user()->hasPermission(\App\Enums\Permission::KommoRead))
                    <div x-data="{ tab: location.hash === '#kommo' ? 'kommo' : 'tracker' }" class="mt-3">
                        <nav role="tablist" aria-label="{{ __('api_token.guide_title') }}" class="flex items-center gap-1 border-b border-gray-100 pb-3">
                            @foreach (['tracker' => 'Task Tracker', 'kommo' => 'Kommo'] as $key => $label)
                                <button
                                    type="button"
                                    role="tab"
                                    id="cursor-tab-{{ $key }}"
                                    aria-controls="cursor-panel-{{ $key }}"
                                    x-bind:aria-selected="tab === '{{ $key }}'"
                                    x-on:click="tab = '{{ $key }}'; history.replaceState(null, '', '#{{ $key }}')"
                                    x-bind:class="tab === '{{ $key }}' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'"
                                    class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </nav>

                        <div id="cursor-panel-tracker" role="tabpanel" aria-labelledby="cursor-tab-tracker" x-show="tab === 'tracker'" class="mt-4">
                            <livewire:profile.api-tokens />
                        </div>

                        <div id="cursor-panel-kommo" role="tabpanel" aria-labelledby="cursor-tab-kommo" aria-label="{{ __('kommo_read.title') }}" x-show="tab === 'kommo'" x-cloak class="mt-4">
                            <livewire:profile.kommo-read-keys />
                        </div>
                    </div>
                @else
                    <div class="mt-1">
                        <livewire:profile.api-tokens />
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
