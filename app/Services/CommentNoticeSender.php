<?php

namespace App\Services;

use App\Models\CommentNotice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\CommentNoticeFormat;
use App\Support\TelegramTaskCard;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends what CommentNoticeService has booked: comments and mentions, follow-ups to unanswered
 * mentions, and the "no answer" note to the author. Runs every minute from the scheduler.
 *
 * Nothing is sent from a copy: right before sending, the comment, the task and the person's
 * rights are read again, so a deleted comment or a revoked access never reaches Telegram.
 * Every message is claimed first (pending → sending); a claim that got stuck is closed as
 * failed rather than repeated, because nobody knows whether Telegram has delivered it.
 */
class CommentNoticeSender
{
    /** A run must end well before the next one starts. */
    private const TIME_BUDGET_SECONDS = 40;

    private const BATCH_LIMIT = 60;

    /** More plain comments than this for one person go out as a single message. */
    private const SEPARATE_COMMENTS_LIMIT = 3;

    /** A comment written just before the end of the working day may still go out this late. */
    private const GRACE_MINUTES = 5;

    private const STUCK_MINUTES = 5;

    private float $startedAt = 0.0;

    public function __construct(
        private CommentNoticeService $notices,
        private CommentNoticeFormat $format,
        private TelegramBotClient $bot,
        private ReminderSettings $settings,
        private WorkCalendar $calendar,
        private TaskVisibilityService $visibility,
    ) {}

    /**
     * @return array{sent: int, skipped: int, failed: int, reminders: int, author_notes: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        $this->startedAt = microtime(true);
        $now = $now ?? $this->calendar->now();
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'reminders' => 0, 'author_notes' => 0];

        if (! $this->settings->enabled()) {
            return $stats;
        }

        $this->closeStuck();
        $this->sendDue($now, $stats);

        // Follow-ups are new messages of the bot's own making: strictly inside working hours.
        if ($this->calendar->isWithinWorkHours($now)) {
            $this->sendReminders($now, $stats);
            $this->sendAuthorNotes($now, $stats);
        }

        return $stats;
    }

    /** @param  array<string, int>  $stats */
    private function sendDue(CarbonInterface $now, array &$stats): void
    {
        $due = CommentNotice::query()
            ->where(fn ($q) => $q
                ->where('status', CommentNotice::STATUS_PENDING)
                ->orWhere(fn ($q) => $q->where('status', CommentNotice::STATUS_FAILED)->where('attempts', '<', CommentNotice::MAX_ATTEMPTS)))
            ->where('send_after', '<=', $now)
            ->with(['task.assignee', 'task.initiator', 'task.watchers', 'comment.author', 'comment.mentionedUsers:id', 'user'])
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        $inHours = $this->calendar->isWithinWorkHours($now);
        $excluded = $this->settings->excludedUserIds();

        foreach ($due->groupBy('user_id') as $ofUser) {
            $ready = collect();

            foreach ($ofUser as $notice) {
                if (! $inHours && ! $this->withinGrace($notice, $now)) {
                    // Missed its moment (the scheduler was down, the day ended): wait for the next morning.
                    $notice->update(['send_after' => $this->calendar->nextSendMoment($now)]);

                    continue;
                }

                $role = $this->currentRole($notice, $excluded);

                if ($role === null) {
                    $notice->update(['status' => CommentNotice::STATUS_SKIPPED]);
                    $stats['skipped']++;

                    continue;
                }

                $ready->push(['notice' => $notice, 'role' => $role]);
            }

            [$mentions, $comments] = $ready->partition(fn (array $item) => $item['notice']->kind === CommentNotice::KIND_MENTION);

            foreach ($mentions as $item) {
                if ($this->outOfTime()) {
                    return;
                }

                $this->deliver(collect([$item]), $stats, fn (string $locale) => [
                    $this->format->mention($item['notice'], $locale),
                    $this->mentionKeyboard($item['notice'], $locale),
                ]);
            }

            if ($comments->count() > self::SEPARATE_COMMENTS_LIMIT) {
                if ($this->outOfTime()) {
                    return;
                }

                // Held back overnight or over a weekend reads as "while you were away".
                $away = $comments->contains(fn (array $item) => $item['notice']->send_after->gt($item['notice']->created_at->copy()->addMinutes(self::GRACE_MINUTES)));
                $tasks = $comments->map(fn (array $item) => ['task_id' => $item['notice']->task_id, 'number' => $item['notice']->task->number])->unique('task_id')->values()->all();

                $this->deliver($comments->values(), $stats, fn (string $locale) => [
                    $this->format->batch($comments->values(), $away, $locale),
                    TelegramTaskCard::listKeyboard($tasks),
                ]);

                continue;
            }

            foreach ($comments as $item) {
                if ($this->outOfTime()) {
                    return;
                }

                $this->deliver(collect([$item]), $stats, fn (string $locale) => [
                    $this->format->comment($item['notice'], $item['role'], $locale),
                    [
                        [['text' => __('notification.comments.reply', [], $locale), 'callback_data' => 'p:'.$item['notice']->id, 'style' => 'primary']],
                        ...$this->linkRows($item['notice'], $locale),
                    ],
                ]);
            }
        }
    }

