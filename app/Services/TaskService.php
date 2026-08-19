<?php

namespace App\Services;

use App\Enums\ContentFormat;
use App\Enums\ContentSource;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private TaskAssignmentService $assignment,
        private TaskWorkflowService $workflow,
        private MentionService $mentions,
        private TaskNotificationService $notifications,
        private AuditLogService $audit,
        private TaskContentService $content,
        private PendingInlineAttachmentService $pendingAttachments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $checklistTexts
     * @param  ContentSource  $descriptionSource  Editor markup is sanitized; literal text is escaped.
     */
    public function create(
        User $initiator,
        array $data,
        array $checklistTexts = [],
        array $watcherIds = [],
        ContentSource $descriptionSource = ContentSource::Editor,
    ): Task {
        Gate::forUser($initiator)->authorize('create', Task::class);

        $department = Department::query()->findOrFail($data['department_id']);

        if (! $department->is_active) {
            throw ValidationException::withMessages([
                'department_id' => [__('task.archived_department')],
            ]);
        }

        $category = Category::query()->findOrFail($data['category_id']);

        if (! $category->is_active) {
            throw ValidationException::withMessages([
                'category_id' => [__('task.archived_category')],
            ]);
        }

        if (
            $initiator->department_id !== $department->id
            && ! $initiator->hasPermission(Permission::CreateTaskAnyDepartment)
            && ! $initiator->isAdmin()
        ) {
            throw new AuthorizationException(__('task.cannot_create_for_department'));
        }

        $assignee = $this->assignment->resolveAssignee(
            $department,
            $data['assignee_id'] ?? null,
        );

        if ($assignee->department_id !== $department->id) {
            throw ValidationException::withMessages([
                'assignee_id' => [__('task.assignee_department_mismatch')],
            ]);
        }

        $parent = $this->resolveParent($initiator, $data['parent_id'] ?? null);

        $task = DB::transaction(function () use ($initiator, $data, $assignee, $checklistTexts, $watcherIds, $category, $descriptionSource, $parent) {
            // Include soft-deleted rows: numbers stay unique and must not be reused.
            $number = (int) Task::withTrashed()->lockForUpdate()->max('number') + 1;

            // Persist the row first so pending inline files can become real
            // TaskAttachments (task_id required) before HTML sanitize runs —
            // otherwise /pending-attachments/{id}/view imgs are stripped.
            $task = new Task([
                'number' => $number,
                'initiator_id' => $initiator->id,
                'assignee_id' => $assignee->id,
                'department_initiator_id' => $initiator->department_id,
                'department_id' => $assignee->department_id,
                'category_id' => $category->id,
                'parent_id' => $parent?->id,
                'title' => $data['title'],
                'description' => '',
                'priority' => (int) $data['priority'],
                'status' => TaskStatus::New,
                'deadline' => $data['deadline'] ?? null,
                'spec_url' => $data['spec_url'] ?? null,
                'result_url' => $data['result_url'] ?? null,
            ]);
            // description_format is not mass-assignable.
            $task->description_format = ContentFormat::Html;
            $task->save();

            $description = $this->pendingAttachments->promoteReferencedInHtml(
                (string) ($data['description'] ?? ''),
                $task,
                $initiator,
            );
            $task->description = $this->content->fromSource($description, $descriptionSource);
            $task->save();

            foreach ($checklistTexts as $i => $text) {
                $text = trim($text);
                if ($text !== '') {
                    $task->checklistItems()->create([
                        'text' => $text,
                        'sort_order' => $i,
                    ]);
                }
            }

            if ($watcherIds) {
                $task->watchers()->sync($watcherIds);
            }

            $this->workflow->logHistory($task, 'status', null, TaskStatus::New->value, $initiator);

            return $task;
        });

        $this->notifications->notifyTaskCreated($task, $initiator);

        $this->audit->log('task.created', $initiator, $task, null, [
            'number' => $task->number,
            'title' => $task->title,
            'assignee_id' => $task->assignee_id,
            'department_id' => $task->department_id,
            'category_id' => $task->category_id,
            'parent_id' => $task->parent_id,
        ]);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data  title required; department/assignee/category/priority default from actor/parent
     * @param  list<string>  $checklistTexts
     * @param  list<int|string>  $extraWatcherIds
     */
    public function createSubtask(User $actor, Task $parent, array $data, array $checklistTexts = [], array $extraWatcherIds = []): Task
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => [__('task.subtask_title_required')],
            ]);
        }

        if (mb_strlen($title) > 120) {
            throw ValidationException::withMessages([
                'title' => [__('validation.max.string', ['attribute' => __('Title'), 'max' => 120])],
            ]);
        }

        $departmentId = $data['department_id'] ?? $actor->department_id;

        if ($departmentId === null || $departmentId === '') {
            throw ValidationException::withMessages([
                'assignee_id' => [__('task.assignee_without_department')],
            ]);
        }

        $assigneeId = array_key_exists('assignee_id', $data)
            ? ($data['assignee_id'] ?: null)
            : $actor->id;

        $description = (string) ($data['description'] ?? '');
        $descriptionSource = $data['description_source']
            ?? ($description !== '' ? ContentSource::Editor : ContentSource::PlainText);

        $watcherIds = collect([$parent->initiator_id, $parent->assignee_id, ...$extraWatcherIds])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === (int) $actor->id)
            ->values()
            ->all();

        return $this->create(
            $actor,
            [
                'department_id' => $departmentId,
                'category_id' => $data['category_id'] ?? $parent->category_id,
                'title' => $title,
                'description' => $description,
                'priority' => (int) ($data['priority'] ?? $parent->priority),
                'assignee_id' => $assigneeId,
                'deadline' => $data['deadline'] ?? null,
                'spec_url' => $data['spec_url'] ?? null,
                'parent_id' => $parent->id,
            ],
            $checklistTexts,
            $watcherIds,
            $descriptionSource,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $checklistTexts
     * @param  list<int|string>  $extraWatcherIds
     */
    public function createSubtaskFromChecklist(
        User $actor,
        Task $parent,
        TaskChecklistItem $item,
        array $data,
        array $checklistTexts = [],
        array $extraWatcherIds = [],
    ): Task {
        if ((int) $item->task_id !== (int) $parent->id) {
            throw ValidationException::withMessages([
                'checklist_item' => [__('task.checklist_item_not_on_parent')],
            ]);
        }

        return DB::transaction(function () use ($actor, $parent, $item, $data, $checklistTexts, $extraWatcherIds) {
            $child = $this->createSubtask($actor, $parent, $data, $checklistTexts, $extraWatcherIds);
            $item->delete();

            return $child;
        });
    }

    private function resolveParent(User $initiator, mixed $parentId): ?Task
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = Task::query()->find((int) $parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => [__('task.parent_not_found')],
            ]);
        }

        if ($parent->isSubtask()) {
            throw ValidationException::withMessages([
                'parent_id' => [__('task.parent_must_be_root')],
            ]);
        }

        Gate::forUser($initiator)->authorize('createSubtask', $parent);

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ContentSource  $descriptionSource  Editor markup is sanitized; literal text is escaped.
     */
    public function update(
        Task $task,
        User $user,
        array $data,
        ContentSource $descriptionSource = ContentSource::Editor,
    ): void {
        $hasAssign = isset($data['assignee_id']);
        $clientDepartmentId = array_key_exists('department_id', $data)
            ? (int) $data['department_id']
            : null;

        if ($hasAssign) {
            $assignee = User::query()->where('is_active', true)->findOrFail($data['assignee_id']);

            if ($assignee->department_id === null) {
                throw ValidationException::withMessages([
                    'assignee_id' => [__('task.assignee_without_department')],
                ]);
            }

            if ($clientDepartmentId !== null && $clientDepartmentId !== (int) $assignee->department_id) {
                throw ValidationException::withMessages([
                    'department_id' => [__('task.department_assignee_mismatch')],
                ]);
            }

            $data['department_id'] = $assignee->department_id;
        } elseif ($clientDepartmentId !== null) {
            $assigneeDepartmentId = (int) User::query()
                ->findOrFail($task->assignee_id)
                ->department_id;

            if ($clientDepartmentId !== $assigneeDepartmentId) {
                throw ValidationException::withMessages([
                    'department_id' => [__('task.department_assignee_mismatch')],
                ]);
            }

            unset($data['department_id']);
        }

        $editFields = ['title', 'description', 'deadline', 'spec_url', 'result_url', 'category_id'];
        $hasEditFields = collect($data)->keys()->intersect($editFields)->isNotEmpty();
        $hasPriority = array_key_exists('priority', $data);
        if ($hasEditFields) {
            Gate::forUser($user)->authorize('update', $task);
        }

        if ($hasPriority) {
            Gate::forUser($user)->authorize('changePriority', $task);
        }

        if ($hasAssign) {
            Gate::forUser($user)->authorize('assign', $task);
        }

        if (! $hasEditFields && ! $hasPriority && ! $hasAssign) {
            Gate::forUser($user)->authorize('update', $task);
        }

        if (isset($data['category_id'])) {
            $category = Category::query()->findOrFail($data['category_id']);
            if (! $category->is_active) {
                throw ValidationException::withMessages([
                    'category_id' => [__('task.archived_category')],
                ]);
            }
        }

        $assigneeChanged = false;
        $auditOld = [];
        $auditNew = [];

        DB::transaction(function () use ($task, $user, $data, $descriptionSource, &$assigneeChanged, &$auditOld, &$auditNew) {
            $updates = [];

            if (array_key_exists('title', $data) && $data['title'] !== null) {
                $old = (string) ($task->title ?? '');
                $new = (string) $data['title'];

                if ($old !== $new) {
                    $updates['title'] = $data['title'];
                    $auditOld['title'] = $old ?: null;
                    $auditNew['title'] = $new ?: null;
                    $this->workflow->logHistory($task, 'title', $old ?: null, $new ?: null, $user);
                }
            }

            $setDescriptionFormatHtml = false;

            if (array_key_exists('description', $data) && $data['description'] !== null) {
                $old = (string) ($task->description ?? '');
                $new = $this->content->fromSource((string) $data['description'], $descriptionSource);

                if ($old !== $new || $task->description_format !== ContentFormat::Html) {
                    $updates['description'] = $new;
                    $setDescriptionFormatHtml = true;
                    $auditOld['description'] = $old ?: null;
                    $auditNew['description'] = $new ?: null;
                    $this->workflow->logHistory($task, 'description', $old ?: null, $new ?: null, $user);
                }
            }

            if (array_key_exists('priority', $data) && $data['priority'] !== null) {
                $old = (string) ($task->priority ?? '');
                $new = (string) $data['priority'];

                if ($old !== $new) {
                    $updates['priority'] = $data['priority'];
                    $auditOld['priority'] = $old ?: null;
                    $auditNew['priority'] = $new ?: null;
                    $this->workflow->logHistory($task, 'priority', $old ?: null, $new ?: null, $user);
                }
            }

            if (isset($data['category_id']) && $data['category_id'] !== null) {
                $old = (string) ($task->category_id ?? '');
                $new = (string) $data['category_id'];

                if ($old !== $new) {
                    $updates['category_id'] = $data['category_id'];
                    $auditOld['category_id'] = $old ?: null;
                    $auditNew['category_id'] = $new ?: null;
                    $this->workflow->logHistory($task, 'category_id', $old ?: null, $new ?: null, $user);
                }
            }

            foreach (['spec_url', 'result_url'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $new = $data[$field] === '' ? null : $data[$field];
                $old = $task->{$field};
                $oldStr = $old === null || $old === '' ? null : (string) $old;
                $newStr = $new === null ? null : (string) $new;

                if ($oldStr !== $newStr) {
                    $updates[$field] = $new;
                    $auditOld[$field] = $oldStr;
                    $auditNew[$field] = $newStr;
                    $this->workflow->logHistory($task, $field, $oldStr, $newStr, $user);
                }
            }

            if (array_key_exists('deadline', $data)) {
                $newRaw = $data['deadline'];

                if ($newRaw === '' || $newRaw === null) {
                    if ($task->deadline !== null) {
                        $old = $task->deadline->toDateTimeString();
                        $updates['deadline'] = null;
                        $auditOld['deadline'] = $old;
                        $auditNew['deadline'] = null;
                        $this->workflow->logHistory($task, 'deadline', $old, null, $user);
                    }
                } elseif (
                    $task->deadline !== null
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $newRaw)
                    && $task->deadline->toDateString() === Carbon::parse($newRaw)->toDateString()
                ) {
                    // Same calendar date — preserve existing time, no history.
                } else {
                    $savedDeadline = Carbon::parse($newRaw);
                    $old = $task->deadline?->toDateTimeString();
                    $new = $savedDeadline->toDateTimeString();

                    if ($old !== $new) {
                        $updates['deadline'] = $savedDeadline;
                        $auditOld['deadline'] = $old;
                        $auditNew['deadline'] = $new;
                        $this->workflow->logHistory($task, 'deadline', $old, $new, $user);
                    }
                }
            }

            if (isset($data['assignee_id'])) {
                $oldAssignee = (string) $task->assignee_id;
                $newAssignee = (string) $data['assignee_id'];

                if ($oldAssignee !== $newAssignee) {
                    $updates['assignee_id'] = $data['assignee_id'];
                    $updates['department_id'] = $data['department_id'];
                    $auditOld['assignee_id'] = $oldAssignee;
                    $auditNew['assignee_id'] = $newAssignee;
                    $auditOld['department_id'] = (string) $task->department_id;
                    $auditNew['department_id'] = (string) $data['department_id'];
                    $this->workflow->logHistory($task, 'assignee_id', $oldAssignee, $newAssignee, $user);
                    $this->workflow->logHistory(
                        $task,
                        'department_id',
                        (string) $task->department_id,
                        (string) $data['department_id'],
                        $user,
                    );
                    $assigneeChanged = true;
                }
            }

            if ($updates !== [] || $setDescriptionFormatHtml) {
                $task->fill($updates);
                if ($setDescriptionFormatHtml) {
                    // description_format is not mass-assignable.
                    $task->description_format = ContentFormat::Html;
                }
                $task->save();
            }
        });

        if ($auditOld !== [] || $auditNew !== []) {
            $this->audit->log('task.updated', $user, $task->fresh(), $auditOld, $auditNew);
        }

        if ($assigneeChanged) {
            $this->notifications->notifyTaskReassigned($task->fresh(), $user);
        }
    }

    public function updateResultUrl(Task $task, User $user, ?string $url): void
    {
        Gate::forUser($user)->authorize('updateResultUrl', $task);

        $normalized = $url === '' || $url === null ? null : $url;

        if ($normalized !== null && ! filter_var($normalized, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'result_url' => [__('validation.url')],
            ]);
        }

        $old = $task->result_url;
        $oldStr = $old === null || $old === '' ? null : (string) $old;
        $newStr = $normalized;

        if ($oldStr === $newStr) {
            return;
        }

        $task->update(['result_url' => $normalized]);
        $this->workflow->logHistory($task, 'result_url', $oldStr, $newStr, $user);

        $this->audit->log('task.updated', $user, $task->fresh(), [
            'result_url' => $oldStr,
        ], [
            'result_url' => $newStr,
        ]);
    }

    /**
     * @param  ContentSource  $source  Editor markup is sanitized; literal text is escaped.
     */
    public function addComment(
        Task $task,
        User $user,
        string $body,
        ContentSource $source = ContentSource::Editor,
    ): TaskComment {
        Gate::forUser($user)->authorize('comment', $task);

        $comment = $task->comments()->make([
            'author_id' => $user->id,
            'body' => $this->content->fromSource(trim($body), $source),
        ]);
        // body_format is not mass-assignable.
        $comment->body_format = ContentFormat::Html;
        $comment->save();

        $mentioned = $this->mentions->processCommentMentions($task, $comment);

        $this->notifications->notifyComment($task, $user, $comment, $mentioned);

        return $comment;
    }

    /**
     * @param  ContentSource  $source  Editor markup is sanitized; literal text is escaped.
     */
    public function updateComment(
        TaskComment $comment,
        User $user,
        string $body,
        ContentSource $source = ContentSource::Editor,
    ): void {
        $task = $comment->task;

        if ($user->isAdmin()) {
            // Admin can always edit.
        } elseif ($comment->author_id !== $user->id) {
            throw new AuthorizationException;
        } elseif ($comment->created_at->lt(now()->subMinutes(15))) {
            throw new AuthorizationException(__('task.comment_edit_window_expired'));
        }

        Gate::forUser($user)->authorize('comment', $task);

        $comment->fill([
            'body' => $this->content->fromSource(trim($body), $source),
            'edited_at' => now(),
        ]);
        // body_format is not mass-assignable.
        $comment->body_format = ContentFormat::Html;
        $comment->save();

        $comment->mentionedUsers()->detach();
        $mentioned = $this->mentions->processCommentMentions($task, $comment->fresh());

        $this->notifications->notifyComment($task, $user, $comment->fresh(), $mentioned, isEdit: true);
    }

    public function deleteComment(TaskComment $comment, User $user): void
    {
        $task = $comment->task;

        if (! $user->isAdmin()) {
            if ($comment->author_id !== $user->id) {
                throw new AuthorizationException;
            }

            if ($comment->created_at->lt(now()->subMinutes(15))) {
                throw new AuthorizationException(__('task.comment_edit_window_expired'));
            }
        }

        Gate::forUser($user)->authorize('comment', $task);

        $comment->delete();
    }

    public function addChecklistItem(Task $task, User $user, string $text): TaskChecklistItem
    {
        Gate::forUser($user)->authorize('manageChecklist', $task);

        $maxOrder = (int) $task->checklistItems()->max('sort_order');

        return $task->checklistItems()->create([
            'text' => trim($text),
            'sort_order' => $maxOrder + 1,
        ]);
    }

    public function toggleChecklistItem(TaskChecklistItem $item, User $user): void
    {
        Gate::forUser($user)->authorize('toggleChecklist', $item->task);

        $item->update(['is_done' => ! $item->is_done]);
    }

    public function deleteChecklistItem(TaskChecklistItem $item, User $user): void
    {
        Gate::forUser($user)->authorize('manageChecklist', $item->task);

        $item->delete();
    }

    public function softDelete(Task $task, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $task);

        $this->audit->log('task.deleted', $user, $task, [
            'number' => $task->number,
            'title' => $task->title,
            'status' => $task->status->value,
            'assignee_id' => $task->assignee_id,
        ], [
            'deleted_at' => now()->toDateTimeString(),
        ]);

        $task->delete();
    }

    public function addBlocker(User $actor, Task $task, Task $blocker): void
    {
        Gate::forUser($actor)->authorize('manageBlockers', $task);

        $this->assertValidBlocker($task, $blocker);

        if ($task->blockers()->whereKey($blocker->id)->exists()) {
            throw ValidationException::withMessages([
                'blocker_id' => [__('task.blocker_already_set')],
            ]);
        }

        $task->blockers()->attach($blocker->id);

        $this->workflow->logHistory($task, 'blocker', null, '#'.$blocker->number, $actor);
    }

    public function removeBlocker(User $actor, Task $task, Task $blocker): void
    {
        Gate::forUser($actor)->authorize('manageBlockers', $task);

        $detached = $task->blockers()->detach($blocker->id);

        if ($detached === 0) {
            return;
        }

        $this->workflow->logHistory($task, 'blocker', '#'.$blocker->number, null, $actor);
    }

    private function assertValidBlocker(Task $task, Task $blocker): void
    {
        if (! $task->isSubtask() || ! $blocker->isSubtask()) {
            throw ValidationException::withMessages([
                'blocker_id' => [__('task.blocker_only_siblings')],
            ]);
        }

        if ((int) $task->id === (int) $blocker->id) {
            throw ValidationException::withMessages([
                'blocker_id' => [__('task.blocker_self')],
            ]);
        }

        if ((int) $task->parent_id !== (int) $blocker->parent_id) {
            throw ValidationException::withMessages([
                'blocker_id' => [__('task.blocker_only_siblings')],
            ]);
        }

        if ($this->blockerWouldCycle($task, $blocker)) {
            throw ValidationException::withMessages([
                'blocker_id' => [__('task.blocker_cycle')],
            ]);
        }
    }

    private function blockerWouldCycle(Task $task, Task $blocker): bool
    {
        $seen = [];
        $queue = [(int) $blocker->id];

        while ($queue !== []) {
            $id = array_shift($queue);

            if ($id === (int) $task->id) {
                return true;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $next = DB::table('task_blockers')
                ->where('task_id', $id)
                ->pluck('blocker_id');

            foreach ($next as $blockerId) {
                $queue[] = (int) $blockerId;
            }
        }

        return false;
    }

    public function restore(Task $task, User $user): void
    {
        Gate::forUser($user)->authorize('restore', $task);

        if (! $task->trashed()) {
            return;
        }

        $deletedAt = $task->deleted_at?->toDateTimeString();

        $task->restore();

        $this->audit->log('task.restored', $user, $task->fresh(), [
            'deleted_at' => $deletedAt,
        ], [
            'number' => $task->number,
            'title' => $task->title,
            'status' => $task->status->value,
        ]);
    }
}
