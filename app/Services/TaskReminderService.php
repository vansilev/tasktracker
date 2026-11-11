<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\TaskEscalationNotification;
use App\Notifications\TaskMorningReminderNotification;
use App\Support\Deadline;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The morning run: one Telegram message per person with their deadlines, plus escalations
 * of long-overdue tasks to department heads. Task 448, stage 2.
 *
 * Every reminder is first claimed in task_reminders (unique per task, person, kind, threshold
 * and date) and only then queued, so overlapping runs or a re-run never repeat a message.
 * Delivery flips the claim to "sent"; a failed delivery flips it to "failed", and a later run
 * on the same working day takes it again, up to TaskReminder::MAX_ATTEMPTS.
 */
class TaskReminderService
{
    public const EVENT = 'task.reminder';

    /** Telegram allows 4096 characters; headings and markup need the rest. */
    private const MESSAGE_BUDGET = 3200;

    /** An escalated task takes up to ~450 characters with its last comment. */
    private const ESCALATIONS_PER_MESSAGE = 7;

    public function __construct(
        private ReminderSettings $settings,
        private WorkCalendar $calendar,
        private BillingBot $billingBot,
        private TaskNotificationService $notifications,
        private TaskVisibilityService $visibility,
    ) {}

    /**
     * @param  bool  $retryOnly  the day's main run is done: only re-send what failed or got stuck
     * @return array{people: int, lines: int, escalations: int, no_telegram: int, escalations_without_recipient: int}
     */
    public function run(?CarbonInterface $now = null, bool $dryRun = false, bool $retryOnly = false): array
    {
        $today = ($now ?? $this->calendar->now())->copy()->timezone(config('app.timezone'))->startOfDay();
        $stats = ['people' => 0, 'lines' => 0, 'escalations' => 0, 'no_telegram' => 0, 'escalations_without_recipient' => 0];
        $excluded = $this->settings->excludedUserIds();

        /** @var array<int, array{user: User, items: list<array<string, mixed>>}> $byUser */
        $byUser = [];
        $escalations = [];

        foreach ($this->candidateTasks() as $task) {
            $responsible = $this->responsibleFor($task, $today);

            if ($responsible === null || in_array($responsible->id, $excluded, true) || $this->billingBot->is($responsible)) {
                continue;
            }

            $days = Deadline::daysFromToday($task->deadline, $today);
            $due = $this->dueMarks($days, $today);

            if ($due !== null) {
                foreach ($this->recipients($task, $responsible, $excluded) as [$user, $role]) {
                    // Watchers hear about the thresholds only; the daily "overdue" is for whoever must act.
                    if ($role === TaskReminder::ROLE_WATCHER && $due['kind'] === TaskReminder::KIND_OVERDUE) {
                        continue;
                    }

                    $byUser[$user->id]['user'] = $user;
                    $byUser[$user->id]['items'][] = ['task' => $task, 'role' => $role, 'days' => $days] + $due;
                }
            }

            $overdueDays = $this->escalationOverdueDays($task, $today);

            if ($overdueDays >= $this->settings->escalationAfterDays()) {
                $escalations[] = ['task' => $task, 'responsible' => $responsible, 'days_overdue' => $overdueDays];
            }
        }

        foreach ($byUser as $entry) {
            $user = $entry['user'];

            if (! $this->telegramAllowed($user)) {
                // Not linked, or switched off by the person: nothing is claimed, so a later link catches up.
                $stats['no_telegram']++;

                continue;
            }

            $items = array_values(array_filter(
                $entry['items'],
                fn (array $item) => $this->claim(
                    $item['task'], $user, $item['kind'], $item['marks'], $item['ref_date'], $item['role'], $dryRun, $retryOnly,
                ),
            ));

            if ($items === []) {
                continue;
            }

            $stats['people']++;
            $stats['lines'] += count($items);

            if (! $dryRun) {
                foreach ($this->split($items) as $part) {
                    $user->notify(new TaskMorningReminderNotification($today->format('Y-m-d'), $part));
                }
            }
        }

        /** @var array<string, array{user: User, role: string, items: list<array<string, mixed>>}> $inbox */
        $inbox = [];

        foreach ($escalations as $escalation) {
            $told = $this->escalate($escalation['task'], $escalation['responsible'], $escalation['days_overdue'], $today, $excluded, $dryRun, $retryOnly, $inbox);

            if ($told !== null) {
                $stats[$told > 0 ? 'escalations' : 'escalations_without_recipient']++;
            }
        }

        // One message per recipient, most overdue first, however many tasks were escalated.
        foreach ($inbox as $entry) {
            usort($entry['items'], fn (array $a, array $b) => $b['days_overdue'] <=> $a['days_overdue']);

            foreach (array_chunk($entry['items'], self::ESCALATIONS_PER_MESSAGE) as $items) {
                $entry['user']->notify(new TaskEscalationNotification($today->format('Y-m-d'), $entry['role'], $items));
            }
        }

        return $stats;
    }

