<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\TaskDigestNotification;
use App\Support\Deadline;
use App\Support\TaskDigestFormatter;
use App\Support\TelegramWidget;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collects the daily digest: every open task of the company (or of one department) seen
 * the way the person who chases deadlines needs it. Pure data — no sending, no formatting.
 * Task 448, stage 3.
 */
class TaskDigestService
{
    /** Statuses in which silence means "stuck" (the customer's list; postponed and rework are not in it). */
    private const STALE_STATUSES = [
        TaskStatus::New,
        TaskStatus::InProgress,
        TaskStatus::OnReview,
        TaskStatus::AwaitingInitiator,
    ];

    public function __construct(
        private ReminderSettings $settings,
        private WorkCalendar $calendar,
        private BillingBot $billingBot,
        private TaskReminderService $reminders,
    ) {}

    /**
     * @return array{
     *     date: string,
     *     department: ?string,
     *     totals: array{open: int, overdue: int, today: int, tomorrow: int, service_overdue: int},
     *     overdue: list<array{department: string, count: int, people: list<array{name: string, count: int, tasks: list<array<string, mixed>>}>}>,
     *     today: list<array<string, mixed>>,
     *     tomorrow: list<array<string, mixed>>,
     *     escalated: list<array<string, mixed>>,
     *     stale: list<array<string, mixed>>,
     *     no_telegram: list<array{name: string, overdue: int}>,
     *     previous: ?array{date: string, open: int, overdue: int}
     * }
     */
    public function build(?CarbonInterface $now = null, ?Department $department = null): array
    {
        $now = ($now ? Carbon::instance($now) : $this->calendar->now())->timezone(config('app.timezone'));
        $today = $now->copy()->startOfDay();
        $excluded = $this->settings->excludedUserIds();

        $rows = [];
        $serviceOverdue = 0;

        foreach ($this->openTasks() as $task) {
            $person = $this->reminders->responsibleFor($task, $today) ?? $task->assignee;

            if ($department !== null && $person?->department_id !== $department->id) {
                continue;
            }

            // The very condition of the tracker's "overdue" filter, so the two numbers can be compared.
            $overdue = $task->deadline !== null && $task->deadline->lt($now);

            if ($person !== null && (in_array($person->id, $excluded, true) || $this->billingBot->is($person))) {
                $serviceOverdue += $overdue ? 1 : 0;

                continue;
            }

            $rows[] = $this->row($task, $person, $today, $overdue);
        }

        $rows = collect($rows);
        $overdue = $rows->whereStrict('overdue', true)->sortBy([['days', 'asc'], ['number', 'asc']])->values();

        return [
            'date' => $today->format('Y-m-d'),
            'department' => $department?->name,
            'totals' => [
                'open' => $rows->count(),
                'overdue' => $overdue->count(),
                'today' => $rows->whereStrict('overdue', false)->whereStrict('days', 0)->count(),
                'tomorrow' => $rows->whereStrict('days', 1)->count(),
                'service_overdue' => $serviceOverdue,
            ],
            'overdue' => $this->byDepartmentAndPerson($overdue),
            'today' => $this->plain($rows->whereStrict('overdue', false)->whereStrict('days', 0)->sortBy('number')),
            'tomorrow' => $this->plain($rows->whereStrict('days', 1)->sortBy('number')),
            'escalated' => $this->escalatedToday($rows, $today),
            'stale' => $this->plain(
                $rows->filter(fn (array $row) => $row['stale_work_days'] >= $this->settings->staleWorkDays())
                    ->sortBy([['stale_work_days', 'desc'], ['number', 'asc']])
            ),
            'no_telegram' => $overdue
                ->filter(fn (array $row) => $row['person_id'] !== null && ! $row['person_has_telegram'])
                ->groupBy('person_id')
                ->map(fn (Collection $tasks) => ['name' => $tasks->first()['person'], 'overdue' => $tasks->count()])
                ->sortBy('name')
                ->values()
                ->all(),
            'stuck' => app(CommentNoticeService::class)
                ->stuckMentions($this->settings->mentionDigestWorkDays(), $now)
                ->filter(fn ($notice) => $department === null || $notice->user?->department_id === $department->id)
                ->map(fn ($notice) => [
                    'notice' => $notice,
                    'work_days' => $this->calendar->workDaysBetween($notice->created_at, $now),
                ])
                ->values()
                ->all(),
            'stale_threshold' => $this->settings->staleWorkDays(),
            'previous' => $this->previousSnapshot($today, $department),
        ];
    }