    /**
     * One Telegram message for one or several notices of the same person.
     *
     * @param  Collection<int, array{notice: CommentNotice, role: string}>  $items
     * @param  callable(string): array{0: string, 1: list<list<array<string, string>>>}  $build
     * @param  array<string, int>  $stats
     */
    private function deliver(Collection $items, array &$stats, callable $build): void
    {
        $ids = $items->map(fn (array $item) => $item['notice']->id)->all();
        /** @var User $user */
        $user = $items->first()['notice']->user;

        // Claim first: whoever moves the rows to "sending" is the only one to send them.
        $claimed = DB::table('comment_notices')
            ->whereIn('id', $ids)
            ->whereIn('status', [CommentNotice::STATUS_PENDING, CommentNotice::STATUS_FAILED])
            ->update(['status' => CommentNotice::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== count($ids)) {
            // Another run took some of them: give back what we took and leave it to that run.
            DB::table('comment_notices')->whereIn('id', $ids)->where('status', CommentNotice::STATUS_SENDING)
                ->where('updated_at', '>=', now()->subSeconds(5))->update(['status' => CommentNotice::STATUS_PENDING, 'attempts' => DB::raw('attempts - 1')]);

            return;
        }

        $messageId = null;

        try {
            [$text, $keyboard] = $build($user->preferredLocale());
            $messageId = $this->bot->sendMessage((string) $user->telegram_chat_id, $text, $keyboard);
        } catch (Throwable $e) {
            report($e);
        }

        $sent = $messageId !== null;

        DB::table('comment_notices')->whereIn('id', $ids)->update($sent
            ? ['status' => CommentNotice::STATUS_SENT, 'sent_at' => now(), 'telegram_message_id' => $messageId, 'updated_at' => now()]
            : ['status' => CommentNotice::STATUS_FAILED, 'updated_at' => now()]);

        $stats[$sent ? 'sent' : 'failed'] += count($ids);

        foreach ($items as $item) {
            $this->log($item['notice'], $item['notice']->kind === CommentNotice::KIND_MENTION ? 'task.mentioned' : 'task.commented', $sent);
        }
    }

    /**
     * A second message about a mention nobody has reacted to. Counted from the delivery, so a
     * person without Telegram gets none (the digest still shows the question).
     *
     * @param  array<string, int>  $stats
     */
    private function sendReminders(CarbonInterface $now, array &$stats): void
    {
        $days = $this->settings->mentionRepeatWorkDays();
        $excluded = $this->settings->excludedUserIds();

        $candidates = $this->notices->openMentions()
            ->where('status', CommentNotice::STATUS_SENT)
            ->whereNull('reminded_at')
            ->whereNotNull('sent_at')
            ->with(['task.assignee', 'task.initiator', 'task.watchers', 'comment.author', 'comment.mentionedUsers:id', 'user'])
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        foreach ($candidates as $notice) {
            if ($this->outOfTime()) {
                return;
            }

            if ($this->calendar->addWorkDays($notice->sent_at, $days)->gt($now)) {
                continue;
            }

            // At most once: the mark goes first, a message lost after it is not repeated.
            if (CommentNotice::query()->whereKey($notice->id)->whereNull('reminded_at')->whereNull('acknowledged_at')->update(['reminded_at' => now()]) !== 1) {
                continue;
            }

            if ($this->currentRole($notice, $excluded) === null || ! $notice->task->status->isOpen()) {
                continue;
            }

            $locale = $notice->user->preferredLocale();
            $waited = max($days, $this->calendar->workDaysBetween($notice->sent_at, $now));
            $sent = $this->bot->sendMessage(
                (string) $notice->user->telegram_chat_id,
                $this->format->mentionReminder($notice, $waited, $locale),
                $this->mentionKeyboard($notice, $locale),
            ) !== null;

            $stats['reminders'] += $sent ? 1 : 0;
            $this->log($notice, 'task.mention_reminder', $sent);
        }
    }

    /**
     * Tells the author that the question has had no reaction. Counted from the comment itself.
     *
     * @param  array<string, int>  $stats
     */
    private function sendAuthorNotes(CarbonInterface $now, array &$stats): void
    {
        $days = $this->settings->mentionDigestWorkDays();
        $excluded = $this->settings->excludedUserIds();

        $candidates = $this->notices->openMentions()
            ->whereNull('author_notified_at')
            ->with(['task', 'comment.author', 'comment.mentionedUsers:id', 'user', 'author'])
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        foreach ($candidates as $notice) {
            if ($this->outOfTime()) {
                return;
            }

            if ($notice->comment === null || $this->calendar->addWorkDays($notice->created_at, $days)->gt($now)) {
                continue;
            }

            if (CommentNotice::query()->whereKey($notice->id)->whereNull('author_notified_at')->whereNull('acknowledged_at')->update(['author_notified_at' => now()]) !== 1) {
                continue;
            }

            $author = $notice->author;

            if (
                $notice->task === null
                || ! $notice->task->status->isOpen()
                || $author === null
                || ! $this->notices->eligible($author, $excluded)
                || ! $this->notices->telegramAllowed($author, CommentNoticeService::EVENT_MENTION)
                || ! $this->visibility->canView($author, $notice->task)
                || ! $notice->comment->mentionedUsers->contains('id', $notice->user_id)
            ) {
                continue;
            }

            $waited = max($days, $this->calendar->workDaysBetween($notice->created_at, $now));
            $sent = $this->bot->sendMessage(
                (string) $author->telegram_chat_id,
                $this->format->unanswered($notice, $waited, $author->preferredLocale()),
            ) !== null;

            $stats['author_notes'] += $sent ? 1 : 0;
        }
    }

    /**
     * Who the person is to the task right now — or null when the message must not go out:
     * the comment or the task is gone, the person lost access or switched Telegram off, the
     * mention was answered or edited away, the person is no longer part of the task.
     *
     * @param  list<int>  $excluded
     */
    private function currentRole(CommentNotice $notice, array $excluded): ?string
    {
        $task = $notice->task;
        $user = $notice->user;

        if ($task === null || $notice->comment === null || $user === null || ! $this->notices->eligible($user, $excluded)) {
            return null;
        }

        $mention = $notice->kind === CommentNotice::KIND_MENTION;

        if (! $this->notices->telegramAllowed($user, $mention ? CommentNoticeService::EVENT_MENTION : CommentNoticeService::EVENT_COMMENT)) {
            return null;
        }

        if (! $this->visibility->canView($user, $task)) {
            return null;
        }

        if ($mention) {
            return $notice->acknowledged_at === null && $notice->comment->mentionedUsers->contains('id', $user->id)
                ? 'mentioned'
                : null;
        }

        foreach ($this->notices->participants($task) as [$participant, $role]) {
            if ($participant->id === $user->id) {
                return $role;
            }
        }

        return null;
    }

    /** @return list<list<array<string, mixed>>> */
    private function mentionKeyboard(CommentNotice $notice, string $locale): array
    {
        return [
            [
                ['text' => __('notification.comments.reply', [], $locale), 'callback_data' => 'p:'.$notice->id, 'style' => 'primary'],
                ['text' => __('notification.comments.ack', [], $locale), 'callback_data' => 'y:'.$notice->id, 'style' => 'success'],
            ],
            ...$this->linkRows($notice, $locale),
        ];
    }

    /** @return list<list<array<string, mixed>>> */
    private function linkRows(CommentNotice $notice, string $locale): array
    {
        return $notice->task === null ? [] : [TelegramTaskCard::linkRow($notice->task, $locale)];
    }

    private function withinGrace(CommentNotice $notice, CarbonInterface $now): bool
    {
        return $this->calendar->isWithinWorkHours($notice->send_after)
            && $notice->send_after->copy()->addMinutes(self::GRACE_MINUTES)->gte($now);
    }

    /** Nobody knows whether a message stuck in "sending" was delivered: it is not repeated. */
    private function closeStuck(): void
    {
        CommentNotice::query()
            ->where('status', CommentNotice::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => CommentNotice::STATUS_FAILED, 'attempts' => CommentNotice::MAX_ATTEMPTS]);
    }

    /** These messages bypass Laravel notifications, so the send log is written by hand. */
    private function log(CommentNotice $notice, string $event, bool $sent): void
    {
        try {
            NotificationDelivery::query()->create([
                'user_id' => $notice->user_id,
                'task_id' => $notice->task_id,
                'event' => $event,
                'channel' => 'telegram',
                'status' => $sent ? NotificationDelivery::STATUS_SENT : NotificationDelivery::STATUS_FAILED,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function outOfTime(): bool
    {
        return microtime(true) - $this->startedAt > self::TIME_BUDGET_SECONDS;
    }
}