    /** Whom the task is waiting on right now, or null when nobody should be nudged. */
    public function responsibleFor(Task $task, CarbonInterface $today): ?User
    {
        return match ($task->status) {
            TaskStatus::New, TaskStatus::InProgress, TaskStatus::Rework => $task->assignee,
            TaskStatus::OnReview, TaskStatus::AwaitingInitiator => $task->initiator,
            // Postponed tasks sleep until their return date; tasks postponed before that date existed stay asleep.
            TaskStatus::Postponed => $task->postponed_until !== null
                && $task->postponed_until->format('Y-m-d') <= $today->format('Y-m-d')
                    ? $task->assignee
                    : null,
            default => null,
        };
    }

    /**
     * Whether this person may get reminders in Telegram. Deliberately independent of
     * TELEGRAM_DM_ENABLED: reminders are personal even while other notifications go to the group.
     */
    public function telegramAllowed(User $user): bool
    {
        return filled($user->telegram_chat_id)
            && $this->settings->allowsPersonalMessages($user->id)
            && $this->notifications->isChannelEnabled($user, self::EVENT, 'telegram');
    }

    /** @return Collection<int, Task> */
    private function candidateTasks(): Collection
    {
        return Task::query()
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))
            ->whereNotNull('deadline')
            ->with(['assignee.department.head', 'initiator.department.head', 'watchers'])
            ->orderBy('deadline')
            ->orderBy('id')
            ->get();
    }

    /** @param  list<int>  $excluded */
    private function canBeReminded(?User $user, array $excluded): bool
    {
        return $user !== null
            && $user->is_active
            && ! in_array($user->id, $excluded, true)
            && ! $this->billingBot->is($user);
    }

    /**
     * The responsible person first, then watchers who are not that person and can still see the task.
     *
     * @param  list<int>  $excluded
     * @return list<array{0: User, 1: string}>
     */
    private function recipients(Task $task, User $responsible, array $excluded): array
    {
        $list = [];

        if ($this->canBeReminded($responsible, $excluded)) {
            $list[] = [$responsible, TaskReminder::ROLE_RESPONSIBLE];
        }

        foreach ($task->watchers as $watcher) {
            if (
                $watcher->id !== $responsible->id
                && $this->canBeReminded($watcher, $excluded)
                && $this->visibility->canView($watcher, $task)
            ) {
                $list[] = [$watcher, TaskReminder::ROLE_WATCHER];
            }
        }

        return $list;
    }

    /**
     * What is due for a task this morning, or null when nothing is.
     *
     * Before the deadline: every threshold whose send day (moved back off a weekend) has come.
     * They go out as one line, so a Monday deadline or a missed day never yields two messages.
     * After the deadline: one "overdue" line per working day.
     *
     * @return array{kind: string, marks: list<int>, ref_date: string, deadline_date: string}|null
     */
    private function dueMarks(int $days, CarbonInterface $today): ?array
    {
        $deadlineDay = $today->copy()->addDays($days);
        $deadlineDate = $deadlineDay->format('Y-m-d');

        if ($days < 0) {
            return [
                'kind' => TaskReminder::KIND_OVERDUE,
                'marks' => [-1],
                'ref_date' => $today->format('Y-m-d'),
                'deadline_date' => $deadlineDate,
            ];
        }

        $marks = [];

        foreach ($this->settings->thresholdDays() as $threshold) {
            if ($this->calendar->workDayOnOrBefore($deadlineDay->copy()->subDays($threshold))->lte($today)) {
                $marks[] = $threshold;
            }
        }

        return $marks === []
            ? null
            : ['kind' => TaskReminder::KIND_THRESHOLD, 'marks' => $marks, 'ref_date' => $deadlineDate, 'deadline_date' => $deadlineDate];
    }

    /**
     * Takes the reminder for this person. True = it has to be sent now.
     *
     * New: inserted as pending. Already sent or freshly pending: left alone. Failed, or pending for
     * too long: taken again while attempts remain. The unique index and the conditional update
     * make two overlapping runs agree on a single winner.
     *
     * @param  list<int>  $marks
     */
    private function claim(
        Task $task,
        User $user,
        string $kind,
        array $marks,
        string $refDate,
        string $role,
        bool $dryRun,
        bool $retryOnly,
        ?string $deadlineDate = null,
    ): bool {
        $deadlineDate ??= $task->deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d');

        $existing = TaskReminder::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->where('kind', $kind)
            ->whereDate('ref_date', $refDate)
            ->whereIn('mark', $marks)
            ->get();

        $staleBefore = now()->subMinutes(TaskReminder::STALE_MINUTES);
        $retryable = $existing->filter(fn (TaskReminder $row) => $row->attempts < TaskReminder::MAX_ATTEMPTS && (
            $row->status === TaskReminder::STATUS_FAILED
            || ($row->status === TaskReminder::STATUS_PENDING && ($row->claimed_at === null || $staleBefore->gt($row->claimed_at)))
        ));
        $missing = array_values(array_diff($marks, $existing->pluck('mark')->map(fn ($mark) => (int) $mark)->all()));

        // A line is due when at least one of its marks has never been claimed (or has to be retried):
        // a Saturday deadline gets "in 3 days" on Wednesday and then "tomorrow" on Friday.
        $hasNew = $missing !== [] && ! $retryOnly;

        if ($dryRun) {
            return $hasNew || $retryable->isNotEmpty();
        }

        $taken = false;

        if ($retryable->isNotEmpty()) {
            $taken = DB::table('task_reminders')
                ->whereIn('id', $retryable->pluck('id'))
                ->where('attempts', '<', TaskReminder::MAX_ATTEMPTS)
                ->where(fn ($q) => $q
                    ->where('status', TaskReminder::STATUS_FAILED)
                    ->orWhere(fn ($q) => $q
                        ->where('status', TaskReminder::STATUS_PENDING)
                        ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', $staleBefore))))
                ->update([
                    'status' => TaskReminder::STATUS_PENDING,
                    'attempts' => DB::raw('attempts + 1'),
                    'claimed_at' => now(),
                ]) > 0;
        }

        if ($missing !== [] && ($hasNew || $taken)) {
            $inserted = DB::table('task_reminders')->insertOrIgnore(array_map(fn (int $mark) => [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'kind' => $kind,
                'mark' => $mark,
                'ref_date' => $refDate,
                'deadline_date' => $deadlineDate,
                'role' => $role,
                'status' => TaskReminder::STATUS_PENDING,
                'attempts' => 1,
                'claimed_at' => now(),
                'created_at' => now(),
            ], $missing));

            $taken = $taken || $inserted > 0;
        }

        return $taken;
    }

    /**
     * Plain rows for the queued notification, cut on line borders into parts that fit a Telegram message.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<list<array<string, mixed>>>
     */
    private function split(array $items): array
    {
        $parts = [[]];
        $size = 0;

        foreach ($items as $item) {
            /** @var Task $task */
            $task = $item['task'];
            $row = [
                'task_id' => $task->id,
                'number' => $task->number,
                'title' => (string) ($task->title ?: $task->plainDescription()),
                'status' => $task->status->value,
                'deadline' => Deadline::format($task->deadline),
                'deadline_date' => $task->deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d'),
                'assignee' => (string) ($task->assignee?->name ?? ''),
                'days' => $item['days'],
                'watcher' => $item['role'] === TaskReminder::ROLE_WATCHER,
                // Who the person is to the task decides the wording of the line.
                'role' => match (true) {
                    $item['role'] === TaskReminder::ROLE_WATCHER => 'watcher',
                    in_array($task->status, [TaskStatus::OnReview, TaskStatus::AwaitingInitiator], true) => 'initiator',
                    default => 'assignee',
                },
                'responsible' => (string) ($this->responsibleFor($task, now())?->name ?? ''),
                'priority' => max(1, min(10, (int) $task->priority)),
                'kind' => $item['kind'],
                'marks' => $item['marks'],
                'ref_date' => $item['ref_date'],
            ];
            $length = min(mb_strlen($row['title']), 120) + 330;

            if ($size + $length > self::MESSAGE_BUDGET && $parts[array_key_last($parts)] !== []) {
                $parts[] = [];
                $size = 0;
            }

            $parts[array_key_last($parts)][] = $row;
            $size += $length;
        }

        return $parts;
    }

    /**
     * Calendar days the task has been overdue for escalation purposes. A task that came back from
     * "postponed" is counted from its return date, so the head is not written to on that very day.
     */
    private function escalationOverdueDays(Task $task, CarbonInterface $today): int
    {
        $overdue = -Deadline::daysFromToday($task->deadline, $today);

        if ($task->postponed_until !== null) {
            $overdue = min($overdue, -Deadline::daysFromToday($task->postponed_until->format('Y-m-d'), $today));
        }

        return $overdue;
    }

    /**
     * @param  list<int>  $excluded
     * @return int|null null = not due today; otherwise how many people were told (0 = nobody to tell)
     */
    private function escalate(
        Task $task,
        User $responsible,
        int $daysOverdue,
        CarbonInterface $today,
        array $excluded,
        bool $dryRun,
        bool $retryOnly,
        array &$inbox,
    ): ?int {
        $deadlineDate = $task->deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d');
        $todayDate = $today->format('Y-m-d');

        // Only escalations about the current deadline count: a moved deadline starts afresh.
        $last = TaskReminder::query()
            ->where('task_id', $task->id)
            ->where('kind', TaskReminder::KIND_ESCALATION)
            ->whereDate('deadline_date', $deadlineDate)
            ->whereDate('ref_date', '<', $todayDate)
            ->where('status', '!=', TaskReminder::STATUS_FAILED)
            ->max('ref_date');

        if ($last !== null) {
            $sinceLast = (int) round((strtotime($todayDate.' UTC') - strtotime(substr((string) $last, 0, 10).' UTC')) / 86400);

            if ($sinceLast < $this->settings->escalationRepeatDays()) {
                return null;
            }
        }

        $recipients = $this->escalationRecipients($responsible, $excluded);

        if ($recipients === []) {
            // Nobody to write to: reported once per day by the main run, never claimed.
            return $retryOnly ? null : 0;
        }

        $told = 0;

        foreach ($recipients as [$user, $role]) {
            if (! $this->claim($task, $user, TaskReminder::KIND_ESCALATION, [-1], $todayDate, $role, $dryRun, $retryOnly, $deadlineDate)) {
                continue;
            }

            $told++;

            if (! $dryRun) {
                $key = $user->id.':'.$role;
                $inbox[$key] ??= ['user' => $user, 'role' => $role, 'items' => []];
                $inbox[$key]['items'][] = $this->escalationItem($task, $responsible, $daysOverdue, $deadlineDate);
            }
        }

        return $told > 0 ? $told : null;
    }

    /**
     * Plain data for the queued message: the task as it is this morning and its last comment.
     *
     * @return array<string, mixed>
     */
    private function escalationItem(Task $task, User $responsible, int $daysOverdue, string $deadlineDate): array
    {
        $comment = $task->comments()->with('author:id,name')->first();

        return [
            'task_id' => $task->id,
            'number' => $task->number,
            'title' => (string) ($task->title ?: $task->plainDescription()),
            'status' => $task->status->value,
            'deadline_date' => $deadlineDate,
            'days_overdue' => $daysOverdue,
            'priority' => max(1, min(10, (int) $task->priority)),
            'responsible' => $responsible->name,
            'responsible_id' => $responsible->id,
            'comment' => $comment === null ? null : [
                'author' => (string) ($comment->author?->name ?? '—'),
                'date' => (string) $comment->created_at?->timezone(config('app.timezone'))->format('d.m'),
                'excerpt' => mb_strimwidth($this->notifications->commentExcerpt($comment), 0, 300, '…'),
            ],
        ];
    }

    /**
     * The head of the department of whoever the task hangs on — not of the task's department.
     * Falls back to the digest recipients when there is no head to write to: the person is the
     * head, the head is on the "no escalations" list, is inactive or has no Telegram, or the
     * department has none. The person the task hangs on never gets their own escalation.
     *
     * @param  list<int>  $excluded
     * @return list<array{0: User, 1: string}>
     */
    private function escalationRecipients(User $responsible, array $excluded): array
    {
        $head = $responsible->department?->head;

        if (
            $head !== null
            && $head->id !== $responsible->id
            && $this->canBeReminded($head, $excluded)
            && ! in_array($head->id, $this->settings->noEscalationHeadIds(), true)
            && $this->telegramAllowed($head)
        ) {
            return [[$head, TaskReminder::ROLE_HEAD]];
        }

        $ids = array_values(array_diff($this->settings->digestRecipientIds(), [$responsible->id]));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $this->telegramAllowed($user))
            ->map(fn (User $user) => [$user, TaskReminder::ROLE_DIGEST])
            ->values()
            ->all();
    }
}
