<?php

use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UserLifecycleService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    public string $name = '';
    public ?int $editingId = null;
    public string $editName = '';
    public ?int $headUserId = null;
    public bool $autoAssign = false;

    /** @var list<int> ordered queue user ids */
    public array $queueIds = [];

    public function with(): array
    {
        return [
            'departments' => Department::query()
                ->with('head')
                ->withCount('users')
                ->orderBy('name')
                ->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'editingDepartmentUsers' => $this->editingId
                ? User::query()->where('is_active', true)
                    ->where('department_id', $this->editingId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
        ];
    }

    public function create(AuditLogService $audit): void
    {
        $this->validate(['name' => 'required|string|max:255|unique:departments,name']);

        $dept = Department::query()->create([
            'name' => $this->name,
            'is_active' => true,
        ]);

        $audit->log('department.created', auth()->user(), $dept, null, [
            'name' => $dept->name,
            'head_user_id' => null,
            'auto_assign_enabled' => false,
            'queue_user_ids' => [],
        ]);

        $this->reset('name');
        $this->dispatch('department-saved');
    }

    public function startEdit(int $id): void
    {
        $dept = Department::query()->with('assignQueue')->findOrFail($id);
        $this->editingId = $dept->id;
        $this->editName = $dept->name;
        $this->headUserId = $dept->head_user_id;
        $this->autoAssign = $dept->auto_assign_enabled;
        $this->queueIds = $dept->assignQueue->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function addToQueue(int $userId): void
    {
        if (! in_array($userId, $this->queueIds, true)) {
            $this->queueIds[] = $userId;
        }
    }

    public function removeFromQueue(int $userId): void
    {
        $this->queueIds = array_values(array_filter($this->queueIds, fn ($id) => $id !== $userId));
    }

    public function moveQueue(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (! isset($this->queueIds[$index], $this->queueIds[$target])) {
            return;
        }

        [$this->queueIds[$index], $this->queueIds[$target]] = [$this->queueIds[$target], $this->queueIds[$index]];
        $this->queueIds = array_values($this->queueIds);
    }

    public function saveEdit(UserLifecycleService $lifecycle, AuditLogService $audit): void
    {
        $dept = Department::query()->with('assignQueue')->findOrFail($this->editingId);

        $this->validate([
            'editName' => 'required|string|max:255|unique:departments,name,'.$dept->id,
            'headUserId' => 'nullable|exists:users,id',
        ]);

        $oldValues = [
            'name' => $dept->name,
            'head_user_id' => $dept->head_user_id,
            'auto_assign_enabled' => $dept->auto_assign_enabled,
            'queue_user_ids' => $dept->assignQueue->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];

        $dept->update([
            'name' => $this->editName,
            'auto_assign_enabled' => $this->autoAssign,
        ]);

        $lifecycle->syncDepartmentHead($dept, $this->headUserId);

        $sync = [];
        foreach (array_values($this->queueIds) as $i => $userId) {
            $sync[$userId] = ['sort_order' => $i];
        }
        $dept->assignQueue()->sync($sync);

        $dept->refresh();
        $dept->load('assignQueue');

        $newValues = [
            'name' => $dept->name,
            'head_user_id' => $dept->head_user_id,
            'auto_assign_enabled' => $dept->auto_assign_enabled,
            'queue_user_ids' => $dept->assignQueue->pluck('id')->map(fn ($id) => (int) $id)->all(),
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
            $audit->log('department.updated', auth()->user(), $dept, $changedOld, $changedNew);
        }

        $this->reset('editingId', 'editName', 'headUserId', 'autoAssign', 'queueIds');
    }

    public function archive(int $id, AuditLogService $audit): void
    {
        try {
            $dept = Department::query()->findOrFail($id);
            $dept->archive();
            $audit->log('department.archived', auth()->user(), $dept);
        } catch (\RuntimeException $e) {
            $this->addError('archive', $e->getMessage());
        }
    }

    public function restore(int $id, AuditLogService $audit): void
    {
        $dept = Department::query()->findOrFail($id);
        $dept->update(['is_active' => true]);
        $audit->log('department.restored', auth()->user(), $dept);
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'headUserId', 'autoAssign', 'queueIds');
    }
}; ?>

