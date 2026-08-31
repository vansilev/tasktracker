<?php

use App\Enums\TaskStatus;
use App\Models\BillingItem;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use App\Rules\PlainTextLength;
use App\Services\BillingPaymentService;
use App\Services\MentionService;
use App\Services\SettingsService;
use App\Services\TaskAttachmentService;
use App\Services\TaskContentService;
use App\Services\TaskHistoryPresenter;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\Volt\Component;

new #[Layout('components.tasks-layout')] class extends Component
{
    use WithFileUploads;

    public Task $task;

    public string $commentBody = '';

    public bool $editing = false;

    public string $editTitle = '';

    public string $editDescription = '';

    public int $editPriority = 5;

    public ?string $editDeadline = null;

    public string $editSpecUrl = '';

    public string $editResultUrl = '';

    public string $resultUrl = '';

    public ?string $resultUrlSaved = null;

    public ?int $editAssigneeId = null;

    public ?int $editAssigneeDepartmentId = null;

    public string $reassignComment = '';

    public string $newChecklistItem = '';

    public bool $creatingSubtask = false;

    public int $subtaskEditorKey = 0;

    public string $subtaskTitle = '';

    public string $subtaskDescription = '';

    public ?int $subtaskDepartmentId = null;

    public ?int $subtaskAssigneeId = null;

    public ?int $subtaskCategoryId = null;

    public int $subtaskPriority = 5;

    public ?string $subtaskDeadline = null;

    public string $subtaskSpecUrl = '';

    public string $subtaskChecklistText = '';

    public array $subtaskWatcherIds = [];

    public array $subtaskUploadFiles = [];

    public $pastedSubtaskFile = null;

    public ?int $convertingChecklistItemId = null;

    public ?int $newBlockerId = null;

    public ?string $pendingTransition = null;

    public string $transitionComment = '';

    public ?string $transitionError = null;

    public bool $editingWatchers = false;

    public array $watcherIds = [];

    public ?int $editingCommentId = null;

    public string $editCommentBody = '';

    public array $uploadFiles = [];

    public array $commentFiles = [];

    public $pastedCommentFile = null;

    public $pastedTaskFile = null;

    /** Temporary upload slot used by TipTap inline attachment insert (show page). */
    public $inlineAttachmentFile = null;

    public bool $billingPayOpen = false;

    public string $billingPayAmount = '';

    public bool $billingSkipOpen = false;

    public string $billingSkipReason = '';

    public function mount(Task $task): void
    {
        $this->task = $task->load([
            'initiator', 'assignee', 'department', 'category',
            'parent:id,number,title',
            'subtasks.assignee:id,name',
            'subtasks.blockers:id,number,status',
            'blockers:id,number,title,status',
            'comments.author', 'comments.mentionedUsers', 'comments.attachments',
            'histories.changedBy',
            'checklistItems', 'watchers', 'attachments.uploader',
        ]);

        abort_unless(auth()->user()->can('view', $this->task), 403);

        $this->editTitle = $this->task->title;
        $this->editDescription = app(TaskContentService::class)->toEditorHtml(
            $this->task->description,
            $this->task->description_format,
        );
        $this->editPriority = $this->task->priority;
        $this->editDeadline = $this->task->deadline?->format('Y-m-d');
        $this->editSpecUrl = $this->task->spec_url ?? '';
        $this->editResultUrl = $this->task->result_url ?? '';
        $this->resultUrl = $this->task->result_url ?? '';
        $this->editAssigneeId = $this->task->assignee_id;
        $this->editAssigneeDepartmentId = $this->task->department_id;
    }

    public function updatedPastedCommentFile(): void
    {
        if ($this->pastedCommentFile === null) {
            return;
        }

        $this->commentFiles[] = $this->pastedCommentFile;
        $this->pastedCommentFile = null;
    }

    public function updatedPastedTaskFile(TaskAttachmentService $attachments, SettingsService $settings): void
    {
        if ($this->pastedTaskFile === null) {
            return;
        }

        abort_unless(auth()->user()->can('uploadAttachment', $this->task), 403);

        $this->validate([
            'pastedTaskFile' => 'file|max:'.(int) $settings->get('attachment_max_kb', 10240),
        ]);

        $attachments->store($this->task, auth()->user(), $this->pastedTaskFile);
        $this->pastedTaskFile = null;
        $this->task->load(['attachments.uploader', 'histories.changedBy']);
    }

    public function softDeleteTask(TaskService $tasks): void
    {
        abort_unless(auth()->user()->can('delete', $this->task), 403);

        $tasks->softDelete($this->task, auth()->user());

        $this->redirect(route('tasks.index'), navigate: true);
    }

    public function updatedEditAssigneeDepartmentId(): void
    {
        $this->editAssigneeId = null;
    }

    public function with(): array
    {
        return [
            'transitions' => app(TaskWorkflowService::class)->allowedTransitions(auth()->user(), $this->task),
            'assignees' => User::query()
                ->people()
                ->where('department_id', $this->editAssigneeDepartmentId ?? $this->task->department_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'checklistDone' => $this->task->checklistItems->where('is_done', true)->count(),
            'checklistTotal' => $this->task->checklistItems->count(),
            'subtaskProgress' => $this->task->subtaskProgress(),
            'subtaskProgressPercent' => $this->task->subtaskProgressPercent(),
            'subtaskTotal' => $this->task->subtaskTotalCount(),
            'openSubtasksCount' => $this->task->openSubtasksCount(),
            'canCreateSubtask' => auth()->user()->can('createSubtask', $this->task),
            'canManageBlockers' => auth()->user()->can('manageBlockers', $this->task),
            'siblingOptions' => $this->siblingBlockerOptions(),
            'subtaskDepartments' => (auth()->user()->hasPermission('create_task_any_department') || auth()->user()->isAdmin())
                ? Department::query()->active()->orderBy('name')->get(['id', 'name'])
                : Department::query()->where('id', auth()->user()->department_id)->get(['id', 'name']),
            'subtaskCategories' => Category::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'subtaskUsers' => User::query()
                ->people()
                ->when($this->subtaskDepartmentId, fn ($q) => $q->where('department_id', $this->subtaskDepartmentId))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'pendingInlineUploadUrl' => auth()->user()->can('create', Task::class)
                ? route('pending.attachments.inline')
                : null,
            'canManageWatchers' => auth()->user()->can('manageWatchers', $this->task),
            'canUpdate' => auth()->user()->can('update', $this->task),
            'canChangePriority' => auth()->user()->can('changePriority', $this->task),
            'canAssign' => auth()->user()->can('assign', $this->task),
            'canComment' => auth()->user()->can('comment', $this->task),
            'canManageChecklist' => auth()->user()->can('manageChecklist', $this->task),
            'canToggleChecklist' => auth()->user()->can('toggleChecklist', $this->task),
            'canUploadAttachment' => auth()->user()->can('uploadAttachment', $this->task),
            'inlineUploadUrl' => route('tasks.attachments.inline', $this->task),
            'canEditResultUrl' => auth()->user()->can('updateResultUrl', $this->task),
            'canDeleteTask' => auth()->user()->can('delete', $this->task),
            'billingItem' => BillingItem::query()->where('last_task_id', $this->task->id)->first(),
            'allUsers' => User::query()
                ->people()
                ->orderBy('name')
                ->get(['id', 'name']),
            'presentedHistories' => app(TaskHistoryPresenter::class)->presentMany($this->task->histories),
        ];
    }

    public function openBillingPay(): void
    {
        $item = BillingItem::query()->where('last_task_id', $this->task->id)->firstOrFail();
        $this->authorize('markPaid', $item);
        $this->billingPayOpen = true;
        $this->billingPayAmount = $item->amount !== null ? (string) $item->amount : '';
    }

    public function confirmBillingPay(BillingPaymentService $payments): void
    {
        $item = BillingItem::query()->where('last_task_id', $this->task->id)->firstOrFail();
        try {
            $payments->markPaid(auth()->user(), $item, $this->billingPayAmount !== '' ? $this->billingPayAmount : null);
            $this->billingPayOpen = false;
            $this->task->refresh();
            $this->task->load(['comments.author', 'histories.changedBy']);
            session()->flash('billing_status', __('billing.task_paid_comment'));
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    public function confirmBillingSkip(BillingPaymentService $payments): void
    {
        $item = BillingItem::query()->where('last_task_id', $this->task->id)->firstOrFail();
        try {
            $payments->skip(auth()->user(), $item, $this->billingSkipReason);
            $this->billingSkipOpen = false;
            $this->billingSkipReason = '';
            $this->task->refresh();
            $this->task->load(['comments.author', 'histories.changedBy']);
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    public function startEditWatchers(): void
    {
        abort_unless(auth()->user()->can('manageWatchers', $this->task), 403);
        $this->watcherIds = $this->task->watchers->pluck('id')->map(fn ($v) => (string) $v)->all();
        $this->editingWatchers = true;
    }

    public function saveWatchers(): void
    {
        abort_unless(auth()->user()->can('manageWatchers', $this->task), 403);
        $this->task->watchers()->sync($this->watcherIds);
        $this->task->load('watchers');
        $this->editingWatchers = false;
    }

    public function cancelEditWatchers(): void
    {
        $this->editingWatchers = false;
        $this->watcherIds = [];
    }

    public function removeWatcher(int $userId): void
    {
        abort_unless(auth()->user()->can('manageWatchers', $this->task), 403);
        $this->task->watchers()->detach($userId);
        $this->task->load('watchers');
    }

    public function transitionLabel(TaskStatus $status): string
    {
        if ($this->task->status === TaskStatus::Completed && $status === TaskStatus::InProgress) {
            return __('task.reopen');
        }

        return $status->label();
    }

    public function selectTransition(string $status): void
    {
        $target = TaskStatus::from($status);
        $this->transitionError = null;

        if (TaskStatus::requiresComment($target, $this->task->status)) {
            $this->pendingTransition = $status;
            $this->transitionComment = '';

            return;
        }

        $this->performTransition($status);
    }

    public function confirmTransition(TaskWorkflowService $workflow): void
    {
        $this->validate([
            'transitionComment' => ['required', 'string', new PlainTextLength(min: 1, max: 20000)],
        ], [], [
            'transitionComment' => __('Transition comment'),
        ]);

        $this->performTransition($this->pendingTransition, $this->transitionComment);
    }

    public function cancelTransition(): void
    {
        $this->pendingTransition = null;
        $this->transitionComment = '';
        $this->transitionError = null;
    }

    private function performTransition(?string $status, ?string $comment = null): void
    {
        if ($status === null) {
            return;
        }

        try {
            app(TaskWorkflowService::class)->transition(
                $this->task,
                auth()->user(),
                TaskStatus::from($status),
                $comment,
            );

            $this->pendingTransition = null;
            $this->transitionComment = '';
            $this->transitionError = null;
        } catch (InvalidArgumentException $e) {
            $this->transitionError = $e->getMessage();
        }

        $this->task->refresh()->load([
            'initiator', 'assignee', 'department', 'category',
            'parent:id,number,title',
            'subtasks.assignee:id,name',
            'subtasks.blockers:id,number,status',
            'blockers:id,number,title,status',
            'comments.author', 'comments.attachments', 'histories.changedBy', 'checklistItems', 'watchers',
        ]);
    }

    public function saveResultUrl(TaskService $tasks): void
    {
        abort_unless(auth()->user()->can('updateResultUrl', $this->task), 403);

        $this->validate([
            'resultUrl' => 'nullable|url|max:500',
        ], [], [
            'resultUrl' => __('Result URL'),
        ]);

        try {
            $tasks->updateResultUrl($this->task, auth()->user(), $this->resultUrl ?: null);
            $this->resultUrlSaved = __('Saved');
            $this->task->refresh();
            $this->editResultUrl = $this->task->result_url ?? '';
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function addComment(TaskService $tasks, TaskAttachmentService $attachments, SettingsService $settings): void
    {
        $this->validate([
            'commentBody' => ['required', 'string', new PlainTextLength(min: 1, max: 20000)],
            'commentFiles.*' => 'nullable|file|max:'.(int) $settings->get('attachment_max_kb', 10240),
        ]);

        $comment = $tasks->addComment($this->task, auth()->user(), $this->commentBody);

        foreach ($this->commentFiles as $file) {
            $attachments->store($this->task, auth()->user(), $file, $comment->id);
        }

        $this->commentBody = '';
        $this->commentFiles = [];
        $this->task->load(['comments.author', 'comments.mentionedUsers', 'comments.attachments']);
    }

    public function saveEdit(TaskService $tasks): void
    {
        abort_unless(auth()->user()->can('update', $this->task), 403);

        $assigneeChanging = auth()->user()->can('assign', $this->task)
            && $this->editAssigneeId !== null
            && (int) $this->editAssigneeId !== (int) $this->task->assignee_id;

        $rules = [
            'editTitle' => 'required|string|max:120',
            'editDescription' => ['required', 'string', new PlainTextLength(min: 3, max: 20000)],
            'editPriority' => 'integer|min:1|max:10',
            'editDeadline' => 'nullable|date',
            'editSpecUrl' => 'nullable|url|max:500',
            'editResultUrl' => 'nullable|url|max:500',
        ];

        if ($assigneeChanging) {
            $rules['reassignComment'] = ['required', 'string', new PlainTextLength(min: 1, max: 20000)];
        }

        $this->validate($rules, [], [
            'reassignComment' => __('Reassignment comment'),
        ]);

        $data = [
            'title' => $this->editTitle,
            'description' => $this->editDescription,
            'deadline' => $this->editDeadline,
            'spec_url' => $this->editSpecUrl ?: null,
            'result_url' => $this->editResultUrl ?: null,
        ];

        if (auth()->user()->can('changePriority', $this->task)) {
            $data['priority'] = $this->editPriority;
        }

        if (auth()->user()->can('assign', $this->task)) {
            $data['assignee_id'] = $this->editAssigneeId;
        }

        $tasks->update($this->task, auth()->user(), $data);

        if ($assigneeChanging) {
            $tasks->addComment($this->task, auth()->user(), $this->reassignComment);
            $this->reassignComment = '';
        }

        $this->task->refresh()->load([
            'initiator', 'assignee', 'department', 'category',
            'parent:id,number,title',
            'subtasks.assignee:id,name',
            'subtasks.blockers:id,number,status',
            'blockers:id,number,title,status',
            'comments.author', 'comments.mentionedUsers', 'comments.attachments',
        ]);
        $this->editAssigneeDepartmentId = $this->task->department_id;
        $this->editAssigneeId = $this->task->assignee_id;
        // Reload from storage so the next edit starts from the sanitized HTML
        // rather than the browser's pre-sanitize version.
        $this->editDescription = app(TaskContentService::class)->toEditorHtml(
            $this->task->description,
            $this->task->description_format,
        );
        $this->editing = false;
    }

    public function toggleChecklist(int $itemId, TaskService $tasks): void
    {
        $item = TaskChecklistItem::query()->where('task_id', $this->task->id)->findOrFail($itemId);
        $tasks->toggleChecklistItem($item, auth()->user());
        $this->task->load('checklistItems');
    }

    public function addChecklistItem(TaskService $tasks): void
    {
        $text = trim($this->newChecklistItem);
        if ($text === '') {
            return;
        }

        $tasks->addChecklistItem($this->task, auth()->user(), $text);
        $this->newChecklistItem = '';
        $this->task->load('checklistItems');
    }

    public function updatedSubtaskDepartmentId(): void
    {
        $this->subtaskAssigneeId = null;
    }

    public function updatedPastedSubtaskFile(): void
    {
        if ($this->pastedSubtaskFile === null) {
            return;
        }

        $this->subtaskUploadFiles[] = $this->pastedSubtaskFile;
        $this->pastedSubtaskFile = null;
    }

    public function openSubtaskModal(): void
    {
        abort_unless(auth()->user()->can('createSubtask', $this->task), 403);

        $this->resetSubtaskForm();
        $this->creatingSubtask = true;
    }

    public function openSubtaskModalFromChecklist(int $itemId): void
    {
        abort_unless(auth()->user()->can('createSubtask', $this->task), 403);
        abort_if($this->task->isSubtask(), 403);

        $item = TaskChecklistItem::query()->where('task_id', $this->task->id)->findOrFail($itemId);

        $this->resetSubtaskForm();
        $this->convertingChecklistItemId = $item->id;
        $this->subtaskTitle = mb_substr(trim($item->text), 0, 120);
        $this->creatingSubtask = true;
    }

    private function resetSubtaskForm(): void
    {
        $user = auth()->user();
        $this->convertingChecklistItemId = null;
        $this->subtaskTitle = '';
        $this->subtaskDescription = '';
        $this->subtaskDepartmentId = $user->department_id;
        $this->subtaskAssigneeId = $user->id;
        $this->subtaskCategoryId = $this->task->category_id;
        $this->subtaskPriority = $this->task->priority;
        $this->subtaskDeadline = null;
        $this->subtaskSpecUrl = '';
        $this->subtaskChecklistText = '';
        $this->subtaskWatcherIds = [];
        $this->subtaskUploadFiles = [];
        $this->pastedSubtaskFile = null;
        $this->subtaskEditorKey++;
        $this->resetErrorBag();
    }

    public function closeSubtaskModal(): void
    {
        $this->creatingSubtask = false;
        $this->convertingChecklistItemId = null;
        $this->subtaskUploadFiles = [];
        $this->pastedSubtaskFile = null;
        $this->resetErrorBag();
    }

    public function addBlocker(TaskService $tasks): void
    {
        abort_unless(auth()->user()->can('manageBlockers', $this->task), 403);

        $this->validate([
            'newBlockerId' => 'required|exists:tasks,id',
        ], [], [
            'newBlockerId' => __('Waiting on'),
        ]);

        try {
            $tasks->addBlocker(
                auth()->user(),
                $this->task,
                Task::query()->findOrFail($this->newBlockerId),
            );
            $this->newBlockerId = null;
            $this->resetErrorBag('newBlockerId');
            $this->task->load(['blockers:id,number,title,status', 'histories.changedBy']);
        } catch (ValidationException $e) {
            $this->addError('newBlockerId', collect($e->errors())->flatten()->first() ?? __('task.blocker_only_siblings'));
        }
    }

    public function removeBlocker(int $blockerId, TaskService $tasks): void
    {
        abort_unless(auth()->user()->can('manageBlockers', $this->task), 403);

        $blocker = Task::query()->findOrFail($blockerId);
        $tasks->removeBlocker(auth()->user(), $this->task, $blocker);
        $this->task->load(['blockers:id,number,title,status', 'histories.changedBy']);
    }

    /** @return \Illuminate\Support\Collection<int, Task> */
    private function siblingBlockerOptions()
    {
        if (! $this->task->isSubtask()) {
            return collect();
        }

        $taken = $this->task->blockers->pluck('id');

        return Task::query()
            ->where('parent_id', $this->task->parent_id)
            ->where('id', '!=', $this->task->id)
            ->when($taken->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $taken))
            ->orderBy('number')
            ->get(['id', 'number', 'title', 'status']);
    }

    public function saveSubtask(TaskService $tasks, TaskAttachmentService $attachments, SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('createSubtask', $this->task), 403);

        $this->validate([
            'subtaskDepartmentId' => 'required|exists:departments,id',
            'subtaskCategoryId' => 'required|exists:categories,id',
            'subtaskTitle' => 'required|string|max:120',
            'subtaskDescription' => ['required', 'string', new PlainTextLength(min: 3, max: 20000)],
            'subtaskPriority' => 'required|integer|min:1|max:10',
            'subtaskDeadline' => 'nullable|date',
            'subtaskSpecUrl' => 'nullable|url|max:500',
            'subtaskAssigneeId' => 'nullable|exists:users,id',
            'subtaskUploadFiles.*' => 'nullable|file|max:'.(int) $settings->get('attachment_max_kb', 10240),
        ], [], [
            'subtaskDepartmentId' => __('Department'),
            'subtaskCategoryId' => __('Category'),
            'subtaskTitle' => __('Title'),
            'subtaskDescription' => __('Description'),
            'subtaskPriority' => __('Priority'),
            'subtaskDeadline' => __('Deadline'),
            'subtaskSpecUrl' => __('Spec URL'),
            'subtaskAssigneeId' => __('Assignee'),
        ]);

        $checklist = array_filter(array_map('trim', explode("\n", $this->subtaskChecklistText)));
        $payload = [
            'department_id' => $this->subtaskDepartmentId,
            'assignee_id' => $this->subtaskAssigneeId,
            'category_id' => $this->subtaskCategoryId,
            'title' => $this->subtaskTitle,
            'description' => $this->subtaskDescription,
            'priority' => $this->subtaskPriority,
            'deadline' => $this->subtaskDeadline,
            'spec_url' => $this->subtaskSpecUrl ?: null,
        ];

        try {
            if ($this->convertingChecklistItemId !== null) {
                $item = TaskChecklistItem::query()
                    ->where('task_id', $this->task->id)
                    ->findOrFail($this->convertingChecklistItemId);
                $child = $tasks->createSubtaskFromChecklist(
                    auth()->user(),
                    $this->task,
                    $item,
                    $payload,
                    $checklist,
                    $this->subtaskWatcherIds,
                );
            } else {
                $child = $tasks->createSubtask(
                    auth()->user(),
                    $this->task,
                    $payload,
                    $checklist,
                    $this->subtaskWatcherIds,
                );
            }

            foreach ($this->subtaskUploadFiles as $file) {
                $attachments->store($child, auth()->user(), $file, null, false);
            }

            $this->closeSubtaskModal();
            $this->task->load(['subtasks.assignee:id,name', 'subtasks.blockers:id,number,status', 'checklistItems']);
        } catch (QueryException $e) {
            report($e);
            $this->addError('subtaskTitle', __('task.create_failed'));
        } catch (RuntimeException $e) {
            $this->addError('subtaskAssigneeId', $e->getMessage());
        }
    }

    /**
     * @param  list<int|string>  $orderedIds
     */
    public function reorderSubtasks(array $orderedIds, TaskService $tasks): void
    {
        try {
        $tasks->reorderSubtasks(auth()->user(), $this->task, array_values($orderedIds));
        } catch (ValidationException $e) {
            $this->task->load(['subtasks.assignee:id,name', 'subtasks.blockers:id,number,status']);

            return;
        }

        $this->task->load(['subtasks.assignee:id,name', 'subtasks.blockers:id,number,status']);
    }

    public function deleteChecklistItem(int $itemId, TaskService $tasks): void
    {
        $item = TaskChecklistItem::query()->where('task_id', $this->task->id)->findOrFail($itemId);
        $tasks->deleteChecklistItem($item, auth()->user());
        $this->task->load('checklistItems');
    }

    public function startEditComment(int $commentId): void
    {
        $comment = TaskComment::query()->where('task_id', $this->task->id)->findOrFail($commentId);
        abort_unless($comment->canBeEditedBy(auth()->user()), 403);
        $this->editingCommentId = $comment->id;
        $this->editCommentBody = app(TaskContentService::class)->toEditorHtml(
            $comment->body,
            $comment->body_format,
        );
    }

    public function saveCommentEdit(TaskService $tasks): void
    {
        $this->validate([
            'editCommentBody' => ['required', 'string', new PlainTextLength(min: 1, max: 20000)],
        ]);

        $comment = TaskComment::query()->where('task_id', $this->task->id)->findOrFail($this->editingCommentId);
        $tasks->updateComment($comment, auth()->user(), $this->editCommentBody);
        $this->editingCommentId = null;
        $this->editCommentBody = '';
        $this->task->load(['comments.author', 'comments.mentionedUsers']);
    }

    public function deleteComment(int $commentId, TaskService $tasks): void
    {
        $comment = TaskComment::query()->where('task_id', $this->task->id)->findOrFail($commentId);
        $tasks->deleteComment($comment, auth()->user());
        $this->task->load(['comments.author', 'comments.mentionedUsers']);
    }

    public function uploadAttachment(TaskAttachmentService $attachments, SettingsService $settings): void
    {
        $this->validate([
            'uploadFiles' => 'required|array|min:1',
            'uploadFiles.*' => 'file|max:'.(int) $settings->get('attachment_max_kb', 10240),
        ]);

        foreach ($this->uploadFiles as $file) {
            $attachments->store($this->task, auth()->user(), $file);
        }

        $this->uploadFiles = [];
        $this->task->load('attachments.uploader');
    }

    /**
     * Store a file already uploaded into $inlineAttachmentFile and return the
     * payload TipTap needs to insert an inline image or document chip.
     *
     * New-comment bodies have no comment_id yet, so the row is stored as a
     * task-level attachment (comment_id null). It still appears in the sidecar
     * list and keeps a stable view/download URL for the content HTML.
     *
     * @return array{id: int, filename: string, mime: string, isImage: bool, viewUrl: string, downloadUrl: string}
     */
    public function storeInlineAttachment(TaskAttachmentService $attachments, SettingsService $settings): array
    {
        abort_unless(auth()->user()->can('uploadAttachment', $this->task), 403);

        $this->validate([
            'inlineAttachmentFile' => 'required|file|max:'.(int) $settings->get('attachment_max_kb', 10240),
        ]);

        $attachment = $attachments->store(
            $this->task,
            auth()->user(),
            $this->inlineAttachmentFile,
        );

        $this->inlineAttachmentFile = null;

        // TipTap inserts the returned markup on the client right after this call.
        // A full Livewire re-render here races that insert (and can reject the
        // round-trip even though the file was stored). Refresh the sidecar list
        // separately via refreshAttachments().
        $this->skipRender();

        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime' => $attachment->mime,
            'isImage' => $attachment->isImage(),
            'viewUrl' => route('tasks.attachments.view', $attachment, absolute: false),
            'downloadUrl' => route('tasks.attachments.download', $attachment, absolute: false),
        ];
    }

    public function refreshAttachments(): void
    {
        // Full render refreshes the sidecar attachment list. The TipTap island is
        // wire:ignore; stale HTML in the snapshot is ignored client-side during
        // the upload pipeline (see rich-text-editor ignoreIncoming).
        $this->task->load(['attachments.uploader', 'comments.attachments']);
    }

    public function deleteAttachment(int $attachmentId, TaskAttachmentService $attachments): void
    {
        $attachment = TaskAttachment::query()
            ->where('task_id', $this->task->id)
            ->findOrFail($attachmentId);

        $attachments->delete($attachment, auth()->user());

        $this->task->load(['attachments.uploader', 'comments.attachments', 'histories.changedBy']);
    }

    /** @return list<array{id: int, name: string, email: string, label: string}> */
    public function mentionSearch(string $term): array
    {
        return app(MentionService::class)->searchMentionableUsers($term);
    }

    public function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: '?';
    }

    /** @return array{text: string, class: string} */
    public function deadlineMeta(): array
    {
        if ($this->task->deadline === null) {
            return ['text' => '—', 'class' => 'text-gray-700'];
        }

        $deadline = $this->task->deadline->timezone(config('app.timezone'));
        $formatted = $deadline->format('d.m.Y');

        if (! $this->task->status->isOpen()) {
            return ['text' => $formatted, 'class' => 'text-gray-700'];
        }

        $now = now()->timezone(config('app.timezone'));

        if ($deadline->isPast()) {
            $days = (int) $deadline->diffInDays($now);

            return [
                'text' => __(':date (overdue :count days)', ['date' => $formatted, 'count' => $days]),
                'class' => 'text-red-600 font-medium',
            ];
        }

        if ((int) $now->diffInDays($deadline) <= 3) {
            return [
                'text' => $deadline->diffForHumans(),
                'class' => 'text-amber-600 font-medium',
            ];
        }

        return ['text' => $formatted, 'class' => 'text-gray-700'];
    }
}; ?>

<div class="space-y-4" x-data="attachmentLightbox">
    <div class="flex items-center justify-between gap-4">
        <a href="{{ route('tasks.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            {{ __('Back to tasks') }}
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div class="min-w-0">
                @if ($task->parent)
                    <a href="{{ route('tasks.show', $task->parent) }}" wire:navigate
                       class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800">
                        {{ __('Part of #:number · :title', ['number' => $task->parent->number, 'title' => $task->parent->title]) }}
                    </a>
                @endif
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg font-semibold text-gray-900">
                        #{{ $task->number }} · {{ $task->title }}
                    </h1>
                    <x-status-badge :status="$task->status" />
                    @if ($task->priority >= 9)
                        <span title="{{ __('Urgent') }}">🔥</span>
                    @endif
                </div>
                @if ($task->isSubtask() && ($task->blockers->isNotEmpty() || ($canManageBlockers && $siblingOptions->isNotEmpty())))
                    <div class="mt-2 flex flex-wrap items-center gap-2" data-testid="waiting-row">
                        <span class="text-sm font-semibold leading-5" style="color:#B45309">{{ __('Waiting on') }}</span>
                        @foreach ($task->blockers as $blocker)
                            <span class="inline-flex items-center gap-1" data-testid="waiting-chip">
                                <x-waiting-chip :open="$blocker->status->isOpen()">
                                    <a href="{{ route('tasks.show', $blocker) }}" wire:navigate class="hover:underline">#{{ $blocker->number }} · {{ $blocker->title }}</a>
                                </x-waiting-chip>
                                @if ($canManageBlockers)
                                    <button type="button"
                                            wire:click.stop="removeBlocker({{ $blocker->id }})"
                                            data-testid="waiting-remove"
                                            class="text-sm font-medium text-gray-500 hover:text-red-700 px-1 py-0.5"
                                            aria-label="{{ __('Remove wait') }}">{{ __('Remove wait') }}</button>
                                @endif
                            </span>
                        @endforeach
                        @if ($canManageBlockers && $siblingOptions->isNotEmpty())
                            <select wire:model="newBlockerId"
                                    data-testid="waiting-select"
                                    class="border-gray-300 rounded-lg bg-white shadow-sm text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500"
                                    style="min-height:2.5rem;min-width:16rem;padding:0.5rem 0.75rem;line-height:1.25">
                                <option value="">{{ __('Select a sibling subtask') }}</option>
                                @foreach ($siblingOptions as $option)
                                    <option value="{{ $option->id }}">#{{ $option->number }} · {{ $option->title }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="addBlocker" data-testid="waiting-add" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ __('Add blocker') }}</button>
                            <x-input-error :messages="$errors->get('newBlockerId')" class="w-full" />
                        @endif
                    </div>
                @endif
                @if ($openSubtasksCount > 0)
                    <p class="mt-1 text-xs text-amber-700">{{ __('This task has :count open subtasks.', ['count' => $openSubtasksCount]) }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                @if ($canUpdate && ! $editing)
                    <x-action-button variant="ghost" wire:click="$set('editing', true)">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        {{ __('Edit') }}
                    </x-action-button>
                @endif
                @if ($canDeleteTask)
                    <x-action-button variant="danger" wire:click="softDeleteTask" wire:confirm="{{ __('Delete this task? It can be restored later from Administration.') }}">
                        {{ __('Delete task') }}
                    </x-action-button>
                @endif
                @if (! $pendingTransition)
                    @foreach ($transitions as $transition)
                        @php
                            $isDestructive = in_array($transition, [\App\Enums\TaskStatus::Cancelled, \App\Enums\TaskStatus::Rejected], true);
                            $isPositive = in_array($transition, [\App\Enums\TaskStatus::Completed, \App\Enums\TaskStatus::InProgress], true);
                            $variant = $isDestructive ? 'danger' : ($isPositive ? 'primary' : 'secondary');
                        @endphp
                        <x-action-button :variant="$variant" wire:click="selectTransition('{{ $transition->value }}')">
                            {{ $this->transitionLabel($transition) }}
                        </x-action-button>
                    @endforeach
                @endif
                @if ($billingItem && $billingItem->kind->canMarkPaid() && auth()->user()->can('markPaid', $billingItem) && $billingItem->state === \App\Enums\BillingState::Active)
                    <x-action-button variant="primary" wire:click="openBillingPay">{{ __('billing.mark_paid') }}</x-action-button>
                    @if ($billingItem->kind->canSkip())
                        <x-action-button variant="ghost" wire:click="$set('billingSkipOpen', true)">{{ __('billing.skip') }}</x-action-button>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if (session('billing_status'))
        <p class="text-sm text-green-700 bg-green-50 rounded-lg px-3 py-2">{{ session('billing_status') }}</p>
    @endif
    @if (session('billing_error'))
        <p class="text-sm text-red-700 bg-red-50 rounded-lg px-3 py-2">{{ session('billing_error') }}</p>
    @endif
    @if ($billingItem)
        <div class="rounded-xl bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-900">
            {{ $billingItem->title() }} · {{ $billingItem->formattedAmount() }}
            @if (auth()->user()->hasPermission(\App\Enums\Permission::ViewBilling) || auth()->user()->hasPermission(\App\Enums\Permission::ManageBilling))
                · <a href="{{ route('billing.show', $billingItem) }}" wire:navigate class="underline">{{ __('billing.open_billing') }}</a>
            @endif
        </div>
    @endif

    @if ($transitionError)
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-2 text-sm">{{ $transitionError }}</div>
    @endif

    @if ($pendingTransition)
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 space-y-3">
            <p class="text-sm font-medium text-amber-900">{{ __('Add a comment for this status change') }}</p>
            <form wire:submit="confirmTransition" class="space-y-3">
                <x-rich-text-editor
                    model="transitionComment"
                    key="task-transition-comment"
                    min-height="5rem"
                    :enable-mentions="true"
                    :enable-inline-attachments="$canUploadAttachment"
                    :inline-upload-url="$canUploadAttachment ? $inlineUploadUrl : null"
                    :placeholder="__('task.transition_comment_placeholder')"
                    :aria-label="__('Transition comment')"
                />
                <x-input-error :messages="$errors->get('transitionComment')" class="mt-1" />
                <div class="flex flex-wrap gap-2">
                    <x-action-button variant="primary" type="submit">
                        {{ __('task.confirm_transition') }}: {{ $this->transitionLabel(\App\Enums\TaskStatus::from($pendingTransition)) }}
                    </x-action-button>
                    <x-action-button variant="secondary" type="button" wire:click="cancelTransition">
                        {{ __('task.cancel_transition') }}
                    </x-action-button>
                </div>
            </form>
        </div>
    @endif

    @if ($editing)
        <x-card>
            <x-slot name="header">
                <h2 class="text-sm font-semibold text-gray-900">{{ __('Edit') }}</h2>
            </x-slot>
            <form wire:submit="saveEdit" class="space-y-4 max-w-3xl">
                <div>
                    <x-input-label :value="__('Title')" />
                    <x-text-input wire:model="editTitle" class="mt-1 w-full" maxlength="120" />
                </div>
                <div>
                    <x-input-label :value="__('Description')" />
                    <x-rich-text-editor
                        model="editDescription"
                        key="task-edit-description"
                        class="mt-1"
                        min-height="10rem"
                        :enable-mentions="true"
                        :enable-inline-attachments="$canUploadAttachment"
                        :inline-upload-url="$canUploadAttachment ? $inlineUploadUrl : null"
                        :aria-label="__('Description')"
                    />
                    <x-input-error :messages="$errors->get('editDescription')" class="mt-1" />
                </div>
                @if ($canChangePriority)
                <div>
                    <x-input-label :value="__('Priority')" />
                    <input type="range" wire:model.live="editPriority" min="1" max="10" class="mt-2 w-full accent-indigo-600" />
                    <x-priority-bar :priority="$editPriority" class="mt-2" size="lg" />
                </div>
                @endif
                <div>
                    <x-input-label :value="__('Deadline')" />
                    <x-text-input type="date" wire:model="editDeadline" class="mt-1 w-full" />
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label :value="__('Spec URL')" />
                        <x-text-input wire:model="editSpecUrl" class="mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label :value="__('Result URL')" />
                        <x-text-input wire:model="editResultUrl" class="mt-1 w-full" />
                    </div>
                </div>
                @if ($canAssign)
                    <div>
                        <x-input-label :value="__('Department')" />
                        <select wire:model.live="editAssigneeDepartmentId" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label :value="__('Assignee')" />
                        <select wire:model="editAssigneeId" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($assignees as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($editAssigneeId && (int) $editAssigneeId !== (int) $task->assignee_id)
                        <div>
                            <x-input-label :value="__('Reassignment comment')" />
                            <x-rich-text-editor
                                model="reassignComment"
                                key="task-reassign-comment"
                                class="mt-1"
                                min-height="4rem"
                                :enable-mentions="true"
                                :enable-inline-attachments="$canUploadAttachment"
                                :inline-upload-url="$canUploadAttachment ? $inlineUploadUrl : null"
                                :placeholder="__('Explain why the task is reassigned')"
                                :aria-label="__('Reassignment comment')"
                            />
                            <x-input-error :messages="$errors->get('reassignComment')" class="mt-1" />
                        </div>
                    @endif
                @endif
                <div class="flex gap-3">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="$set('editing', false)">{{ __('Cancel') }}</x-secondary-button>
                </div>
            </form>
        </x-card>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
            {{-- Left column --}}
            <div class="space-y-4 min-w-0">
                <x-card>
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Description') }}</h2>
                    </x-slot>
                    <div class="prose prose-sm max-w-none text-gray-800">
                        {!! $task->renderedDescription() !!}
                    </div>
                    @if ($canCreateSubtask && ! $task->isSubtask())
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <x-secondary-button type="button" wire:click="openSubtaskModal">{{ __('Add subtask') }}</x-secondary-button>
                        </div>
                    @endif
                </x-card>

                @unless ($task->isSubtask())
                <x-card>
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Subtasks') }}</h2>
                    </x-slot>
                    @if ($subtaskProgress !== '')
                        <x-slot name="actions">
                            <span class="text-xs text-gray-500">{{ $subtaskProgress }}</span>
                        </x-slot>
                    @endif
                    @if ($subtaskTotal > 0)
                        <div class="mb-4 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-indigo-500 rounded-full transition-all" style="width: {{ $subtaskProgressPercent }}%"></div>
                        </div>
                    @endif
                    @if ($task->subtasks->isEmpty())
                        <p class="text-sm text-gray-500 py-2 text-center">{{ __('No subtasks yet.') }}</p>
                    @else
                        <ul class="space-y-1.5"
                            @if ($canCreateSubtask && $task->subtasks->count() > 1) x-data="subtaskSort" @endif>
                            @foreach ($task->subtasks as $subtask)
                                <li wire:key="subtask-{{ $subtask->id }}"
                                    data-subtask-id="{{ $subtask->id }}"
                                    @if ($canCreateSubtask && $task->subtasks->count() > 1)
                                        class="touch-none cursor-grab active:cursor-grabbing [&_a]:cursor-grab"
                                    @endif>
                                    <div class="flex items-center gap-1.5 py-1.5 px-1 rounded-lg hover:bg-gray-50 transition-colors">
                                        @if ($canCreateSubtask && $task->subtasks->count() > 1)
                                            <button type="button"
                                                    class="shrink-0 inline-flex items-center justify-center size-7 rounded text-gray-400 hover:text-gray-600 cursor-grab active:cursor-grabbing leading-none touch-none"
                                                    aria-label="{{ __('Drag to reorder') }}"
                                                    @click.stop
                                                    tabindex="-1">
                                                <svg class="block size-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                    <circle cx="3" cy="3" r="1.15"/>
                                                    <circle cx="8" cy="3" r="1.15"/>
                                                    <circle cx="13" cy="3" r="1.15"/>
                                                    <circle cx="3" cy="8" r="1.15"/>
                                                    <circle cx="8" cy="8" r="1.15"/>
                                                    <circle cx="13" cy="8" r="1.15"/>
                                                    <circle cx="3" cy="13" r="1.15"/>
                                                    <circle cx="8" cy="13" r="1.15"/>
                                                    <circle cx="13" cy="13" r="1.15"/>
                                                </svg>
                                            </button>
                                        @endif
                                        <a href="{{ route('tasks.show', $subtask) }}" wire:navigate draggable="false"
                                           class="flex flex-1 min-w-0 flex-wrap items-center gap-x-2 gap-y-1 leading-5">
                                            <span class="shrink-0 text-sm tabular-nums text-gray-400">#{{ $subtask->number }}</span>
                                            <span class="flex-1 min-w-0 text-sm text-gray-800 truncate">{{ $subtask->title }}</span>
                                            @if (($waitingOn = $subtask->waitingOnLabel()) !== '')
                                                <x-waiting-chip>{{ $waitingOn }}</x-waiting-chip>
                                            @endif
                                            <x-status-badge :status="$subtask->status" class="shrink-0" />
                                            <span class="shrink-0 text-sm text-gray-500 max-w-[7rem] truncate">{{ $subtask->assignee?->name }}</span>
                                            <span class="shrink-0 text-sm tabular-nums text-gray-500">{{ $subtask->deadline?->timezone(config('app.timezone'))->format('d.m.Y') ?? '—' }}</span>
                                        </a>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
                @endunless

                <x-card>
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Checklist') }}</h2>
                    </x-slot>
                    @if ($checklistTotal > 0)
                        <x-slot name="actions">
                            <span class="text-xs text-gray-500">{{ $checklistDone }}/{{ $checklistTotal }}</span>
                        </x-slot>
                    @endif
                    @if ($checklistTotal > 0)
                        <div class="mb-4 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-indigo-500 rounded-full transition-all" style="width: {{ round(($checklistDone / $checklistTotal) * 100) }}%"></div>
                        </div>
                    @endif
                    <ul class="space-y-1.5">
                        @forelse ($task->checklistItems as $item)
                            <li class="flex items-start gap-2 p-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                                @if ($canToggleChecklist)
                                    <input type="checkbox" wire:click="toggleChecklist({{ $item->id }})" @checked($item->is_done)
                                           class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                @else
                                    <input type="checkbox" disabled @checked($item->is_done)
                                           class="mt-0.5 rounded border-gray-300 text-gray-400" />
                                @endif
                                <span class="flex-1 text-sm {{ $item->is_done ? 'line-through text-gray-400' : 'text-gray-800' }}">{{ $item->text }}</span>
                                @if ($canCreateSubtask && ! $task->isSubtask())
                                    <button type="button" wire:click="openSubtaskModalFromChecklist({{ $item->id }})"
                                            class="shrink-0 text-xs text-indigo-600 hover:text-indigo-800">{{ __('To subtask') }}</button>
                                @endif
                                @if ($canManageChecklist)
                                    <button type="button" wire:click="deleteChecklistItem({{ $item->id }})" class="text-xs text-red-600 hover:text-red-800">✕</button>
                                @endif
                            </li>
                        @empty
                            <p class="text-sm text-gray-500 py-2 text-center">{{ __('No checklist items yet.') }}</p>
                        @endforelse
                    </ul>
                    @if ($canManageChecklist)
                    <div class="mt-3 flex gap-2">
                        <x-text-input wire:model="newChecklistItem" class="flex-1" placeholder="{{ __('Add checklist item') }}" wire:keydown.enter="addChecklistItem" />
                        <x-secondary-button type="button" wire:click="addChecklistItem">{{ __('Add') }}</x-secondary-button>
                    </div>
                    @endif
                </x-card>

                <x-card class="flex flex-col">
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Comments') }}</h2>
                    </x-slot>
                    <div class="space-y-4 max-h-96 overflow-y-auto mb-4 pr-1">
                        @forelse ($task->comments as $comment)
                            <div class="flex gap-2.5">
                                <div class="shrink-0 w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-[10px] font-semibold">
                                    {{ $this->initials($comment->author->name) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs text-gray-500">
                                        <span class="font-medium text-gray-700">{{ $comment->author->name }}</span>
                                        · {{ $comment->created_at->format('d.m.Y H:i') }}
                                        @if ($comment->edited_at)
                                            <span class="text-gray-400">({{ __('task.edited') }})</span>
                                        @endif
                                    </p>
                                    @if ($editingCommentId === $comment->id)
                                        <form wire:submit="saveCommentEdit" class="mt-2 space-y-2">
                                            <x-rich-text-editor
                                                model="editCommentBody"
                                                key="task-edit-comment-{{ $comment->id }}"
                                                min-height="4rem"
                                                :enable-mentions="true"
                                                :enable-inline-attachments="$canUploadAttachment"
                                                :inline-upload-url="$canUploadAttachment ? $inlineUploadUrl : null"
                                                :aria-label="__('Comments')"
                                            />
                                            <x-input-error :messages="$errors->get('editCommentBody')" />
                                            <div class="flex gap-2">
                                                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                                <x-secondary-button type="button" wire:click="$set('editingCommentId', null)">{{ __('Cancel') }}</x-secondary-button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="prose prose-sm max-w-none text-gray-800 mt-1">{!! $comment->renderedBody() !!}</div>
                                        @if ($comment->attachments->isNotEmpty())
                                            <ul class="mt-2 space-y-2">
                                                @foreach ($comment->attachments as $attachment)
                                                    <x-attachment-item :attachment="$attachment" :task="$task" />
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if ($comment->canBeEditedBy(auth()->user()))
                                            <div class="mt-1 flex gap-2">
                                                <button type="button" wire:click="startEditComment({{ $comment->id }})" class="text-xs text-indigo-600 hover:text-indigo-800">{{ __('Edit') }}</button>
                                                <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}" class="text-xs text-red-600 hover:text-red-800">{{ __('Delete') }}</button>
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 text-center py-6">{{ __('No comments yet.') }}</p>
                        @endforelse
                    </div>
                    @if ($canComment)
                    <form wire:submit="addComment" class="pt-4 border-t border-gray-100 space-y-2"
                          x-data="clipboardImagePaste($wire, 'pastedCommentFile')"
                          @paste="handlePaste($event)">
                        <x-rich-text-editor
                            model="commentBody"
                            key="task-new-comment"
                            min-height="4rem"
                            :enable-mentions="true"
                            :enable-inline-attachments="$canUploadAttachment"
                            :inline-upload-url="$canUploadAttachment ? $inlineUploadUrl : null"
                            :placeholder="__('Write a comment...')"
                            :aria-label="__('Comments')"
                        />
                        <x-input-error :messages="$errors->get('commentBody')" />
                        <p class="text-xs text-gray-500">{{ __('Mention hint') }}</p>
                        @if ($canUploadAttachment)
                            <p class="text-xs text-gray-500">{{ __('Paste image hint') }}</p>
                        @endif
                        <x-input-error :messages="$errors->get('inlineAttachmentFile')" class="mt-1" />
                        @if (count($commentFiles) > 0)
                            <ul class="text-xs text-gray-600 space-y-0.5">
                                @foreach ($commentFiles as $file)
                                    <li>{{ is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : __('Attachment') }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="flex flex-wrap items-end gap-2">
                            <input type="file" wire:model="commentFiles" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" class="text-sm flex-1 min-w-[12rem]" />
                            <x-primary-button class="self-end">{{ __('Send') }}</x-primary-button>
                        </div>
                        <x-input-error :messages="$errors->get('commentFiles.*')" class="mt-1" />
                        <x-input-error :messages="$errors->get('pastedCommentFile')" class="mt-1" />
                    </form>
                    @endif
                </x-card>
            </div>

            {{-- Right sidebar --}}
            <aside class="space-y-4 lg:sticky lg:top-6 self-start">
                @php $deadline = $this->deadlineMeta(); @endphp
                <x-card>
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Meta') }}</h2>
                    </x-slot>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Status') }}</dt>
                            <dd class="mt-0.5"><x-status-badge :status="$task->status" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Priority') }}</dt>
                            <dd class="mt-0.5"><x-priority-bar :priority="$task->priority" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Assignee') }}</dt>
                            <dd class="text-sm text-gray-700">{{ $task->assignee->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Initiator') }}</dt>
                            <dd class="text-sm text-gray-700">{{ $task->initiator->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Department') }}</dt>
                            <dd class="text-sm text-gray-700">{{ $task->department->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Category') }}</dt>
                            <dd class="text-sm text-gray-700">{{ $task->category->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Deadline') }}</dt>
                            <dd class="text-sm {{ $deadline['class'] }}">{{ $deadline['text'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Created at') }}</dt>
                            <dd class="text-sm text-gray-700">{{ $task->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Spec URL') }}</dt>
                            <dd class="text-sm">
                                @if ($task->spec_url)
                                    <a href="{{ $task->spec_url }}" target="_blank" class="text-indigo-600 hover:text-indigo-800 hover:underline">{{ __('Open') }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">{{ __('Result URL') }}</dt>
                            <dd class="mt-1">
                                @if ($canEditResultUrl)
                                    <form wire:submit="saveResultUrl" class="space-y-2">
                                        <x-text-input
                                            wire:model="resultUrl"
                                            type="url"
                                            class="w-full text-sm"
                                            placeholder="https://"
                                        />
                                        <div class="flex items-center gap-2">
                                            <x-secondary-button type="submit" class="!text-xs !py-1">
                                                {{ __('Save') }}
                                            </x-secondary-button>
                                            @if ($task->result_url)
                                                <a href="{{ $task->result_url }}" target="_blank" class="text-xs text-indigo-600 hover:text-indigo-800 hover:underline">
                                                    {{ __('Open') }}
                                                </a>
                                            @endif
                                            @if ($resultUrlSaved)
                                                <span class="text-xs text-emerald-600">{{ $resultUrlSaved }}</span>
                                            @endif
                                        </div>
                                        <x-input-error :messages="$errors->get('resultUrl')" class="mt-1" />
                                    </form>
                                @else
                                    <span class="text-sm">
                                        @if ($task->result_url)
                                            <a href="{{ $task->result_url }}" target="_blank" class="text-indigo-600 hover:text-indigo-800 hover:underline">{{ __('Open') }}</a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-card>

                <x-card>
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Watchers') }}</h2>
                    </x-slot>
                    @if ($canManageWatchers && ! $editingWatchers)
                        <x-slot name="actions">
                            <x-action-button variant="ghost" wire:click="startEditWatchers">
                                {{ __('Manage watchers') }}
                            </x-action-button>
                        </x-slot>
                    @endif
                    @if ($editingWatchers)
                        <div class="space-y-3">
                            <select wire:model="watcherIds" multiple
                                    class="w-full border-gray-300 rounded-lg shadow-sm h-32 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($allUsers as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <div class="flex gap-2">
                                <x-primary-button type="button" wire:click="saveWatchers">{{ __('Save') }}</x-primary-button>
                                <x-secondary-button type="button" wire:click="cancelEditWatchers">{{ __('Cancel') }}</x-secondary-button>
                            </div>
                        </div>
                    @else
                        @if ($task->watchers->isEmpty())
                            <p class="text-sm text-gray-400">{{ __('No watchers yet.') }}</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($task->watchers as $watcher)
                                    <li class="flex items-center gap-2 text-sm text-gray-700">
                                        <span class="w-6 h-6 shrink-0 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-[10px] font-semibold">{{ $this->initials($watcher->name) }}</span>
                                        <span class="flex-1 min-w-0 truncate">{{ $watcher->name }}</span>
                                        @if ($canManageWatchers)
                                            <button type="button" wire:click="removeWatcher({{ $watcher->id }})" class="w-4 h-4 shrink-0 flex items-center justify-center text-gray-400 hover:text-red-600">✕</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </x-card>

                <x-card
                    x-data="clipboardImagePaste($wire, 'pastedTaskFile')"
                    @paste="handlePaste($event)"
                    tabindex="0"
                    class="outline-none focus:ring-2 focus:ring-indigo-200"
                >
                    <x-slot name="header">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('Attachments') }}</h2>
                    </x-slot>
                    <ul class="space-y-2 mb-3">
                        @forelse ($task->attachments->whereNull('comment_id') as $attachment)
                            <x-attachment-item :attachment="$attachment" :task="$task" />
                        @empty
                            <p class="text-sm text-gray-500">{{ __('No attachments yet.') }}</p>
                        @endforelse
                    </ul>
                    @if ($canUploadAttachment)
                        <p class="text-xs text-gray-500 mb-2">{{ __('Paste image hint') }}</p>
                        <form wire:submit="uploadAttachment" class="flex flex-col gap-2">
                            <input type="file" wire:model="uploadFiles" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" class="text-xs w-full" />
                            <x-secondary-button type="submit" wire:loading.attr="disabled" class="self-start">{{ __('Upload') }}</x-secondary-button>
                        </form>
                        <x-input-error :messages="$errors->get('uploadFiles')" class="mt-1" />
                        <x-input-error :messages="$errors->get('uploadFiles.*')" class="mt-1" />
                        <x-input-error :messages="$errors->get('pastedTaskFile')" class="mt-1" />
                    @endif
                </x-card>
            </aside>
        </div>
    @endif

    {{-- History (collapsed by default) --}}
    <x-card padding="p-0" x-data="{ open: false }">
        <button type="button" @click="open = !open"
                class="w-full flex items-center justify-between gap-2 px-5 py-4 text-left hover:bg-gray-50/80 transition-colors rounded-xl">
            <h2 class="text-sm font-semibold text-gray-900">{{ __('History') }} ({{ count($presentedHistories) }})</h2>
            <svg class="w-4 h-4 shrink-0 text-gray-500 transition-transform" :class="open ? 'rotate-180' : ''"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="open" x-collapse class="px-5 pb-5 border-t border-gray-100">
            <ul class="space-y-0 pt-4">
                @forelse ($presentedHistories as $presented)
                    @php $entry = $presented['entry']; @endphp
                    <li class="relative pl-6 pb-4 last:pb-0 border-l-2 border-indigo-100 last:border-transparent ml-2">
                        <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-indigo-400"></span>
                        <p class="text-sm text-gray-800">
                            <span class="text-gray-400 text-xs">{{ $entry->created_at->format('d.m.Y H:i') }}</span>
                            <span class="mx-1">·</span>
                            <span class="font-medium">{{ $entry->changedBy->name }}</span>
                            <span class="text-gray-500">— {{ $presented['field'] }}</span>
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $presented['old'] ?? '—' }} → {{ $presented['new'] ?? '—' }}</p>
                    </li>
                @empty
                    <li class="text-sm text-gray-500 text-center py-4">{{ __('No history yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </x-card>

    @if ($creatingSubtask)
        <div
            class="fixed inset-0 z-50 overflow-y-auto"
            wire:keydown.escape.window="closeSubtaskModal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="create-subtask-title"
        >
            <div class="fixed inset-0 bg-gray-500/75" wire:click="closeSubtaskModal"></div>
            <div class="relative mx-auto my-6 w-full max-w-5xl px-4">
                <form wire:submit="saveSubtask" class="relative bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden">
                    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                        <h2 id="create-subtask-title" class="text-sm font-semibold text-gray-900">{{ __('Add subtask') }}</h2>
                        <button type="button" wire:click="closeSubtaskModal" class="text-gray-400 hover:text-gray-700 text-lg leading-none" aria-label="{{ __('Cancel') }}">✕</button>
                    </div>

                    <div class="p-5 grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 items-start max-h-[calc(100vh-10rem)] overflow-y-auto">
                        <div class="space-y-4 min-w-0">
                            <div>
                                <x-input-label :value="__('Title')" class="text-xs text-gray-500 font-medium" />
                                <x-text-input wire:model="subtaskTitle" class="mt-1 w-full rounded-lg text-sm" maxlength="120" />
                                <x-input-error :messages="$errors->get('subtaskTitle')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label :value="__('Description')" class="text-xs text-gray-500 font-medium" />
                                <x-rich-text-editor
                                    model="subtaskDescription"
                                    key="task-subtask-description-{{ $task->id }}-{{ $subtaskEditorKey }}"
                                    class="mt-1"
                                    min-height="10rem"
                                    :placeholder="__('Description')"
                                    :aria-label="__('Description')"
                                    :enable-mentions="true"
                                    :enable-inline-attachments="true"
                                    :inline-upload-url="$pendingInlineUploadUrl"
                                />
                                <x-input-error :messages="$errors->get('subtaskDescription')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label :value="__('Checklist')" class="text-xs text-gray-500 font-medium" />
                                <textarea wire:model="subtaskChecklistText" rows="4"
                                          class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                          placeholder="{{ __('One item per line') }}"></textarea>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <x-input-label :value="__('Department')" class="text-xs text-gray-500 font-medium" />
                                <select wire:model.live="subtaskDepartmentId"
                                        class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        @if(count($subtaskDepartments) === 1) disabled @endif>
                                    @foreach ($subtaskDepartments as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('subtaskDepartmentId')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label :value="__('Assignee')" class="text-xs text-gray-500 font-medium" />
                                <select wire:model="subtaskAssigneeId" class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">{{ __('— Auto (head / queue) —') }}</option>
                                    @foreach ($subtaskUsers as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">{{ __('If not selected, task goes to department head or assign queue.') }}</p>
                                <x-input-error :messages="$errors->get('subtaskAssigneeId')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label :value="__('Category')" class="text-xs text-gray-500 font-medium" />
                                <select wire:model="subtaskCategoryId" class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">{{ __('— Select —') }}</option>
                                    @foreach ($subtaskCategories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('subtaskCategoryId')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label :value="__('Priority')" class="text-xs text-gray-500 font-medium" />
                                <div class="mt-1 flex items-center gap-2">
                                    <input type="range" wire:model.live="subtaskPriority" min="1" max="10" class="flex-1 accent-indigo-600" />
                                    <x-priority-bar :priority="$subtaskPriority" size="sm" />
                                    <span class="text-sm font-medium text-gray-700 w-5 text-center tabular-nums">{{ $subtaskPriority }}</span>
                                </div>
                            </div>

                            <div>
                                <x-input-label :value="__('Deadline')" class="text-xs text-gray-500 font-medium" />
                                <x-text-input type="date" wire:model="subtaskDeadline" class="mt-1 w-full rounded-lg text-sm" />
                            </div>

                            <div>
                                <x-input-label :value="__('Spec URL')" class="text-xs text-gray-500 font-medium" />
                                <x-text-input wire:model="subtaskSpecUrl" type="url" class="mt-1 w-full rounded-lg text-sm" placeholder="https://" />
                                <x-input-error :messages="$errors->get('subtaskSpecUrl')" class="mt-1" />
                            </div>

                            <div
                                x-data="clipboardImagePaste($wire, 'pastedSubtaskFile')"
                                @paste="handlePaste($event)"
                                tabindex="0"
                                class="outline-none focus:ring-2 focus:ring-indigo-200 rounded-lg"
                            >
                                <x-input-label :value="__('Attachments')" class="text-xs text-gray-500 font-medium" />
                                <p class="mt-1 text-xs text-gray-500">{{ __('Paste image hint') }}</p>
                                <input type="file" wire:model="subtaskUploadFiles" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" class="mt-1 text-sm w-full" />
                                @if (count($subtaskUploadFiles) > 0)
                                    <ul class="mt-1 text-xs text-gray-600 space-y-0.5">
                                        @foreach ($subtaskUploadFiles as $file)
                                            <li>{{ is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : __('Attachment') }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <x-input-error :messages="$errors->get('subtaskUploadFiles.*')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label :value="__('Watchers')" class="text-xs text-gray-500 font-medium" />
                                <select wire:model="subtaskWatcherIds" multiple
                                        class="mt-1 w-full text-sm border-gray-300 rounded-lg shadow-sm h-28 focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach ($allUsers as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-gray-100 bg-gray-50">
                        <x-secondary-button type="button" wire:click="closeSubtaskModal">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button wire:loading.attr="disabled">{{ __('Save') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($billingPayOpen && $billingItem)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <p class="text-sm">{{ __('billing.paid_confirm', ['title' => $billingItem->title(), 'amount' => $billingItem->formattedAmount()]) }}</p>
                <x-text-input wire:model="billingPayAmount" class="w-full" placeholder="{{ __('billing.other_amount') }}" />
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('billingPayOpen', false)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="primary" wire:click="confirmBillingPay">{{ __('billing.yes') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    @if ($billingSkipOpen && $billingItem)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <label class="text-sm">{{ __('billing.skip_reason') }}</label>
                <textarea wire:model="billingSkipReason" class="w-full border-gray-300 rounded-lg text-sm" rows="3"></textarea>
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('billingSkipOpen', false)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="danger" wire:click="confirmBillingSkip">{{ __('billing.skip') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    <div
        data-attachment-lightbox
        data-fallback-label="{{ __('Attachment') }}"
        hidden
        class="fixed inset-0 z-[80]"
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-label="{{ __('Attachment') }}"
    >
        <div data-attachment-lightbox-backdrop class="absolute inset-0 bg-black/80"></div>
        <button type="button"
                data-attachment-lightbox-close
                class="absolute top-4 right-4 z-10 inline-flex size-10 items-center justify-center rounded-full bg-white/10 text-white text-2xl leading-none hover:bg-white/20"
                aria-label="{{ __('Close') }}">✕</button>
        <button type="button"
                data-attachment-lightbox-prev
                hidden
                class="absolute left-3 top-1/2 z-10 -translate-y-1/2 inline-flex size-10 items-center justify-center rounded-full bg-white/10 text-white text-2xl leading-none hover:bg-white/20"
                aria-label="{{ __('Previous') }}">‹</button>
        <button type="button"
                data-attachment-lightbox-next
                hidden
                class="absolute right-3 top-1/2 z-10 -translate-y-1/2 inline-flex size-10 items-center justify-center rounded-full bg-white/10 text-white text-2xl leading-none hover:bg-white/20"
                aria-label="{{ __('Next') }}">›</button>
        <div class="relative z-10 flex h-full w-full flex-col items-center justify-center p-12 pointer-events-none">
            <img data-attachment-lightbox-image alt="" class="max-h-[80vh] max-w-[90vw] object-contain pointer-events-auto rounded-lg shadow-2xl">
            <iframe data-attachment-lightbox-pdf hidden title="" class="h-[80vh] w-full max-w-5xl pointer-events-auto rounded-lg bg-white shadow-2xl"></iframe>
            <p data-attachment-lightbox-error hidden class="max-w-md rounded-lg bg-white px-5 py-4 text-sm text-gray-800 pointer-events-auto">{{ __('Could not preview this PDF.') }}</p>
        </div>
        <div class="absolute bottom-4 left-0 right-0 z-10 px-4 text-center text-sm text-white/80">
            <p data-attachment-lightbox-counter hidden class="mb-1 tabular-nums"></p>
            <p data-attachment-lightbox-name hidden class="truncate"></p>
            <a data-attachment-lightbox-download hidden href="#" class="mt-1 inline-block text-indigo-200 hover:text-white underline">{{ __('Download') }}</a>
        </div>
    </div>
</div>

@script
<script>
    /*
     * Sidecar clipboard paste (comment file list / attachments card).
     *
     * TipTap editors with data-inline-attachments handle image paste themselves
     * (HTTP POST → tasks.attachments.inline → insert into the document). Skip
     * those targets so the same paste is not uploaded twice.
     *
     * Comment @mention autocomplete lives in the TipTap editor (enable-mentions)
     * and calls mentionSearch() on this component.
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
