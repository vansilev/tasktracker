@php
    $adminSections = [
        'admin.index' => __('Overview'),
        'admin.departments' => __('Departments'),
        'admin.users' => __('Users'),
        'admin.roles' => __('Access roles'),
        'admin.categories' => __('Categories'),
        'admin.settings' => __('System settings'),
        'admin.import' => __('Excel import'),
        'admin.audit' => __('Audit log'),
        'admin.deleted-tasks' => __('Deleted tasks'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Administration')" class="pb-2" />

        <nav class="flex items-center gap-1 pb-3 overflow-x-auto" aria-label="{{ __('Administration') }}">
            @foreach ($adminSections as $route => $label)
                @php $isActive = request()->routeIs($route) || request()->routeIs($route.'.*'); @endphp
                <a href="{{ route($route) }}"
                   wire:navigate
                   @if ($isActive) aria-current="page" @endif
                   class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $isActive ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </div>
</x-app-layout>
