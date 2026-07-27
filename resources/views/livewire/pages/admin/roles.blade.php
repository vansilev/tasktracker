<?php

use App\Models\Role;
use App\Services\AuditLogService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    public string $name = '';
    public string $description = '';

    public function with(): array
    {
        return [
            'roles' => Role::query()
                ->withCount(['users', 'permissions', 'visibleDepartments'])
                ->orderBy('name')
                ->get(),
        ];
    }

    public function create(AuditLogService $audit): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string|max:500',
        ]);

        $role = Role::query()->create([
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => true,
        ]);

        $audit->log('role.created', auth()->user(), $role, null, ['name' => $role->name]);

        $this->reset('name', 'description');

        $this->redirect(route('admin.roles.edit', $role), navigate: true);
    }

    public function archive(int $id, AuditLogService $audit): void
    {
        $role = Role::query()->findOrFail($id);
        $role->update(['is_active' => false]);

        $audit->log('role.archived', auth()->user(), $role, ['is_active' => true], ['is_active' => false]);
    }

    public function restore(int $id, AuditLogService $audit): void
    {
        $role = Role::query()->findOrFail($id);
        $role->update(['is_active' => true]);

        $audit->log('role.restored', auth()->user(), $role, ['is_active' => false], ['is_active' => true]);
    }
}; ?>

<div class="space-y-4">
    <x-card>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Add access role') }}</h3>
        <form wire:submit="create" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
            <div>
                <x-input-label :value="__('Name')" class="text-xs" />
                <x-text-input wire:model="name" class="w-full mt-1" />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
            <div>
                <x-input-label :value="__('Description')" class="text-xs" />
                <x-text-input wire:model="description" class="w-full mt-1" />
            </div>
            <x-primary-button>{{ __('Create and configure') }}</x-primary-button>
        </form>
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50/80">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Name') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Users') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Permissions') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Visible depts') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Status') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($roles as $role)
                        <tr class="hover:bg-gray-50/80 transition-colors {{ ! $role->is_active ? 'opacity-60' : '' }}">
                            <td class="px-4 py-2.5">
                                <div class="font-medium text-gray-900">{{ $role->name }}</div>
                                @if ($role->description)
                                    <div class="text-gray-500 text-xs mt-0.5">{{ $role->description }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $role->users_count }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $role->permissions_count }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $role->visible_departments_count }}</td>
                            <td class="px-4 py-2.5">
                                <x-pill :color="$role->is_active ? 'green' : 'gray'">{{ $role->is_active ? __('Active') : __('Archived') }}</x-pill>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="inline-flex flex-wrap justify-end gap-1">
                                    <a href="{{ route('admin.roles.edit', $role) }}" wire:navigate>
                                        <x-action-button variant="ghost" size="sm" type="button">{{ __('Configure') }}</x-action-button>
                                    </a>
                                    @if ($role->is_active)
                                        <x-action-button variant="danger" size="sm" wire:click="archive({{ $role->id }})">{{ __('Archive') }}</x-action-button>
                                    @else
                                        <x-action-button variant="ghost" size="sm" wire:click="restore({{ $role->id }})">{{ __('Restore') }}</x-action-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-2.5">
                                <x-empty-state>{{ __('No data yet.') }}</x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