    /**
     * Remembers today's numbers for tomorrow's "change since yesterday". The first caller of the
     * day wins: the return value tells whether this call was the one that stored the snapshot.
     */
    public function storeSnapshot(array $digest, ?Department $department = null): bool
    {
        return DB::table('digest_snapshots')->insertOrIgnore([
            'date' => $digest['date'],
            'department_id' => $department?->id ?? 0,
            'open_count' => $digest['totals']['open'],
            'overdue_count' => $digest['totals']['overdue'],
            'created_at' => now(),
        ]) > 0;
    }

    /**
     * The scheduled digest: built once, sent to every digest recipient who has Telegram.
     * Each recipient is claimed in digest_deliveries first, so overlapping or repeated runs send
     * one digest a day; a failed or stuck delivery is taken again while attempts remain.
     *
     * @return array{recipients: int, sent_to: int, without_telegram: int, messages: int}
     */
    public function sendScheduled(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $stats = ['recipients' => 0, 'sent_to' => 0, 'without_telegram' => 0, 'messages' => 0];
        $ids = $this->settings->digestRecipientIds();
        $recipients = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('id')->get();
        $stats['recipients'] = $recipients->count();

        $digest = $this->build($now);

        if (! $dryRun) {
            $this->storeSnapshot($digest);
        }

        foreach ($recipients as $user) {
            if (! $this->reminders->telegramAllowed($user)) {
                $stats['without_telegram']++;

                continue;
            }

            if (! $this->claimDelivery($digest['date'], $user, $dryRun)) {
                continue;
            }

            $stats['sent_to']++;
            $stats['messages'] += $this->dispatch($digest, $user, scheduled: true, dryRun: $dryRun);
        }

        return $stats;
    }

    /** The digest on request (/svodka): never claimed, sent at any hour. */
    public function sendOnDemand(User $user, ?Department $department = null): int
    {
        return $this->dispatch($this->build(null, $department), $user, scheduled: false, dryRun: false, department: $department);
    }

    /** @param  array<string, mixed>  $digest */
    private function dispatch(array $digest, User $user, bool $scheduled, bool $dryRun, ?Department $department = null): int
    {
        $formatter = app(TaskDigestFormatter::class);
        $messages = $formatter->messages($digest, $user->preferredLocale());

        // One widget instead of several messages, when it fits. Its fallback is the first text
        // part: Telegram refusing a widget that fits is the rare case.
        if ($this->settings->widgetsFor($user->id)) {
            [$html, $caption] = $formatter->widget($digest, $user->preferredLocale());

            if (TelegramWidget::fits($html)) {
                if (! $dryRun) {
                    $user->notify(new TaskDigestNotification(
                        $digest['date'], $department?->id ?? 0, $messages[0], 1, 1, $scheduled, $html, TelegramWidget::caption($caption),
                    ));
                }

                return 1;
            }
        }

        if (! $dryRun) {
            foreach ($messages as $index => $text) {
                $user->notify(new TaskDigestNotification(
                    $digest['date'], $department?->id ?? 0, $text, $index + 1, count($messages), $scheduled,
                ));
            }
        }

        return count($messages);
    }

    private function claimDelivery(string $date, User $user, bool $dryRun): bool
    {
        $key = ['date' => $date, 'department_id' => 0, 'user_id' => $user->id];
        $existing = DB::table('digest_deliveries')->whereDate('date', $date)->where('department_id', 0)->where('user_id', $user->id)->first();
        $staleBefore = now()->subMinutes(TaskReminder::STALE_MINUTES);

        if ($existing === null) {
            return $dryRun || DB::table('digest_deliveries')->insertOrIgnore($key + [
                'status' => TaskReminder::STATUS_PENDING, 'attempts' => 1, 'claimed_at' => now(), 'created_at' => now(),
            ]) > 0;
        }

        $retryable = $existing->attempts < TaskReminder::MAX_ATTEMPTS && (
            $existing->status === TaskReminder::STATUS_FAILED
            || ($existing->status === TaskReminder::STATUS_PENDING && ($existing->claimed_at === null || $staleBefore->gt($existing->claimed_at)))
        );

        if (! $retryable || $dryRun) {
            return $retryable;
        }

        // The status and attempts conditions make two overlapping runs agree on one winner.
        return DB::table('digest_deliveries')
            ->where('id', $existing->id)
            ->where('status', $existing->status)
            ->where('attempts', $existing->attempts)
            ->update(['status' => TaskReminder::STATUS_PENDING, 'attempts' => $existing->attempts + 1, 'claimed_at' => now()]) > 0;
    }

