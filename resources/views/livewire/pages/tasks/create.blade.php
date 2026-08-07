<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Rules\PlainTextLength;
use App\Services\SettingsService;
use App\Services\TaskAttachmentService;
use App\Services\TaskService;
use Livewire\Attributes\Layout;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\Volt\Component;

new #[Layout('components.tasks-layout')] class extends Component
{
    use WithFileUploads;

    public ?int $departmentId = null;

    public ?int $assigneeId = null;

    public ?int $categoryId = null;

    public string $title = '';

    public string $description = '';

    public int $priority = 5;

    public ?string $deadline = null;

    public string $specUrl = '';

    public string $checklistText = '';

    public array $watcherIds = [];

    public array $uploadFiles = [];

    public $pastedCreateFile = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->departmentId = $user->department_id;

        abort_unless($user->can('create', Task::class), 403);
    }

    public function updatedPastedCreateFile(): void
    {
        if ($this->pastedCreateFile === null) {
            return;
        }

        $this->uploadFiles[] = $this->pastedCreateFile;
        $this->pastedCreateFile = null;
    }

    public function with(): array
    {
        $user = auth()->user();
        $canAnyDept = $user->hasPermission('create_task_any_department') || $user->isAdmin();

        return [
            'departments' => $canAnyDept
                ? Department::query()->active()->orderBy('name')->get(['id', 'name'])
                : Department::query()->where('id', $user->department_id)->get(['id', 'name']),
            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'users' => User::query()
                ->where('is_active', true)
                ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'allUsers' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function updatedDepartmentId(): void
    {
        $this->assigneeId = null;
    }

    public function save(TaskService $tasks, TaskAttachmentService $attachments, SettingsService $settings): void
    {
        $this->validate([
            'departmentId' => 'required|exists:departments,id',
            'categoryId' => 'required|exists:categories,id',
            'title' => 'required|string|max:120',
            'description' => ['required', 'string', new PlainTextLength(min: 3, max: 20000)],
            'priority' => 'required|integer|min:1|max:10',
            'deadline' => 'nullable|date',
            'specUrl' => 'nullable|url|max:500',
            'assigneeId' => 'nullable|exists:users,id',
            'uploadFiles.*' => 'nullable|file|max:'.(int) $settings->get('attachment_max_kb', 10240),
        ]);

        $checklist = array_filter(array_map('trim', explode("\n", $this->checklistText)));

        try {
            $task = $tasks->create(auth()->user(), [
                'department_id' => $this->departmentId,
                'assignee_id' => $this->assigneeId,
                'category_id' => $this->categoryId,
                'title' => $this->title,
                'description' => $this->description,
                'priority' => $this->priority,
                'deadline' => $this->deadline,
                'spec_url' => $this->specUrl ?: null,
            ], $checklist, $this->watcherIds);

            foreach ($this->uploadFiles as $file) {
                $attachments->store($task, auth()->user(), $file, null, false);
            }

            $this->redirect(route('tasks.show', $task), navigate: true);
        } catch (RuntimeException $e) {
            $this->addError('assigneeId', $e->getMessage());

            return;
        }
    }
}; ?>

<div class="space-y-4">
    <a href="{{ route('tasks.index') }}" wire:navigate
       class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Back to tasks') }}
    </a>

    <form wire:submit="save" class="max-w-5xl">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4 items-start">
            <x-card>
                <x-slot name="header">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Create task') }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">{{ __('Task information') }}</p>
                    </div>
                </x-slot>

                <div class="space-y-4">
                    <div>
                        <x-input-label :value="__('Title')" class="text-xs text-gray-500 font-medium" />
                        <x-text-input wire:model="title" class="mt-1 w-full rounded-lg text-sm" maxlength="120" />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label :value="__('Description')" class="text-xs text-gray-500 font-medium" />
                        <textarea wire:model="description" rows="6"
                                  class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="{{ __('Description') }}"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label :value="__('Checklist')" class="text-xs text-gray-500 font-medium" />
                        <textarea wire:model="checklistText" rows="4"
                                  class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="{{ __('One item per line') }}"></textarea>
                    </div>
                </div>
            </x-card>

            <div class="space-y-4 lg:sticky lg:top-6 self-start">
                <x-card>
                    <div class="space-y-4">
                        <div>
                            <x-input-label :value="__('Department')" class="text-xs text-gray-500 font-medium" />
                            <select wire:model.live="departmentId"
                                    class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @if(count($departments) === 1) disabled @endif>
                                @foreach ($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('departmentId')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label :value="__('Assignee')" class="text-xs text-gray-500 font-medium" />
                            <select wire:model="assigneeId" class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('— Auto (head / queue) —') }}</option>
                                @foreach ($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ __('If not selected, task goes to department head or assign queue.') }}</p>
                        </div>

                        <div>
                            <x-input-label :value="__('Category')" class="text-xs text-gray-500 font-medium" />
                            <select wire:model="categoryId" class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('— Select —') }}</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('categoryId')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label :value="__('Priority')" class="text-xs text-gray-500 font-medium" />
                            <div class="mt-1 flex items-center gap-2">
                                <input type="range" wire:model.live="priority" min="1" max="10" class="flex-1 accent-indigo-600" />
                                <x-priority-bar :priority="$priority" size="sm" />
                                <span class="text-sm font-medium text-gray-700 w-5 text-center tabular-nums">{{ $priority }}</span>
                            </div>
                        </div>

                        <div>
                            <x-input-label :value="__('Deadline')" class="text-xs text-gray-500 font-medium" />
                            <x-text-input type="date" wire:model="deadline" class="mt-1 w-full rounded-lg text-sm" />
                        </div>

                        <div>
                            <x-input-label :value="__('Spec URL')" class="text-xs text-gray-500 font-medium" />
                            <x-text-input wire:model="specUrl" type="url" class="mt-1 w-full rounded-lg text-sm" placeholder="https://" />
                        </div>

                        <div
                            x-data="clipboardImagePaste($wire, 'pastedCreateFile')"
                            @paste="handlePaste($event)"
                            tabindex="0"
                            class="outline-none focus:ring-2 focus:ring-indigo-200 rounded-lg"
                        >
                            <x-input-label :value="__('Attachments')" class="text-xs text-gray-500 font-medium" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('Paste image hint') }}</p>
                            <input type="file" wire:model="uploadFiles" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" class="mt-1 text-sm w-full" />
                            @if (count($uploadFiles) > 0)
                                <ul class="mt-1 text-xs text-gray-600 space-y-0.5">
                                    @foreach ($uploadFiles as $file)
                                        <li>{{ is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : __('Attachment') }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <x-input-error :messages="$errors->get('uploadFiles.*')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label :value="__('Watchers')" class="text-xs text-gray-500 font-medium" />
                            <select wire:model="watcherIds" multiple
                                    class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm h-28 focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($allUsers as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </x-card>

                <div class="flex gap-3">
                    <x-primary-button>{{ __('Create task') }}</x-primary-button>
                    <a href="{{ route('tasks.index') }}" wire:navigate>
                        <x-secondary-button type="button">{{ __('Cancel') }}</x-secondary-button>
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

@script
<script>
    Alpine.data('clipboardImagePaste', (wire, property) => ({
        handlePaste(event) {
            const items = event.clipboardData?.items;
            if (!items) {
                return;
            }

            const images = Array.from(items).filter((item) => item.type.startsWith('image/'));
            if (images.length === 0) {
                return;
            }

            event.preventDefault();

            images.forEach((item, index) => {
                const file = item.getAsFile();
                if (!file) {
                    return;
                }

                const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                const named = new File([file], `paste-${Date.now()}-${index}.${ext}`, { type: file.type });
                wire.upload(property, named);
            });
        },
    }));
</script>
@endscript
