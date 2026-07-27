<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    public function with(): array
    {
        return [
            'stats' => [
                'users' => User::query()->count(),
                'active_users' => User::query()->where('is_active', true)->count(),
                'departments' => Department::query()->where('is_active', true)->count(),
                'roles' => Role::query()->where('is_active', true)->count(),
                'categories' => Category::query()->where('is_active', true)->count(),
            ],
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        @php
            $icons = [
                'indigo' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z"/>',
                'green' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
                'blue' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m4-14h6m-6 4h6m-2 4h2"/>',
                'purple' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
                'amber' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z"/>',
            ];
        @endphp
        @foreach ([
            ['label' => __('Users'), 'value' => $stats['users'], 'color' => 'indigo'],
            ['label' => __('Active'), 'value' => $stats['active_users'], 'color' => 'green'],
            ['label' => __('Departments'), 'value' => $stats['departments'], 'color' => 'blue'],
            ['label' => __('Access roles'), 'value' => $stats['roles'], 'color' => 'purple'],
            ['label' => __('Categories'), 'value' => $stats['categories'], 'color' => 'amber'],
        ] as $stat)
            @php
                $chip = match ($stat['color']) {
                    'green' => 'bg-green-100 text-green-600',
                    'blue' => 'bg-blue-100 text-blue-600',
                    'purple' => 'bg-purple-100 text-purple-600',
                    'amber' => 'bg-amber-100 text-amber-600',
                    default => 'bg-indigo-100 text-indigo-600',
                };
            @endphp
            <x-card padding="p-4">
                <div class="flex items-center gap-2.5">
                    <span class="shrink-0 w-8 h-8 rounded-lg flex items-center justify-center {{ $chip }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$stat['color']] !!}</svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 truncate">{{ $stat['label'] }}</p>
                        <p class="text-xl font-bold text-gray-900">{{ $stat['value'] }}</p>
                    </div>
                </div>
            </x-card>
        @endforeach
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @foreach ([
            ['route' => 'admin.departments', 'label' => __('Departments'), 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m4-14h6m-6 4h6m-2 4h2', 'color' => 'bg-blue-100 text-blue-600'],
            ['route' => 'admin.users', 'label' => __('Users'), 'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z', 'color' => 'bg-indigo-100 text-indigo-600'],
            ['route' => 'admin.roles', 'label' => __('Access roles'), 'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', 'color' => 'bg-purple-100 text-purple-600'],
            ['route' => 'admin.categories', 'label' => __('Categories'), 'icon' => 'M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z', 'color' => 'bg-amber-100 text-amber-600'],
            ['route' => 'admin.settings', 'label' => __('System settings'), 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'color' => 'bg-gray-100 text-gray-600'],
            ['route' => 'admin.import', 'label' => __('Excel import'), 'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12', 'color' => 'bg-green-100 text-green-600'],
            ['route' => 'admin.audit', 'label' => __('Audit log'), 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'color' => 'bg-rose-100 text-rose-600'],
            ['route' => 'admin.deleted-tasks', 'label' => __('Deleted tasks'), 'icon' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16', 'color' => 'bg-orange-100 text-orange-600'],
        ] as $section)
            <a href="{{ route($section['route']) }}" wire:navigate class="block group">
                <x-card padding="p-4" class="h-full group-hover:border-indigo-200 transition-colors">
                    <div class="flex items-start gap-3">
                        <span class="shrink-0 w-8 h-8 rounded-lg flex items-center justify-center {{ $section['color'] }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $section['icon'] }}"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900 group-hover:text-indigo-700">{{ $section['label'] }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">{{ __('Phase 1: manage departments, users, access roles and categories.') }}</p>
                        </div>
                    </div>
                </x-card>
            </a>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">{{ __('Phase 1: manage departments, users, access roles and categories.') }}</p>
</div>
