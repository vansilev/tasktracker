<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Services\ReminderSettings;
use App\Services\TaskReminderService;
use App\Services\TaskVisibilityService;
use App\Support\TelegramAppLinks;
use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramTaskCard;
use App\Support\TelegramWidget as W;
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

    public function toTelegram(object $notifiable): TelegramMessage|TelegramWidgetMessage
    {
        /** @var User $notifiable */
        $locale = $notifiable->preferredLocale();
        $text = TelegramMessage::create()
            ->parseMode('HTML')
            ->options(array_filter([
                'disable_web_page_preview' => true,
                'reply_markup' => $this->keyboard($notifiable),
            ]))
            ->content($this->text($locale));

        if (! app(ReminderSettings::class)->widgetsFor($notifiable->id)) {
            return $text;
        }

        [$html, $caption] = $this->widget($locale);
        $rows = $this->keyboardRows($notifiable);

        return TelegramWidgetMessage::make($html, W::caption($caption), $text, count($this->rows) > 1 ? $rows : TelegramAppLinks::widgetKeyboard($rows));
    }

    /**
     * The same news as text(), as a widget: one task as a card, several as tabs by urgency.
     *
     * @return array{0: string, 1: string} the HTML and the caption
     */
    public function widget(string $locale): array
    {
        $t = fn (string $key, array $replace = []) => __('notification.reminder.'.$key, $replace, $locale);
        $w = fn (string $key, array $replace = []) => __('notification.widget.'.$key, $replace, $locale);

        if (count($this->rows) === 1) {
            $row = $this->rows[0];
            $days = (int) $row['days'];
            $date = F::weekdayDate($row['deadline_date'], $locale);
            [$chip, $colour] = match (true) {
                $days < 0 => [$t('head_overdue', ['days' => F::days($days, $locale)]), 'red'],
                $days === 0 => [$t('head_today'), 'red'],
                $days === 1 => [$t('head_tomorrow'), 'or'],
                default => [$t('head_in_days', ['days' => F::days($days, $locale)]), 'bl'],
            };
            $status = e(__('notification.reminder.status_quoted', ['status' => __('task.status.'.$row['status'], [], $locale)], $locale));
            $action = $this->action($row, $locale);
            $who = match ($row['role']) {
                'watcher' => ['👀 '.e($t('you_watcher')), '🎯 '.str_replace('%s', $status, e($t('responsible_is', ['name' => $row['responsible'], 'status' => '%s'])))],
                'initiator' => ['🙋 '.str_replace('%s', $status, e($t('you_initiator', ['status' => '%s']))), filled($row['assignee']) ? '🎯 '.e($t('assignee_is', ['name' => $row['assignee']])) : null],
                default => ['🎯 '.str_replace('%s', $status, e($t('you_assignee', ['status' => '%s'])))],
            };

            $html = W::chips([[F::urgencyEmoji($days).' '.$chip, $colour], F::isHighPriority((int) $row['priority']) ? [F::priorityTag((int) $row['priority'], $locale) ?? '', 'gy'] : null])
                .W::title($row['task_id'], $row['number'], $row['title'])
                .W::meta([...$who, '🗓 '.e($days < 0 ? $t('was_due', ['date' => $date]) : $t('due', ['date' => $date]))])
                .($action === null ? '' : W::todo('👉 <b>'.e($t('what')).'</b> '.e($action)))
                .W::linkButtons($row['task_id'], $row['number'], $locale);

            return [W::document($html, $locale), F::urgencyEmoji($days).' '.$chip.': #'.$row['number'].' '.$row['title']];
        }

        $own = array_values(array_filter($this->rows, fn (array $row) => $row['role'] !== 'watcher'));
        $watched = array_values(array_filter($this->rows, fn (array $row) => $row['role'] === 'watcher'));
        $bucket = fn (int $days) => match (true) {
            $days < 0 => 'overdue', $days === 0 => 'today', $days === 1 => 'tomorrow', default => 'later'
        };
        $groups = ['overdue' => [], 'today' => [], 'tomorrow' => [], 'later' => []];

        foreach ($own as $row) {
            $groups[$bucket((int) $row['days'])][] = $row;
        }

        $line = function (array $row) use ($locale, $t): string {
            $days = (int) $row['days'];
            $facts = array_filter([
                '🗓 '.($days < 0 ? '<b>'.e(F::days($days, $locale)).'</b> · '.e($t('was_due', ['date' => F::shortDate($row['deadline_date'])])) : e(F::weekdayDate($row['deadline_date'], $locale))),
                F::priorityTag((int) $row['priority'], $locale) === null ? null : e(F::priorityTag((int) $row['priority'], $locale)),
                $row['role'] === 'watcher' ? '👀 '.e($row['responsible']) : (($action = $this->action($row, $locale)) === null ? null : '👉 '.e($action)),
            ]);

            return W::row($row['task_id'], $row['number'], $row['title'], implode(' · ', $facts));
        };

        $labels = ['overdue' => $t('group_overdue'), 'today' => $t('group_today'), 'tomorrow' => $t('group_tomorrow'), 'later' => __('notification.widget.later', [], $locale)];
        $pages = [];
        $tiles = [];

        foreach ($groups as $key => $rows) {
            if ($rows !== []) {
                $pages[] = [$labels[$key], implode('', array_map($line, $rows))];
                $tiles[] = [count($rows), mb_strtolower($labels[$key]), $key === 'overdue'];
            }
        }

        if ($watched !== []) {
            $pages[] = [$w('watching'), W::meta([e($t('watcher_note'))]).implode('', array_map($line, $watched))];
            $tiles[] = [count($watched), mb_strtolower($w('watching'))];
        }

        $html = W::chips([], '📋 '.$t('list_title', ['date' => F::weekdayDate($this->date, $locale)]))
            .W::tiles(array_slice($tiles, 0, 4))
            .W::tabs($pages);

        $summary = implode(', ', array_map(fn (array $tile) => $tile[0].' '.$tile[1], $tiles));

        return [W::document($html, $locale), '📋 '.$w('list_caption', ['summary' => $summary])];
    }

    /**
     * One task: its action buttons. Several: their numbers, a press sends that task's card.
     * Buttons only invite; each press is checked again on the server.
     */
    private function keyboard(User $user): ?string
    {
        $rows = $this->keyboardRows($user);

        return $rows === [] ? null : json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /** @return list<list<array<string, mixed>>> */
    private function keyboardRows(User $user): array
    {
        if (count($this->rows) > 1) {
            return TelegramTaskCard::listKeyboard($this->rows);
        }

        $task = app(TaskVisibilityService::class)->accessibleQuery($user)->whereKey($this->rows[0]['task_id'])->first();

        return $task === null ? [] : app(TelegramTaskCard::class)->keyboard($task, $user);
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
