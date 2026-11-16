<?php

use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Rules\PlainTextLength;
use App\Services\MentionService;
use App\Services\SettingsService;
use App\Services\TaskAttachmentService;
use App\Services\TaskService;
use Illuminate\Database\QueryException;
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

    /** Combobox buffer: picking a person adds them to $watcherIds. */
    public ?int $watcherToAdd = null;

    public array $uploadFiles = [];

    public $pastedCreateFile = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->departmentId = $user->department_id;

        abort_unless($user->can('create', Task::class), 403);
    }

    public function updatedWatcherToAdd($value): void
    {
        $id = (int) $value;
        if ($id > 0 && ! in_array($id, array_map('intval', $this->watcherIds), true)) {
            $this->watcherIds[] = $id;
        }
        $this->watcherToAdd = null;
    }

    public function removeWatcherId(int $id): void
    {
        $this->watcherIds = array_values(array_filter($this->watcherIds, fn ($w) => (int) $w !== $id));
    }

    public function updatedPastedCreateFile(): void
    {
        if ($this->pastedCreateFile === null) {
            return;
        }

        $this->uploadFiles[] = $this->pastedCreateFile;
        $this->pastedCreateFile = null;
    }

    /**
     * TipTap calls this after inline upload (same hook as show page).
     * Create has no attachment sidebar to refresh — keep as intentional no-op.
     */
    public function refreshAttachments(): void
    {
        //
    }

    /** @return list<array{id: int, name: string, email: string, label: string}> */
    public function mentionSearch(string $term): array
    {
        return app(MentionService::class)->searchMentionableUsers($term);
    }

    public function with(): array
    {
        $user = auth()->user();
        $canAnyDept = $user->hasPermission('create_task_any_department') || $user->isAdmin();
        $canCreate = $user->can('create', Task::class);

        return [
            'canCreate' => $canCreate,
            'pendingInlineUploadUrl' => $canCreate ? route('pending.attachments.inline') : null,
            'departments' => $canAnyDept
                ? Department::query()->active()->orderBy('name')->get(['id', 'name'])
                : Department::query()->where('id', $user->department_id)->get(['id', 'name']),
            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'users' => User::query()
                ->people()
                ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'allUsers' => User::query()->people()->orderBy('name')->get(['id', 'name']),
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
        } catch (QueryException $e) {
            // QueryException extends RuntimeException — do not mis-label DB failures
            // as assignee errors (e.g. tasks_number_unique after soft-delete).
            report($e);
            $this->addError('title', __('task.create_failed'));

            return;
        } catch (RuntimeException $e) {
            $this->addError('assigneeId', $e->getMessage());

            return;
        }
    }
}; ?>