<div class="space-y-4">
    <x-card>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Add department') }}</h3>
        <form wire:submit="create" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <x-text-input wire:model="name" class="w-full" placeholder="{{ __('Department name') }}" />
            </div>
            <x-primary-button>{{ __('Add') }}</x-primary-button>
        </form>
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </x-card>

    <x-input-error :messages="$errors->get('archive')" />

    <x-card padding="p-0" class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50/80">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Name') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Head') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Employees') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Status') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($departments as $department)
                        <tr class="hover:bg-gray-50/80 transition-colors {{ ! $department->is_active ? 'opacity-60' : '' }}">
                            @if ($editingId === $department->id)
                                <td colspan="5" class="px-4 py-3">
                                    @php $queueLookup = $users->keyBy('id'); @endphp
                                    <form wire:submit="saveEdit" class="space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                                            <div>
                                                <x-input-label :value="__('Name')" class="text-xs" />
                                                <x-text-input wire:model="editName" class="w-full mt-1" />
                                            </div>
                                            <div>
                                                <x-input-label :value="__('Head')" class="text-xs" />
                                                <select wire:model="headUserId" class="w-full mt-1 border-gray-300 rounded-lg shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="">{{ __('— Not set —') }}</option>
                                                    @foreach ($editingDepartmentUsers as $user)
                                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex items-center gap-2 pt-5">
                                                <input type="checkbox" wire:model.live="autoAssign" id="autoAssign" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                <label for="autoAssign" class="text-xs text-gray-600">{{ __('Auto-assign') }}</label>
                                            </div>
                                            <div class="flex gap-2">
                                                <x-primary-button>{{ __('Save') }}</x-primary-button>
                                                <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                            </div>
                                        </div>

                                        <div class="rounded-lg border border-gray-200 bg-gray-50/60 p-4">
                                            <div class="flex items-center justify-between mb-2">
                                                <h4 class="text-sm font-semibold text-gray-900">{{ __('Assignment queue') }}</h4>
                                                <x-pill :color="$autoAssign ? 'green' : 'gray'">
                                                    {{ $autoAssign ? __('Auto-assign: on') : __('Auto-assign: off') }}
                                                </x-pill>
                                            </div>
                                            <p class="text-xs text-gray-500 mb-3">{{ __('When auto-assign is on, new tasks are distributed across the queue in round-robin order.') }}</p>

                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">{{ __('Current queue') }}</p>
                                                    @if (count($queueIds) === 0)
                                                        <x-empty-state>{{ __('Queue is empty.') }}</x-empty-state>
                                                    @else
                                                        <ol class="space-y-1">
                                                            @foreach ($queueIds as $i => $qid)
                                                                <li class="flex items-center justify-between gap-2 bg-white rounded-lg border border-gray-200 px-3 py-1.5">
                                                                    <span class="text-sm text-gray-800">{{ $i + 1 }}. {{ $queueLookup[$qid]->name ?? '#'.$qid }}</span>
                                                                    <span class="flex items-center gap-1">
                                                                        <button type="button" wire:click="moveQueue({{ $i }}, -1)" @disabled($i === 0) class="inline-flex items-center justify-center px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 rounded-lg disabled:opacity-30">↑</button>
                                                                        <button type="button" wire:click="moveQueue({{ $i }}, 1)" @disabled($i === count($queueIds) - 1) class="inline-flex items-center justify-center px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 rounded-lg disabled:opacity-30">↓</button>
                                                                        <x-action-button variant="danger" size="sm" type="button" wire:click="removeFromQueue({{ $qid }})">✕</x-action-button>
                                                                    </span>
                                                                </li>
                                                            @endforeach
                                                        </ol>
                                                    @endif
                                                </div>
                                                <div>
                                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">{{ __('Available employees') }}</p>
                                                    @php $available = $editingDepartmentUsers->reject(fn ($u) => in_array((int) $u->id, $queueIds, true)); @endphp
                                                    @if ($available->isEmpty())
                                                        <x-empty-state>{{ __('No more employees to add.') }}</x-empty-state>
                                                    @else
                                                        <div class="flex flex-wrap gap-2">
                                                            @foreach ($available as $u)
                                                                <x-action-button variant="ghost" size="sm" type="button" wire:click="addToQueue({{ $u->id }})">
                                                                    + {{ $u->name }}
                                                                </x-action-button>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </td>
                            @else
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-900">{{ $department->name }}</div>
                                    @if ($department->auto_assign_enabled)
                                        <span class="inline-flex items-center gap-1 mt-0.5 text-xs text-green-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>{{ __('Auto-assign: on') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-gray-600">{{ $department->head?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-gray-700">{{ $department->users_count }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($department->is_active)
                                        <x-pill color="green">{{ __('Active') }}</x-pill>
                                    @else
                                        <x-pill color="gray">{{ __('Archived') }}</x-pill>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="inline-flex flex-wrap justify-end gap-1">
                                        @if ($department->is_active)
                                            <x-action-button variant="ghost" size="sm" wire:click="startEdit({{ $department->id }})">{{ __('Edit') }}</x-action-button>
                                            <x-action-button variant="danger" size="sm" wire:click="archive({{ $department->id }})" wire:confirm="{{ __('Archive this department?') }}">{{ __('Archive') }}</x-action-button>
                                        @else
                                            <x-action-button variant="ghost" size="sm" wire:click="restore({{ $department->id }})">{{ __('Restore') }}</x-action-button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-2.5">
                                <x-empty-state>{{ __('No data yet.') }}</x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
