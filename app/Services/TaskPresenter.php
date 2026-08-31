<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Collection;

class TaskPresenter
{
    public function __construct(
        private TaskHistoryPresenter $history,
        private TaskWorkflowService $workflow,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(User $user): array
    {
        $user->loadMissing('department:id,name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->preferredLocale(),
            'system_type' => $user->system_type?->value,
            'department' => $this->named($user->department),
            'is_admin' => $user->isAdmin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Task $task): array
    {
        return [
            'id' => $task->id,
            'number' => $task->number,
            'url' => url('/tasks/'.$task->id),
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority,
            'deadline' => optional($task->deadline)?->toIso8601String(),
            'initiator' => $this->user($task->initiator),
            'assignee' => $this->user($task->assignee),
            'department' => $this->named($task->department),
            'category' => $this->named($task->category),
            'parent_number' => $task->parent?->number,
            'checklist' => $task->checklistProgress() ?: null,
            'subtasks' => $task->subtaskProgress() ?: null,
            'created_at' => optional($task->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Task $task, User $actor): array
    {
        $presentedHistory = $this->history->presentMany($task->histories);

        return [
            ...$this->summary($task),
            'description' => $task->plainDescription(),
            'spec_url' => $task->spec_url,
            'result_url' => $task->result_url,
            'rework_count' => $task->rework_count,
            'completed_at' => optional($task->completed_at)?->toIso8601String(),
            'closed_by' => $this->user($task->closedBy),
            'review_due_at' => optional($task->review_due_at)?->toIso8601String(),
            'watchers' => $task->watchers->map(fn (User $user) => $this->user($user))->values()->all(),
            'checklist_items' => $task->checklistItems
                ->map(fn (TaskChecklistItem $item) => [
                    'id' => $item->id,
                    'text' => $item->text,
                    'is_done' => (bool) $item->is_done,
                ])->values()->all(),
            'subtask_list' => $task->subtasks
                ->map(fn (Task $sub) => [
                    'number' => $sub->number,
                    'title' => $sub->title,
                    'status' => $sub->status->value,
                    'assignee' => $this->user($sub->assignee),
                    'deadline' => optional($sub->deadline)?->toIso8601String(),
                ])->values()->all(),
            'blockers' => $task->blockers
                ->map(fn (Task $blocker) => [
                    'number' => $blocker->number,
                    'title' => $blocker->title,
                    'status' => $blocker->status->value,
                    'is_open' => $blocker->status->isOpen(),
                ])->values()->all(),
            'comments' => $task->comments
                ->map(fn (TaskComment $comment) => $this->comment($comment))
                ->values()
                ->all(),
            'history' => $presentedHistory
                ->map(fn (array $row) => [
                    'field' => $row['field'],
                    'old' => $row['old'],
                    'new' => $row['new'],
                    'changed_by' => $this->user($row['entry']->changedBy),
                    'created_at' => optional($row['entry']->created_at)?->toIso8601String(),
                ])->values()->all(),
            'attachments' => $task->attachments
                ->map(fn (TaskAttachment $file) => [
                    'id' => $file->id,
                    'filename' => $file->filename,
                    'mime' => $file->mime,
                    'size' => $file->size,
                    'comment_id' => $file->comment_id,
                ])->values()->all(),
            'allowed_transitions' => array_map(
                fn ($status) => $status->value,
                $this->workflow->allowedTransitions($actor, $task),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(TaskComment $comment): array
    {
        $comment->loadMissing('author:id,name,email');

        return [
            'id' => $comment->id,
            'body' => $comment->body_text ?? app(HtmlContentService::class)->toPlainText($comment->body),
            'author' => $this->user($comment->author),
            'created_at' => optional($comment->created_at)?->toIso8601String(),
            'edited_at' => optional($comment->edited_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function named(Department|Category|null $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'id' => $model->id,
            'name' => $model->name,
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    public function users(Collection $users): array
    {
        return $users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department' => $this->named($user->department),
            'system_type' => $user->system_type?->value,
        ])->values()->all();
    }
}
