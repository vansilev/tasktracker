<?php

namespace App\Services;

use App\Enums\ContentFormat;
use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TaskWorkflowService
{
    public const UNDO_TTL_SECONDS = 30;

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

        $assigneeTransitions = $this->transitionsForAssignee(
            $status,
            $isAssignee || $isAdmin || $isHead,
            $isHead || $isAdmin,
        );
        $initiatorTransitions = $this->transitionsForInitiator(
            $status,
            $isInitiator || $isAdmin,
            $isAssignee,
        );

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
     * @return array{task_id: int, from: string, to: string, snapshot: array<string, mixed>}
     */
    public function transition(
        Task $task,
        User $user,
        TaskStatus $to,
        ?string $comment = null,
        ContentSource $commentSource = ContentSource::Editor,
    ): array {
        if (! Gate::forUser($user)->allows('transitionTo', [$task, $to])) {
            throw new AuthorizationException;
        }

        $allowed = $this->allowedTransitions($user, $task);

        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(__('task.transition_not_allowed'));
        }

        $from = $task->status;
        $snapshot = $this->statusSnapshot($task);

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

        return [
            'task_id' => $task->id,
            'from' => $from->value,
            'to' => $to->value,
            'snapshot' => $snapshot,
        ];
    }

    /** @param  list<array{task_id: int, from: string, to: string, snapshot: array<string, mixed>}>  $items */
    public function issueUndoToken(User $user, array $items): string
    {
        $items = array_values(array_filter($items));
        if ($items === []) {
            return '';
        }

        $token = (string) Str::uuid();
        Cache::put($this->undoCacheKey($token), [
            'user_id' => $user->id,
            'items' => $items,
        ], now()->addSeconds(self::UNDO_TTL_SECONDS));

        return $token;
    }

    public function undoToastScript(string $message, User $user, array $items): string
    {
        $token = $this->issueUndoToken($user, $items);
        $options = ['timeout' => 5000];
        if ($token !== '') {
            $options['undo'] = [
                'event' => 'task-undo-status',
                'params' => ['token' => $token],
            ];
        }

        return 'window.uiToast('.json_encode($message).', '.json_encode($options).')';
    }

    public function undo(User $user, string $token): int
    {
        $payload = Cache::pull($this->undoCacheKey($token));
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            throw new InvalidArgumentException(__('Nothing to undo'));
        }

        $done = 0;
        foreach ($payload['items'] ?? [] as $item) {
            if (is_array($item) && $this->applyUndoItem($user, $item)) {
                $done++;
            }
        }

        return $done;
    }

    private function undoCacheKey(string $token): string
    {
        return 'task-status-undo:'.$token;
    }

    /** @return array{completed_at: ?string, closed_by: mixed, review_due_at: ?string, rework_count: int} */
    private function statusSnapshot(Task $task): array
    {
        return [
            'completed_at' => $task->completed_at?->toIso8601String(),
            'closed_by' => $task->closed_by,
            'review_due_at' => $task->review_due_at?->toIso8601String(),
            'rework_count' => (int) $task->rework_count,
        ];
    }

    /** @param  array{task_id?: int, from?: string, to?: string, snapshot?: array<string, mixed>}  $item */
    private function applyUndoItem(User $user, array $item): bool
    {
        $taskId = (int) ($item['task_id'] ?? 0);
        $from = TaskStatus::tryFrom((string) ($item['from'] ?? ''));
        $to = TaskStatus::tryFrom((string) ($item['to'] ?? ''));
        $snapshot = is_array($item['snapshot'] ?? null) ? $item['snapshot'] : [];

        if ($taskId < 1 || $from === null || $to === null) {
            return false;
        }

        $task = $this->visibility->accessibleQuery($user)->whereKey($taskId)->first();
        if ($task === null || $task->status !== $to) {
            return false;
        }

        if (! Gate::forUser($user)->allows('transition', $task)) {
            return false;
        }

        DB::transaction(function () use ($task, $user, $from, $to, $snapshot) {
            $task->update([
                'status' => $from,
                'completed_at' => filled($snapshot['completed_at'] ?? null)
                    ? Carbon::parse($snapshot['completed_at'])
                    : null,
                'closed_by' => $snapshot['closed_by'] ?? null,
                'review_due_at' => filled($snapshot['review_due_at'] ?? null)
                    ? Carbon::parse($snapshot['review_due_at'])
                    : null,
                'rework_count' => (int) ($snapshot['rework_count'] ?? $task->rework_count),
            ]);

            $this->logHistory($task, 'status', $to->value, $from->value, $user);
            $this->audit->log('task.status_changed', $user, $task, [
                'status' => $to->value,
            ], [
                'status' => $from->value,
            ]);
        });

        return true;
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
