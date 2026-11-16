<?php

namespace App\Services;

use App\Models\CommentNotice;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is owed a comment in Telegram, and which mentions are still waiting for a reaction.
 * Task 448, stage 5. This class only keeps the books; CommentNoticeSender does the sending.
 *
 * A mention is open until the mentioned person writes a comment in the task, changes its
 * status or presses "Принял". Only mentions in comments are tracked — a mention in a task
 * description or in the reason of a status change is not.
 */
class CommentNoticeService
{
    public const EVENT_MENTION = 'task.mentioned';

    public const EVENT_COMMENT = 'task.commented';

    public function __construct(
        private ReminderSettings $settings,
        private WorkCalendar $calendar,
        private BillingBot $billingBot,
        private TaskNotificationService $notifications,
    ) {}

    /**
     * Books a new or edited comment. Mentions are booked even for people without Telegram
     * (they still count as "waiting on the person"); plain comments only for those who can
     * actually be written to.
     *
     * @param  Collection<int, User>  $mentioned
     */
    public function record(Task $task, TaskComment $comment, User $author, Collection $mentioned, bool $isEdit = false): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        $task->loadMissing(['assignee', 'initiator', 'watchers']);
        $sendAfter = $this->calendar->nextSendMoment();
        $excluded = $this->settings->excludedUserIds();

        // Mentioning yourself asks nobody anything.
        $mentioned = $mentioned
            ->filter(fn (User $user) => $user->id !== $author->id && $this->eligible($user, $excluded))
            ->unique('id')
            ->values();

        foreach ($mentioned as $user) {
            $this->insert($task, $comment, $user, $author, CommentNotice::KIND_MENTION, 'mentioned', $sendAfter, self::EVENT_MENTION);
        }

        if ($isEdit) {
            // A mention taken out by the edit is no longer owed, nor is it a question any more.
            CommentNotice::query()
                ->where('comment_id', $comment->id)
                ->where('kind', CommentNotice::KIND_MENTION)
                ->whereNotIn('user_id', $mentioned->pluck('id'))
                ->whereNull('acknowledged_at')
                ->update(['status' => CommentNotice::STATUS_SKIPPED, 'acknowledged_at' => now(), 'ack_via' => 'removed']);

            // An edit is not a new comment for the others.
            return;
        }

        if (! $this->settings->commentNoticesEnabled()) {
            return;
        }

        $mentionedIds = $mentioned->pluck('id')->all();

        foreach ($this->participants($task) as [$user, $role]) {
            if ($user->id === $author->id || in_array($user->id, $mentionedIds, true) || ! $this->eligible($user, $excluded)) {
                continue;
            }

            if (! $this->telegramAllowed($user, self::EVENT_COMMENT)) {
                continue;
            }

            $this->insert($task, $comment, $user, $author, CommentNotice::KIND_COMMENT, $role, $sendAfter, self::EVENT_COMMENT);
        }
    }

    /**
     * The person did something in the task: every question put to them there before is answered.
     * Deliberately broad, as the customer defined it — any comment or status change counts.
     */
    public function acknowledgeByActivity(Task $task, User $actor, string $via, ?TaskComment $comment = null): int
    {
        return CommentNotice::query()
            ->where('task_id', $task->id)
            ->where('user_id', $actor->id)
            ->where('kind', CommentNotice::KIND_MENTION)
            ->whereNull('acknowledged_at')
            // Not the comment that carries the mention itself, and nothing written after this action.
            ->when($comment !== null, fn ($q) => $q->where('comment_id', '!=', $comment->id)->where('created_at', '<=', $comment->created_at))
            ->update(['acknowledged_at' => now(), 'ack_via' => $via]);
    }

    /** "Принял" pressed. False when it was already closed or is not this person's. */
    public function acknowledgeByButton(CommentNotice $notice, User $user): bool
    {
        return CommentNotice::query()
            ->whereKey($notice->id)
            ->where('user_id', $user->id)
            ->where('kind', CommentNotice::KIND_MENTION)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'ack_via' => 'button']) > 0;
    }

    /**
     * Mentions nobody has reacted to for the given number of working days, oldest first —
     * the "waiting on the person" block of the digest. Counted from the comment itself, so
     * people without Telegram are in it too.
     *
     * @return Collection<int, CommentNotice>
     */
    public function stuckMentions(int $workDays, ?CarbonInterface $now = null): Collection
    {
        $now = $now ?? $this->calendar->now();

        return $this->openMentions()
            ->with(['task', 'comment', 'user.department', 'author'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (CommentNotice $notice) => $notice->task !== null
                && $notice->comment !== null
                && $notice->task->status->isOpen()
                && $notice->user?->is_active
                && $this->calendar->workDaysBetween($notice->created_at, $now) >= $workDays)
            ->values();
    }

    /** @return Builder<CommentNotice> */
    public function openMentions()
    {
        return CommentNotice::query()
            ->where('kind', CommentNotice::KIND_MENTION)
            ->whereNull('acknowledged_at');
    }

    public function telegramAllowed(User $user, string $event): bool
    {
        return filled($user->telegram_chat_id)
            && $this->settings->allowsPersonalMessages($user->id)
            && $this->notifications->isChannelEnabled($user, $event, 'telegram');
    }

    /** @param  list<int>  $excluded */
    public function eligible(User $user, array $excluded): bool
    {
        return $user->is_active
            && ! in_array($user->id, $excluded, true)
            && ! $this->billingBot->is($user);
    }

    /**
     * Assignee, initiator and watchers, each once, in that order of precedence.
     *
     * @return list<array{0: User, 1: string}>
     */
    public function participants(Task $task): array
    {
        $list = [];

        foreach ([[$task->assignee, 'assignee'], [$task->initiator, 'initiator']] as [$user, $role]) {
            if ($user !== null && ! isset($list[$user->id])) {
                $list[$user->id] = [$user, $role];
            }
        }

        foreach ($task->watchers as $watcher) {
            $list[$watcher->id] ??= [$watcher, 'watcher'];
        }

        return array_values($list);
    }

    private function insert(Task $task, TaskComment $comment, User $user, User $author, string $kind, string $role, CarbonInterface $sendAfter, string $event): void
    {
        DB::table('comment_notices')->insertOrIgnore([
            'task_id' => $task->id,
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'author_id' => $author->id,
            'kind' => $kind,
            'role' => $role,
            // Nobody to write to: the mention is still tracked, there is just no message.
            'status' => $this->telegramAllowed($user, $event) ? CommentNotice::STATUS_PENDING : CommentNotice::STATUS_SKIPPED,
            'send_after' => $sendAfter,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