<div class="space-y-4">
    <a href="{{ route('tasks.index') }}" wire:navigate
       class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-800 transition-colors">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Back to tasks') }}
    </a>

    <form wire:submit="save" class="max-w-6xl">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
            <x-card class="lg:col-span-2">
                <x-slot name="header">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Create task') }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">{{ __('Task information') }}</p>
                    </div>
                </x-slot>

                <div class="space-y-4">
                    <div>
                        <x-input-label for="f-title" :value="__('Title')" class="text-xs text-gray-500 font-medium" />
                        <x-text-input id="f-title" wire:model="title" class="mt-1 w-full rounded-lg text-sm" maxlength="120" />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label :value="__('Description')" class="text-xs text-gray-500 font-medium" />
                        <x-rich-text-editor
                            model="description"
                            key="task-create-description"
                            class="mt-1"
                            min-height="10rem"
                            :placeholder="__('Description')"
                            :aria-label="__('Description')"
                            :enable-mentions="true"
                            :enable-inline-attachments="$canCreate"
                            :inline-upload-url="$pendingInlineUploadUrl"
                        />
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="f-checklistText" :value="__('Checklist')" class="text-xs text-gray-500 font-medium" />
                        <textarea id="f-checklistText" wire:model="checklistText" rows="4"
                                  class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500"
                                  placeholder="{{ __('One item per line') }}"></textarea>
                    </div>
                </div>
            </x-card>

            <div class="space-y-4 lg:sticky lg:top-6 self-start lg:col-span-1">
                <x-card>
                    <div class="space-y-4">
                        <div>
                            <x-input-label for="f-departmentId" :value="__('Department')" class="text-xs text-gray-500 font-medium" />
                            <x-combobox id="f-departmentId"
                                class="mt-1"
                                wire:model.live="departmentId"
                                :disabled="count($departments) === 1"
                                :options="$departments->map(fn ($dept) => ['value' => $dept->id, 'label' => $dept->name])->all()"
                                :placeholder="__('— Select —')"
                            />
                            <x-input-error :messages="$errors->get('departmentId')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="f-assigneeId" :value="__('Assignee')" class="text-xs text-gray-500 font-medium" />
                            <x-combobox id="f-assigneeId"
                                class="mt-1"
                                wire:key="create-assignee-{{ $departmentId }}"
                                wire:model="assigneeId"
                                :options="collect([['value' => null, 'label' => __('— Auto (head / queue) —')]])->concat($users->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]))->all()"
                                :placeholder="__('— Auto (head / queue) —')"
                            />
                            <p class="mt-1 text-xs text-gray-500">{{ __('If not selected, task goes to department head or assign queue.') }}</p>
                        </div>

                        <div>
                            <x-input-label for="f-categoryId" :value="__('Category')" class="text-xs text-gray-500 font-medium" />
                            <x-combobox id="f-categoryId"
                                class="mt-1"
                                wire:model="categoryId"
                                :options="collect([['value' => null, 'label' => __('— Select —')]])->concat($categories->map(fn ($cat) => ['value' => $cat->id, 'label' => $cat->name]))->all()"
                                :placeholder="__('— Select —')"
                            />
                            <x-input-error :messages="$errors->get('categoryId')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="f-priority" :value="__('Priority')" class="text-xs text-gray-500 font-medium" />
                            <div class="mt-1 flex items-center gap-2">
                                <input id="f-priority" type="range" wire:model.live="priority" min="1" max="10" class="flex-1 accent-primary-600" />
                                <x-priority-bar :priority="$priority" size="sm" mode="scale" />
                                <span class="text-sm font-medium text-gray-700 w-5 text-center tabular-nums">{{ $priority }}</span>
                            </div>
                        </div>

                        <div>
                            <x-input-label for="f-deadline" :value="__('Deadline')" class="text-xs text-gray-500 font-medium" />
                            <x-text-input id="f-deadline" type="date" wire:model="deadline" class="mt-1 w-full rounded-lg text-sm" />
                        </div>

                        <div>
                            <x-input-label for="f-specUrl" :value="__('Spec URL')" class="text-xs text-gray-500 font-medium" />
                            <x-text-input id="f-specUrl" wire:model="specUrl" type="url" class="mt-1 w-full rounded-lg text-sm" placeholder="https://" />
                        </div>

                        <div
                            x-data="clipboardImagePaste($wire, 'pastedCreateFile')"
                            @paste="handlePaste($event)"
                            tabindex="0"
                            class="outline-none focus:ring-2 focus:ring-primary-200 rounded-lg"
                        >
                            <p class="text-xs text-gray-500 font-medium">{{ __('Attachments') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ __('Paste image hint') }}</p>
                            <x-file-input id="f-uploadFiles" wire:model="uploadFiles" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" :names="false" class="mt-2" />
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
                            <x-input-label for="f-watcherToAdd" :value="__('Watchers')" class="text-xs text-gray-500 font-medium" />
                            @php
                                $watcherIdsInt = array_map('intval', $watcherIds);
                                $chosenWatchers = $allUsers->whereIn('id', $watcherIdsInt);
                            @endphp
                            @if ($chosenWatchers->isNotEmpty())
                                <ul class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($chosenWatchers as $w)
                                        <li class="inline-flex items-center gap-1 rounded-control bg-gray-100 py-0.5 ps-2 pe-1 text-body-sm text-gray-800">
                                            {{ $w->name }}
                                            <button type="button" wire:click="removeWatcherId({{ $w->id }})" class="rounded p-0.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900" aria-label="{{ __('Delete') }}: {{ $w->name }}"><x-icon.x-mark class="w-3.5 h-3.5" /></button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <x-combobox id="f-watcherToAdd"
                                class="mt-1.5"
                                wire:key="create-watcher-picker-{{ count($watcherIds) }}"
                                wire:model.live="watcherToAdd"
                                :options="$allUsers->reject(fn ($u) => in_array($u->id, $watcherIdsInt, true))->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values()->all()"
                                :placeholder="__('Add watcher')"
                            />
                        </div>
                    </div>
                </x-card>

            </div>

            {{-- Form actions under the main column, left-aligned, in reading order. --}}
            <div class="flex gap-3 lg:col-span-2">
                <x-button variant="primary" type="submit">{{ __('Create task') }}</x-button>
                <x-button variant="secondary" :href="route('tasks.index')" wire:navigate>{{ __('Cancel') }}</x-button>
            </div>
        </div>
    </form>
</div>

@script
<script>
    /*
     * Sidecar Attachments field: paste still fills uploadFiles.
     * Description TipTap uses pending.attachments.inline (promoted on save).
     */
    Alpine.data('clipboardImagePaste', (wire, property) => ({
        handlePaste(event) {
            if (event.target?.closest?.('[data-inline-attachments="true"]')) {
                return;
            }

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
