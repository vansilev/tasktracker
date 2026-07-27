<?php



use App\Enums\SystemType;

use App\Models\Department;

use App\Models\Role;

use App\Models\User;

use App\Rules\CorporateEmail;

use App\Services\UserLifecycleService;

use Illuminate\Validation\Rules;

use Livewire\Attributes\Layout;

use Livewire\Volt\Component;



new #[Layout('components.admin-layout')] class extends Component

{

    public string $search = '';

    public bool $showCreate = false;

    public string $newName = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public ?int $newDepartmentId = null;

    public string $newSystemType = 'user';

    public string $newTelegramChatId = '';

    public array $newRoleIds = [];



    public ?int $editingId = null;

    public string $editName = '';

    public ?int $departmentId = null;

    public string $systemType = 'user';

    public string $editTelegramChatId = '';

    public array $roleIds = [];



    public function with(): array

    {

        return [

            'users' => User::query()

                ->with(['department', 'roles'])

                ->when($this->search, fn ($q) => $q->where(function ($q) {

                    $q->where('name', 'like', '%'.$this->search.'%')

                        ->orWhere('email', 'like', '%'.$this->search.'%');

                }))

                ->orderBy('name')

                ->get(),

            'departments' => Department::query()->active()->orderBy('name')->get(),

            'roles' => Role::query()->where('is_active', true)->orderBy('name')->get(),

            'systemTypes' => SystemType::cases(),

        ];

    }



    public function createUser(UserLifecycleService $lifecycle): void

    {

        $this->validate([

            'newName' => 'required|string|max:255',

            'newEmail' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email', new CorporateEmail],

            'newPassword' => ['required', 'string', Rules\Password::defaults()],

            'newDepartmentId' => 'nullable|exists:departments,id',

            'newSystemType' => 'required|in:'.implode(',', array_column(SystemType::cases(), 'value')),

            'newTelegramChatId' => 'nullable|string|max:64',

            'newRoleIds' => 'array',

            'newRoleIds.*' => 'exists:roles,id',

        ]);



        $lifecycle->createUser([

            'name' => $this->newName,

            'email' => $this->newEmail,

            'password' => $this->newPassword,

            'department_id' => $this->newDepartmentId,

            'system_type' => $this->newSystemType,

            'telegram_chat_id' => $this->newTelegramChatId ?: null,

        ], $this->newRoleIds);



        $this->reset('showCreate', 'newName', 'newEmail', 'newPassword', 'newDepartmentId', 'newSystemType', 'newTelegramChatId', 'newRoleIds');

        $this->newSystemType = 'user';



        session()->flash('status', __('User created.'));

    }



    public function startEdit(int $id): void

    {

        $user = User::query()->with('roles')->findOrFail($id);

        $this->editingId = $user->id;

        $this->editName = $user->name;

        $this->departmentId = $user->department_id;

        $this->systemType = $user->system_type->value;

        $this->editTelegramChatId = $user->telegram_chat_id ?? '';

        $this->roleIds = $user->roles->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->showCreate = false;

    }



    public function saveEdit(UserLifecycleService $lifecycle): void

    {

        $user = User::query()->findOrFail($this->editingId);



        $this->validate([

            'editName' => 'required|string|max:255',

            'departmentId' => 'nullable|exists:departments,id',

            'systemType' => 'required|in:'.implode(',', array_column(SystemType::cases(), 'value')),

            'editTelegramChatId' => 'nullable|string|max:64',

            'roleIds' => 'array',

            'roleIds.*' => 'exists:roles,id',

        ]);



        $lifecycle->updateUser($user, [

            'name' => $this->editName,

            'department_id' => $this->departmentId,

            'system_type' => $this->systemType,

            'telegram_chat_id' => $this->editTelegramChatId ?: null,

        ], $this->roleIds);



        $this->reset('editingId', 'editName', 'departmentId', 'systemType', 'editTelegramChatId', 'roleIds');

    }



    public function deactivate(int $id, UserLifecycleService $service): void

    {

        try {

            $service->deactivate(User::query()->findOrFail($id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('user', collect($e->errors())->flatten()->first());
        } catch (\RuntimeException $e) {

            $this->addError('user', $e->getMessage());

        }

    }



    public function activate(int $id, UserLifecycleService $service): void

    {

        $service->activate(User::query()->findOrFail($id));

    }



    public function cancelEdit(): void

    {

        $this->reset('editingId', 'editName', 'departmentId', 'systemType', 'editTelegramChatId', 'roleIds');

    }

}; ?>



<div class="space-y-4">

    @if (session('status'))

        <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">

            {{ session('status') }}

        </div>

    @endif



    <x-card padding="p-4" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">

        <x-text-input wire:model.live.debounce.300ms="search" class="w-full md:w-96" placeholder="{{ __('Search by name or email...') }}" />

        <x-primary-button type="button" wire:click="$toggle('showCreate')">

            {{ $showCreate ? __('Cancel') : __('Add user') }}

        </x-primary-button>

    </x-card>

    @if ($showCreate)

        <x-card>

            <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Add user') }}</h3>

            <form wire:submit="createUser" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">

                <div>

                    <x-input-label :value="__('Name')" class="text-xs" />

                    <x-text-input wire:model="newName" class="w-full mt-1" />

                    <x-input-error :messages="$errors->get('newName')" class="mt-1" />

                </div>

                <div>

                    <x-input-label :value="__('Email')" />

                    <x-text-input wire:model="newEmail" type="email" class="w-full mt-1" />

                    <x-input-error :messages="$errors->get('newEmail')" class="mt-1" />

                </div>

                <div>

                    <x-input-label :value="__('Password')" class="text-xs" />

                    <x-text-input wire:model="newPassword" type="password" class="w-full mt-1" />

                    <x-input-error :messages="$errors->get('newPassword')" class="mt-1" />

                </div>

                <div>

                    <x-input-label :value="__('Department')" class="text-xs" />

                    <select wire:model="newDepartmentId" class="w-full mt-1 border-gray-300 rounded-lg shadow-sm text-sm">

                        <option value="">{{ __('— Not assigned —') }}</option>

                        @foreach ($departments as $dept)

                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>

                        @endforeach

                    </select>

                </div>

                <div>

                    <x-input-label :value="__('System type')" class="text-xs" />

                    <select wire:model="newSystemType" class="w-full mt-1 border-gray-300 rounded-lg shadow-sm text-sm">

                        @foreach ($systemTypes as $type)

                            <option value="{{ $type->value }}">{{ $type->label() }}</option>

                        @endforeach

                    </select>

                </div>

                <div>

                    <x-input-label :value="__('Telegram chat ID')" />

                    <x-text-input wire:model="newTelegramChatId" class="w-full mt-1" placeholder="{{ __('Optional') }}" />

                </div>

                <div class="md:col-span-2 lg:col-span-3">

                    <x-input-label :value="__('Access roles')" class="text-xs" />

                    <div class="mt-2 flex flex-wrap gap-3">

                        @foreach ($roles as $role)

                            <label class="inline-flex items-center gap-2 text-sm">

                                <input type="checkbox" wire:model="newRoleIds" value="{{ $role->id }}" class="rounded">

                                {{ $role->name }}

                            </label>

                        @endforeach

                    </div>

                </div>

                <div class="md:col-span-2 lg:col-span-3">

                    <x-primary-button>{{ __('Create user') }}</x-primary-button>

                </div>

            </form>

        </x-card>

    @endif



    <x-input-error :messages="$errors->get('user')" />



    <x-card padding="p-0" class="overflow-hidden">

        <div class="overflow-x-auto">

        <table class="min-w-full divide-y divide-gray-100 text-sm">

            <thead class="bg-gray-50/80">

                <tr>

                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Name') }}</th>

                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Email') }}</th>

                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Department') }}</th>

                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Type') }}</th>

                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Roles') }}</th>

                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Status') }}</th>

                    <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Actions') }}</th>

                </tr>

            </thead>

            <tbody class="divide-y divide-gray-100">

                @forelse ($users as $user)

                    @if ($editingId === $user->id)

                        <tr>

                            <td colspan="7" class="px-4 py-4">

                                <form wire:submit="saveEdit" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                                    <div>

                                        <x-input-label :value="__('Name')" />

                                        <x-text-input wire:model="editName" class="w-full mt-1" />

                                    </div>

                                    <div>

                                        <x-input-label :value="__('Email')" />

                                        <x-text-input value="{{ $user->email }}" class="w-full mt-1 bg-gray-50" disabled />

                                    </div>

                                    <div>

                                        <x-input-label :value="__('Telegram chat ID')" />

                                        <x-text-input wire:model="editTelegramChatId" class="w-full mt-1" />

                                    </div>

                                    <div>

                                        <x-input-label :value="__('Department')" />

                                        <select wire:model="departmentId" class="w-full mt-1 border-gray-300 rounded-md shadow-sm">

                                            <option value="">{{ __('— Not assigned —') }}</option>

                                            @foreach ($departments as $dept)

                                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>

                                            @endforeach

                                        </select>

                                    </div>

                                    <div>

                                        <x-input-label :value="__('System type')" />

                                        <select wire:model="systemType" class="w-full mt-1 border-gray-300 rounded-md shadow-sm">

                                            @foreach ($systemTypes as $type)

                                                <option value="{{ $type->value }}">{{ $type->label() }}</option>

                                            @endforeach

                                        </select>

                                    </div>

                                    <div class="md:col-span-2 lg:col-span-3">

                                        <x-input-label :value="__('Access roles')" class="text-xs" />

                                        <div class="mt-2 flex flex-wrap gap-3">

                                            @foreach ($roles as $role)

                                                <label class="inline-flex items-center gap-2 text-sm">

                                                    <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}" class="rounded">

                                                    {{ $role->name }}

                                                </label>

                                            @endforeach

                                        </div>

                                    </div>

                                    <div class="flex gap-2 md:col-span-2 lg:col-span-3">

                                        <x-primary-button>{{ __('Save') }}</x-primary-button>

                                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>

                                    </div>

                                </form>

                            </td>

                        </tr>

                    @else

                        <tr class="hover:bg-gray-50/80 transition-colors {{ ! $user->is_active ? 'opacity-60' : '' }}">

                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $user->name }}</td>

                            <td class="px-4 py-2.5 text-gray-600">{{ $user->email }}</td>

                            <td class="px-4 py-2.5 text-gray-700">{{ $user->department?->name ?? '—' }}</td>

                            <td class="px-4 py-2.5 text-gray-700">{{ $user->system_type->label() }}</td>

                            <td class="px-4 py-2.5 text-gray-700">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>

                            <td class="px-4 py-2.5">

                                <x-pill :color="$user->is_active ? 'green' : 'gray'">{{ $user->is_active ? __('Active') : __('Inactive') }}</x-pill>

                            </td>

                            <td class="px-4 py-2.5 text-right">

                                <div class="inline-flex flex-wrap justify-end gap-1">

                                    <x-action-button variant="ghost" size="sm" wire:click="startEdit({{ $user->id }})">{{ __('Edit') }}</x-action-button>

                                    @if ($user->is_active)

                                        <x-action-button variant="danger" size="sm" wire:click="deactivate({{ $user->id }})" wire:confirm="{{ __('Deactivate this user?') }}">{{ __('Deactivate') }}</x-action-button>

                                    @else

                                        <x-action-button variant="ghost" size="sm" wire:click="activate({{ $user->id }})">{{ __('Activate') }}</x-action-button>

                                    @endif

                                </div>

                            </td>

                        </tr>

                    @endif

                @empty

                    <tr><td colspan="7" class="px-4 py-2.5"><x-empty-state>{{ __('No data yet.') }}</x-empty-state></td></tr>

                @endforelse

            </tbody>

        </table>

        </div>

    </x-card>

</div>

