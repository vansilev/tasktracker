<?php

use App\Models\Category;
use App\Services\AuditLogService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    public string $name = '';
    public ?int $editingId = null;
    public string $editName = '';
    public int $editSortOrder = 0;

    public function with(): array
    {
        return [
            'categories' => Category::query()->ordered()->get(),
        ];
    }

    public function create(AuditLogService $audit): void
    {
        $this->validate(['name' => 'required|string|max:255|unique:categories,name']);

        $maxOrder = Category::query()->max('sort_order') ?? 0;

        $cat = Category::query()->create([
            'name' => $this->name,
            'is_active' => true,
            'sort_order' => $maxOrder + 1,
        ]);

        $audit->log('category.created', auth()->user(), $cat, null, [
            'name' => $cat->name,
            'sort_order' => $cat->sort_order,
        ]);

        $this->reset('name');
    }

    public function startEdit(int $id): void
    {
        $cat = Category::query()->findOrFail($id);
        $this->editingId = $cat->id;
        $this->editName = $cat->name;
        $this->editSortOrder = $cat->sort_order;
    }

    public function saveEdit(AuditLogService $audit): void
    {
        $cat = Category::query()->findOrFail($this->editingId);

        $this->validate([
            'editName' => 'required|string|max:255|unique:categories,name,'.$cat->id,
            'editSortOrder' => 'required|integer|min:0',
        ]);

        $oldValues = [
            'name' => $cat->name,
            'sort_order' => $cat->sort_order,
        ];

        $cat->update([
            'name' => $this->editName,
            'sort_order' => $this->editSortOrder,
        ]);

        $newValues = [
            'name' => $cat->name,
            'sort_order' => $cat->sort_order,
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
            $audit->log('category.updated', auth()->user(), $cat, $changedOld, $changedNew);
        }

        $this->reset('editingId', 'editName', 'editSortOrder');
    }

    public function toggle(int $id, AuditLogService $audit): void
    {
        $cat = Category::query()->findOrFail($id);
        $wasActive = $cat->is_active;
        $cat->update(['is_active' => ! $wasActive]);

        $audit->log(
            $wasActive ? 'category.archived' : 'category.restored',
            auth()->user(),
            $cat,
        );
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editSortOrder');
    }
}; ?>

<div class="space-y-4">
    <x-card>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Add category') }}</h3>
        <form wire:submit="create" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <x-text-input wire:model="name" class="w-full" placeholder="{{ __('Category name') }}" />
            </div>
            <x-primary-button>{{ __('Add') }}</x-primary-button>
        </form>
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50/80">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Order') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Name') }}</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Status') }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($categories as $category)
                        @if ($editingId === $category->id)
                            <tr>
                                <td colspan="4" class="px-4 py-3">
                                    <form wire:submit="saveEdit" class="flex flex-wrap gap-3 items-end">
                                        <div>
                                            <x-input-label :value="__('Order')" class="text-xs" />
                                            <x-text-input wire:model="editSortOrder" type="number" class="w-24 mt-1" />
                                        </div>
                                        <div class="flex-1 min-w-[200px]">
                                            <x-input-label :value="__('Name')" class="text-xs" />
                                            <x-text-input wire:model="editName" class="w-full mt-1" />
                                        </div>
                                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                    </form>
                                </td>
                            </tr>
                        @else
                            <tr class="hover:bg-gray-50/80 transition-colors {{ ! $category->is_active ? 'opacity-60' : '' }}">
                                <td class="px-4 py-2.5 text-gray-700">{{ $category->sort_order }}</td>
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $category->name }}</td>
                                <td class="px-4 py-2.5">
                                    <x-pill :color="$category->is_active ? 'green' : 'gray'">{{ $category->is_active ? __('Active') : __('Archived') }}</x-pill>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="inline-flex flex-wrap justify-end gap-1">
                                        <x-action-button variant="ghost" size="sm" wire:click="startEdit({{ $category->id }})">{{ __('Edit') }}</x-action-button>
                                        <x-action-button variant="ghost" size="sm" wire:click="toggle({{ $category->id }})">
                                            {{ $category->is_active ? __('Archive') : __('Restore') }}
                                        </x-action-button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-2.5">
                                <x-empty-state>{{ __('No data yet.') }}</x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
