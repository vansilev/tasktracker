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
                <livewire:profile.api-tokens />
            </x-card>

            @if (auth()->user()->hasPermission(\App\Enums\Permission::KommoRead))
                <x-card>
                    <livewire:profile.kommo-read-keys />
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
