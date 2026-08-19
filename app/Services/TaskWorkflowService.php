<?php

namespace App\Services;

use App\Enums\ContentFormat;
use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class TaskWorkflowService
{
    public function __construct(
        private TaskVisibilityService $visibility,
        private SettingsService $settings,
        private TaskNotificationService $notifications,
        private AuditLogService $audit,
        private TaskContentService $content,
    ) {}

    /** @return list<TaskStatus> */
    public function allowedTransitions(User $user, Task $task): array
    {
        return $this->filterByTransitionPermission($user, $task, $this->computeTransitions($user, $task));
    }

    /** @return list<TaskStatus> */
    public function computeTransitions(User $user, Task $task): array
    {
        if (! $this->visibility->canView($user, $task)) {
            return [];
        }

        $status = $task->status;
        $isInitiator = $task->initiator_id === $user->id;
        $isAssignee = $task->assignee_id === $user->id;
        $isHead = $this->isDepartmentHead($user, $task);
        $isAdmin = $user->isAdmin();

        if ($status === TaskStatus::Completed) {
            $transitions = [];

            if (
                $isInitiator
                && $task->completed_at
                && $task->completed_at->gte(now()->subDays(30))
            ) {
                $transitions[] = TaskStatus::InProgress;
            }

            if ($isAdmin) {
                $transitions[] = TaskStatus::InProgress;
            }

            return array_values(array_unique($transitions, SORT_REGULAR));
        }

        if (! $status->isOpen()) {
            return [];
        }

        $assigneeTransitions = $this->transitionsForAssignee($status, $isAssignee, $isHead);
        $initiatorTransitions = $this->transitionsForInitiator($status, $isInitiator, $isAssignee);

        $transitions = array_merge($assigneeTransitions, $initiatorTransitions);

        if ($isInitiator) {
            $transitions[] = TaskStatus::Cancelled;
        }

        if ($isAdmin) {
            $transitions[] = TaskStatus::Cancelled;
        }

        return array_values(array_unique($transitions, SORT_REGULAR));
    }

    /**
     * @param  ContentSource  $commentSource  Editor markup is sanitized; literal text is escaped.
     */
    public function transition(
        Task $task,
        User $user,
        TaskStatus $to,
        ?string $comment = null,
        ContentSource $commentSource = ContentSource::Editor,
    ): void {
        if (! Gate::forUser($user)->allows('transitionTo', [$task, $to])) {
            throw new AuthorizationException;
        }

        $allowed = $this->allowedTransitions($user, $task);

        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(__('task.transition_not_allowed'));
        }

        $from = $task->status;

        if (TaskStatus::requiresComment($to, $from) && blank($comment)) {
            throw new InvalidArgumentException(__('task.comment_required'));
        }

        $reasonExcerpt = null;

        DB::transaction(function () use ($task, $user, $to, $from, $comment, $commentSource, &$reasonExcerpt) {
            $updates = ['status' => $to];

            if ($to === TaskStatus::Completed) {
                $updates['completed_at'] = now();
                $updates['closed_by'] = $user->id;
            }

            if ($from === TaskStatus::Completed && $to === TaskStatus::InProgress) {
                $updates['completed_at'] = null;
                $updates['closed_by'] = null;
            }

            if ($to === TaskStatus::Rework) {
                $updates['rework_count'] = $task->rework_count + 1;
            }

            if ($to === TaskStatus::OnReview) {
                $updates['review_due_at'] = now()->addDays(
                    (int) $this->settings->get('sla_review_days', 3)
                );
            }

            $task->update($updates);

            if (filled($comment)) {
                $statusComment = $task->comments()->make([
                    'author_id' => $user->id,
                    'body' => $this->content->fromSource(trim($comment), $commentSource),
                ]);
                // body_format is not mass-assignable.
                $statusComment->body_format = ContentFormat::Html;
                $statusComment->save();
                $reasonExcerpt = $this->notifications->commentExcerpt($statusComment);
            }

            $this->logHistory($task, 'status', $from->value, $to->value, $user);

            $this->audit->log('task.status_changed', $user, $task, [
                'status' => $from->value,
            ], [
                'status' => $to->value,
            ]);
        });

        $this->notifications->notifyStatusChanged($task->fresh(), $user, $from, $to, $reasonExcerpt);
    }

    public function logHistory(Task $task, string $field, ?string $old, ?string $new, User $user): void
    {
        if ($old === $new) {
            return;
        }

        TaskHistory::create([
            'task_id' => $task->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'changed_by' => $user->id,
        ]);
    }

    private function isDepartmentHead(User $user, Task $task): bool
    {
        return $user->headedDepartments()
            ->where('id', $task->department_id)
            ->exists();
    }

    /** @return list<TaskStatus> */
    private function transitionsForAssignee(TaskStatus $status, bool $isAssignee, bool $isHead): array
    {
        return match ($status) {
            TaskStatus::New => array_filter([
                $isAssignee ? TaskStatus::InProgress : null,
                ($isAssignee || $isHead) ? TaskStatus::Rejected : null,
            ]),
            TaskStatus::InProgress => array_filter([
                $isAssignee ? TaskStatus::OnReview : null,
                $isAssignee ? TaskStatus::AwaitingInitiator : null,
                $isAssignee ? TaskStatus::Postponed : null,
            ]),
            TaskStatus::Rework => $isAssignee ? [TaskStatus::InProgress] : [],
            TaskStatus::Postponed => $isAssignee ? [TaskStatus::InProgress] : [],
            default => [],
        };
    }

    /** @return list<TaskStatus> */
    private function transitionsForInitiator(TaskStatus $status, bool $isInitiator, bool $isAssignee): array
    {
        if (! $isInitiator) {
            return [];
        }

        $initiatorOnly = match ($status) {
            TaskStatus::AwaitingInitiator => [TaskStatus::InProgress],
            TaskStatus::OnReview => [TaskStatus::Completed, TaskStatus::Rework],
            TaskStatus::InProgress => [TaskStatus::Postponed],
            TaskStatus::Postponed => [TaskStatus::InProgress],
            default => [],
        };

        if ($isAssignee) {
            return $initiatorOnly;
        }

        return array_merge($initiatorOnly, $this->transitionsForAssignee($status, true, false));
    }

    /** @param  list<TaskStatus>  $transitions */
    private function filterByTransitionPermission(User $user, Task $task, array $transitions): array
    {
        return array_values(array_filter(
            $transitions,
            fn (TaskStatus $to) => Gate::forUser($user)->allows('transitionTo', [$task, $to]),
        ));
    }
}