    /** @return Collection<int, Task> */
    private function openTasks(): Collection
    {
        return Task::query()
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))
            ->with(['assignee.department', 'initiator.department'])
            ->withMax('comments as last_comment_at', 'created_at')
            ->withMax(['histories as last_status_at' => fn ($q) => $q->where('field', 'status')], 'created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function row(Task $task, ?User $person, CarbonInterface $today, bool $overdue): array
    {
        $stale = 0;

        if (in_array($task->status, self::STALE_STATUSES, true)) {
            $last = collect([$task->created_at, $task->last_comment_at, $task->last_status_at])
                ->filter()
                ->map(fn ($moment) => Carbon::parse($moment))
                ->max();

            $stale = $last === null ? 0 : $this->calendar->workDaysBetween($last, $today);
        }

        return [
            'task_id' => $task->id,
            'number' => $task->number,
            'title' => (string) ($task->title ?: $task->plainDescription()),
            'status' => $task->status->value,
            'deadline_date' => $task->deadline?->copy()->timezone(config('app.timezone'))->format('Y-m-d'),
            'days' => $task->deadline === null ? null : Deadline::daysFromToday($task->deadline, $today),
            'overdue' => $overdue,
            'postponed_until' => $task->postponed_until?->format('Y-m-d'),
            'person_id' => $person?->id,
            'person' => (string) ($person?->name ?? '—'),
            'person_has_telegram' => filled($person?->telegram_chat_id),
            'department' => (string) ($person?->department?->name ?? '—'),
            'stale_work_days' => $stale,
        ];
    }

    /**
     * Departments with the oldest overdue task first; inside, people the same way.
     *
     * @param  Collection<int, array<string, mixed>>  $overdue  already sorted oldest first
     * @return list<array{department: string, count: int, people: list<array{name: string, count: int, tasks: list<array<string, mixed>>}>}>
     */
    private function byDepartmentAndPerson(Collection $overdue): array
    {
        return $overdue
            ->groupBy('department')
            ->map(fn (Collection $tasks, string $department) => [
                'department' => $department,
                'count' => $tasks->count(),
                'people' => $tasks
                    ->groupBy(fn (array $row) => $row['person_id'] ?? 0)
                    ->map(fn (Collection $own) => [
                        'name' => $own->first()['person'],
                        'count' => $own->count(),
                        'tasks' => $own->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Escalations raised this morning (they go out before the digest), one entry per task.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function escalatedToday(Collection $rows, CarbonInterface $today): array
    {
        $byTask = $rows->keyBy('task_id');

        return TaskReminder::query()
            ->where('kind', TaskReminder::KIND_ESCALATION)
            ->whereDate('ref_date', $today->format('Y-m-d'))
            ->whereIn('task_id', $byTask->keys())
            ->orderBy('task_id')
            ->get()
            ->groupBy('task_id')
            ->map(function (Collection $claims, int $taskId) use ($byTask): array {
                $users = User::query()->whereIn('id', $claims->pluck('user_id'))->orderBy('name')->pluck('name');

                return $byTask[$taskId] + [
                    'recipients' => $users->all(),
                    'to_head' => $claims->contains('role', TaskReminder::ROLE_HEAD),
                ];
            })
            ->sortBy('days')
            ->values()
            ->all();
    }

    /** @return ?array{date: string, open: int, overdue: int} */
    private function previousSnapshot(CarbonInterface $today, ?Department $department): ?array
    {
        $snapshot = DB::table('digest_snapshots')
            ->where('department_id', $department?->id ?? 0)
            ->whereDate('date', '<', $today->format('Y-m-d'))
            ->orderByDesc('date')
            ->first();

        return $snapshot === null ? null : [
            'date' => substr((string) $snapshot->date, 0, 10),
            'open' => (int) $snapshot->open_count,
            'overdue' => (int) $snapshot->overdue_count,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function plain(Collection $rows): array
    {
        return $rows->values()->all();
    }
}
