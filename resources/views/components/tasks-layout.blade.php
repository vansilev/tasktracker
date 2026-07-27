<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__($title ?? 'Tasks')">
            @isset($headerActions)
                <x-slot name="actions">
                    {{ $headerActions }}
                </x-slot>
            @endisset
        </x-page-header>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </div>
</x-app-layout>
