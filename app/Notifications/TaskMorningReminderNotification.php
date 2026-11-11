<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\ReminderSettings;
use App\Services\TaskReminderService;
use App\Services\TaskVisibilityService;
use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramTaskCard;
use App\Support\WorkCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * The morning list of one person's deadlines. Telegram only, sent as a personal message even
 * while the other personal notifications are switched off (TELEGRAM_DM_ENABLED): who gets it is
 * decided by TaskReminderService before it is queued.
 */
class TaskMorningReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $date  Y-m-d, the morning this list was built for
     * @param  list<array{task_id: int, number: int, title: string, status: string, deadline: string, deadline_date: string, days: int, assignee: string, watcher: bool, role: string, responsible: string, priority: int, kind: string, marks: list<int>, ref_date: string}>  $rows
     */
    public function __construct(
        public string $date,
        public array $rows,
    ) {}

    /** Re-sending is the morning command's job (it re-claims failed reminders), not the queue's. */
    public int $tries = 1;

    /** Nothing leaves outside working hours: a held-back message is picked up by the next run. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(WorkCalendar::class)->isWithinWorkHours();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // The pilot list is checked again here, whatever queued this notification.
        return $notifiable instanceof User
            && filled($notifiable->telegram_chat_id)
            && app(ReminderSettings::class)->allowsPersonalMessages($notifiable->id)
                ? ['telegram']
                : [];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        /** @var User $notifiable */
        return TelegramMessage::create()
            ->parseMode('HTML')
            ->options(array_filter([
                'disable_web_page_preview' => true,
                'reply_markup' => $this->keyboard($notifiable),
            ]))
            ->content($this->text($notifiable->preferredLocale()));
    }

    /**
     * One task: its action buttons. Several: their numbers, a press sends that task's card.
     * Buttons only invite; each press is checked again on the server.
     */
    private function keyboard(User $user): ?string
    {
        if (count($this->rows) > 1) {
            $rows = TelegramTaskCard::listKeyboard($this->rows);
        } else {
            $task = app(TaskVisibilityService::class)->accessibleQuery($user)->whereKey($this->rows[0]['task_id'])->first();
            $rows = $task === null ? [] : app(TelegramTaskCard::class)->keyboard($task, $user);
        }

        return $rows === [] ? null : json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * One task: urgency, the task, what to do. Several: a summary and one collapsible group per urgency.
     */
    public function text(string $locale): string
    {
        return count($this->rows) === 1
            ? $this->singleTask($this->rows[0], $locale)
            : $this->taskList($locale);
    }

    /** @param  array<string, mixed>  $row */
    private function singleTask(array $row, string $locale): string
    {
        $t = fn (string $key, array $replace = []) => __('notification.reminder.'.$key, $replace, $locale);
        $days = (int) $row['days'];
        $date = F::weekdayDate($row['deadline_date'], $locale);
        $status = F::status($row['status'], $locale);

        $head = match (true) {
            $days < 0 => '<b>'.e($t('head_overdue', ['days' => F::days($days, $locale)])).'</b> · '.e($t('was_due', ['date' => $date])),
            $days === 0 => '<b>'.e($t('head_today')).'</b> · '.e($date),
            $days === 1 => '<b>'.e($t('head_tomorrow')).'</b> · '.e($date),
            default => '<b>'.e($t('head_in_days', ['days' => F::days($days, $locale)])).'</b> · '.e($date),
        };

        $action = $this->action($row, $locale);

        $who = match ($row['role']) {
            'watcher' => [
                '👀 '.e($t('you_watcher')),
                '🎯 '.e($t('responsible_is', ['name' => $row['responsible'], 'status' => '%s'])),
            ],
            'initiator' => [
                '🙋 '.e($t('you_initiator', ['status' => '%s'])),
                filled($row['assignee']) ? '🎯 '.e($t('assignee_is', ['name' => $row['assignee']])) : null,
            ],
            default => ['🎯 '.e($t('you_assignee', ['status' => '%s']))],
        };

        return F::join([
            [
                F::urgencyEmoji($days).' '.$head,
                F::taskLink($row['task_id'], $row['number'], $row['title'], $locale),
            ],
            [
                $action === null ? null : '👉 <b>'.e($t('what')).'</b> '.e($action),
                ...array_map(fn (?string $line) => $line === null ? null : str_replace('%s', $status, $line), $who),
                F::priorityLine((int) $row['priority'], $locale),
            ],
            F::link(route('tasks.show', $row['task_id']), __('notification.open_task', [], $locale)),
        ]);
    }

    private function taskList(string $locale): string
    {
        $t = fn (string $key, array $replace = []) => __('notification.reminder.'.$key, $replace, $locale);
        $upper = fn (string $text) => mb_strtoupper($text);

        $own = array_values(array_filter($this->rows, fn (array $row) => $row['role'] !== 'watcher'));
        $watched = array_values(array_filter($this->rows, fn (array $row) => $row['role'] === 'watcher'));

        $groups = [];
        foreach ($own as $row) {
            $groups[max(-1, (int) $row['days'])][] = $row;
        }
        ksort($groups);

        $count = fn (callable $filter) => count(array_filter($own, fn (array $row) => $filter((int) $row['days'])));
        $summary = array_filter([
            ($n = $count(fn (int $d) => $d < 0)) ? '🔴 '.e($t('summary_overdue', ['count' => $n])) : null,
            ($n = $count(fn (int $d) => $d === 0)) ? '⏰ '.e($t('summary_today', ['count' => $n])) : null,
            ($n = $count(fn (int $d) => $d === 1)) ? '🟡 '.e($t('summary_tomorrow', ['count' => $n])) : null,
            ($n = $count(fn (int $d) => $d > 1)) ? '🗓 '.e($t('summary_later', ['count' => $n])) : null,
        ]);

        $blocks = [[
            '📋 <b>'.e($t('list_title', ['date' => F::weekdayDate($this->date, $locale)])).'</b>',
            implode(' · ', $summary),
        ]];

        foreach ($groups as $days => $rows) {
            $name = match (true) {
                $days < 0 => $t('group_overdue'),
                $days === 0 => $t('group_today'),
                $days === 1 => $t('group_tomorrow'),
                default => $t('group_in_days', ['days' => F::days($days, $locale)]),
            };
            $heading = F::urgencyEmoji($days).' <b>'.e($upper($name)).' · '.count($rows).'</b>'
                .($days > 0 ? ' · '.e(F::weekdayDate($rows[0]['deadline_date'], $locale)) : '');

            $blocks[] = F::collapsible($heading, array_map(function (array $row) use ($locale, $t): string {
                $action = $this->action($row, $locale);
                $facts = array_filter([
                    (int) $row['days'] < 0
                        ? '<b>'.e(F::days((int) $row['days'], $locale)).'</b> · '.e($t('was_due', ['date' => F::shortDate($row['deadline_date'])]))
                        : null,
                    F::priorityTag((int) $row['priority'], $locale),
                ]);

                return F::join([[
                    F::taskLink($row['task_id'], $row['number'], $row['title'], $locale),
                    $facts === [] ? null : implode(' · ', $facts),
                    $action === null ? null : '👉 '.e($action),
                ]]);
            }, $rows));
        }

        if ($watched !== []) {
            $blocks[] = F::collapsible(
                '👀 <b>'.e($upper($t('group_watcher'))).' · '.count($watched).'</b>',
                [
                    e($t('watcher_note')),
                    ...array_map(fn (array $row): string => F::join([[
                        F::taskLink($row['task_id'], $row['number'], $row['title'], $locale, bold: false),
                        str_replace('%s', F::status($row['status'], $locale), e($t('watch_line', [
                            'due' => $t('due', ['date' => F::shortDate($row['deadline_date'])]),
                            'name' => $row['responsible'],
                            'status' => '%s',
                        ]))),
                    ]]), $watched),
                ],
            );
        }

        $blocks[] = e(__('notification.bot.pick_task', [], $locale));
        $blocks[] = F::link(route('tasks.index'), $t('all_tasks'));

        return F::join($blocks);
    }

    /**
     * What the person is expected to do, or null for a watcher.
     *
     * @param  array<string, mixed>  $row
     */
    private function action(array $row, string $locale): ?string
    {
        if ($row['role'] === 'watcher') {
            return null;
        }

        $key = 'notification.reminder.do_'.$row['status'];
        $text = __($key, [], $locale);

        return $text === $key ? null : $text;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => TaskReminderService::EVENT,
            'date' => $this->date,
            'task_ids' => array_column($this->rows, 'task_id'),
        ];
    }
}
