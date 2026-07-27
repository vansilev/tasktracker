<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Services\AuditLogService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    public Role $role;
    public string $name = '';
    public string $description = '';
    public array $permissions = [];
    public array $visibleDepartmentIds = [];

    public function mount(Role $role): void
    {
        $this->role = $role;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $this->permissions = $role->permissionList();
        $this->visibleDepartmentIds = $role->visibleDepartments()->pluck('departments.id')->map(fn ($id) => (string) $id)->all();
    }

    public function with(): array
    {
        return [
            'allPermissions' => Permission::cases(),
            'departments' => Department::query()->active()->orderBy('name')->get(),
        ];
    }

    public function save(AuditLogService $audit): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:roles,name,'.$this->role->id,
            'description' => 'nullable|string|max:500',
            'permissions' => 'array',
            'visibleDepartmentIds' => 'array',
        ]);

        $oldValues = [
            'name' => $this->role->name,
            'description' => $this->role->description,
            'permissions' => $this->role->permissionList(),
            'visible_department_ids' => $this->role->visibleDepartments()->pluck('departments.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];

        $this->role->update([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        $this->role->syncPermissions($this->permissions);
        $this->role->syncVisibleDepartments($this->visibleDepartmentIds);

        $this->role->refresh();

        $newValues = [
            'name' => $this->role->name,
            'description' => $this->role->description,
            'permissions' => $this->role->permissionList(),
            'visible_department_ids' => $this->role->visibleDepartments()->pluck('departments.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];

        $changedOld = [];
        $changedNew = [];
        foreach ($newValues as $key => $value) {
            if ($oldValues[$key] !== $value) {
                $changedOld[$key] = $oldValues[$key];
                $changedNew[$key] = $value;
            }
        }

        if ($changedOld !== [] || $changedNew !== []) {
            $audit->log('role.updated', auth()->user(), $this->role, $changedOld, $changedNew);
        }

        session()->flash('status', __('Role saved.'));
    }
}; ?>

<div class="space-y-4">
    @if (session('status'))
        <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    <nav class="flex items-center gap-1.5 text-xs text-gray-500" aria-label="{{ __('Access roles') }}">
        <a href="{{ route('admin.roles') }}" class="hover:text-gray-700" wire:navigate>{{ __('Access roles') }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-gray-700">{{ $role->name }}</span>
    </nav>

    <x-card>
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <x-input-label :value="__('Name')" class="text-xs" />
                    <x-text-input wire:model="name" class="w-full mt-1" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label :value="__('Description')" class="text-xs" />
                    <x-text-input wire:model="description" class="w-full mt-1" />
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-2">{{ __('Permissions') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    @foreach ($allPermissions as $permission)
                        <label class="inline-flex items-center gap-2 text-sm p-2 rounded-lg hover:bg-gray-50">
                            <input type="checkbox" wire:model="permissions" value="{{ $permission->value }}" class="rounded">
                            <span>{{ $permission->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900">{{ __('Visible departments') }}</h3>
                <p class="text-xs text-gray-500 mt-0.5 mb-2">{{ __('Users with this role can see tasks from these departments (in addition to own tasks).') }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                    @foreach ($departments as $department)
                        <label class="inline-flex items-center gap-2 text-sm p-2 rounded-lg hover:bg-gray-50">
                            <input type="checkbox" wire:model="visibleDepartmentIds" value="{{ $department->id }}" class="rounded">
                            {{ $department->name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <x-primary-button>{{ __('Save') }}</x-primary-button>
                <a href="{{ route('admin.roles') }}" wire:navigate>
                    <x-secondary-button type="button">{{ __('Back') }}</x-secondary-button>
                </a>
            </div>
        </form>
    </x-card>
</div>
