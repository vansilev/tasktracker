<?php

namespace App\Support;

use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramWidget as W;

/**
 * Turns the data of TaskDigestService::build() into Telegram messages in the reminders' look:
 * a headline with the totals, then one collapsible group per block. A long digest is cut on
 * task borders into several messages; a group that does not fit is continued in the next one.
 */
class TaskDigestFormatter
{
    /** Telegram allows 4096 characters per message; markup and the headline need the rest. */
    private const MESSAGE_LIMIT = 3600;

    private const GROUP_LIMIT = 3000;

    private const TASKS_PER_PARAGRAPH = 6;

    /**
     * @param  array<string, mixed>  $digest
     * @return list<string> one string per Telegram message
     */
    public function messages(array $digest, string $locale): array
    {
        $t = fn (string $key, array $replace = []) => __('notification.digest.'.$key, $replace, $locale);
        $totals = $digest['totals'];

        $headline = F::join([[
            '📊 <b>'.e($t($digest['department'] === null ? 'title' : 'title_department', [
                'date' => F::weekdayDate($digest['date'], $locale),
                'department' => $digest['department'],
            ])).'</b>',
            implode(' · ', array_filter([
                e($t('open', ['count' => $totals['open']])),
                '🔴 '.e($t('overdue', ['count' => $totals['overdue']])),
                $totals['today'] > 0 ? '⏰ '.e($t('today', ['count' => $totals['today']])) : null,
                $totals['tomorrow'] > 0 ? '🟡 '.e($t('tomorrow', ['count' => $totals['tomorrow']])) : null,
            ])),
        ]]);

        $blocks = [$headline];

        foreach ($this->groups($digest, $locale) as [$heading, $paragraphs]) {
            foreach ($this->packGroup($heading, $paragraphs, $t('continued')) as $block) {
                $blocks[] = $block;
            }
        }

        $blocks[] = '📌 <b>'.e($t('total')).'</b> '.e($this->totalLine($digest, $locale));
        $blocks[] = F::link(route('tasks.index', ['tab' => 'all', 'overdueOnly' => 1]), $t('overdue_link'));

        return $this->packMessages($blocks);
    }

    /** How many tasks a widget tab lists before "N more — in the tracker". */
    private const WIDGET_ROWS = 12;

    /**
     * The digest as one HTML widget: tiles with the totals and a tab per group.
     *
     * @param  array<string, mixed>  $digest
     * @return array{0: string, 1: string} the HTML and the caption
     */
    public function widget(array $digest, string $locale): array
    {
        $t = fn (string $key, array $replace = []) => __('notification.digest.'.$key, $replace, $locale);
        $w = fn (string $key, array $replace = []) => __('notification.widget.'.$key, $replace, $locale);
        $totals = $digest['totals'];
        $listUrl = route('tasks.index', ['tab' => 'all', 'overdueOnly' => 1]);
        $more = fn (int $left) => $left > 0 ? '<a class=r href="'.e($listUrl).'">'.e($w('more', ['count' => $left])).'</a>' : '';
        $limit = function (array $rows, callable $line) use ($more): string {
            return implode('', array_map($line, array_slice($rows, 0, self::WIDGET_ROWS))).$more(count($rows) - self::WIDGET_ROWS);
        };

        $overdue = [];
        $bars = '';
        $max = max([1, ...array_map(fn (array $d) => (int) $d['count'], $digest['overdue'])]);

        foreach ($digest['overdue'] as $department) {
            $bars .= W::bar($department['department'], (int) $department['count'], $max);

            foreach ($department['people'] as $person) {
                foreach ($person['tasks'] as $row) {
                    $overdue[] = $row + ['person' => $person['name'], 'department' => $department['department']];
                }
            }
        }

        usort($overdue, fn (array $a, array $b) => [(int) $a['days'], $a['number']] <=> [(int) $b['days'], $b['number']]);

        $soon = array_merge(
            array_map(fn (array $row) => $row + ['when' => '⏰ '.mb_strtolower($t('group_today'))], $digest['today']),
            array_map(fn (array $row) => $row + ['when' => '🟡 '.mb_strtolower($t('group_tomorrow'))], $digest['tomorrow']),
        );

        $pages = [
            [$w('tab_overdue'), $limit($overdue, fn (array $row) => W::row($row['task_id'], $row['number'], $row['title'],
                '👤 '.e($row['person']).' · '.e($row['department']).' · <b>'.e(F::days(max(1, -(int) $row['days']), $locale)).'</b>'))
                .($totals['service_overdue'] > 0 ? W::meta(['<i>'.e($t('service_overdue', ['count' => $totals['service_overdue']])).'</i>'], 'margin-top:6px') : '')],
            [$w('tab_soon'), $limit($soon, fn (array $row) => W::row($row['task_id'], $row['number'], $row['title'],
                e($row['when']).' · 👤 '.e($row['person']).' · '.F::status($row['status'], $locale)))],
            [$w('tab_stuck'), $limit($digest['stuck'] ?? [], fn (array $row) => W::row($row['notice']->task->id, $row['notice']->task->number,
                (string) ($row['notice']->task->title ?: $row['notice']->task->plainDescription()),
                '⏳ '.e($w('waits', ['name' => (string) $row['notice']->user?->name, 'days' => trans_choice('notification.digest.work_days', max(1, $row['work_days']), ['count' => max(1, $row['work_days'])], $locale)]))))],
            [$w('tab_stale'), $limit($digest['stale'], fn (array $row) => W::row($row['task_id'], $row['number'], $row['title'],
                '💤 <b>'.e(trans_choice('notification.digest.work_days', $row['stale_work_days'], ['count' => $row['stale_work_days']], $locale)).'</b> · 👤 '.e($row['person'])))],
            [$w('tab_departments'), $bars === '' ? '' : '<div style="margin-top:6px">'.$bars.'</div>'],
        ];

        $footer = array_filter([
            $digest['escalated'] !== [] ? '🔺 '.e($t('group_escalated')).': <b>'.count($digest['escalated']).'</b>' : null,
            $digest['no_telegram'] !== [] ? '📵 '.e($w('no_telegram', ['names' => implode(', ', array_column($digest['no_telegram'], 'name'))])) : null,
            '📌 '.e($t('total')).' '.e($this->totalLine($digest, $locale)),
        ]);

        $title = $t($digest['department'] === null ? 'title' : 'title_department', [
            'date' => F::weekdayDate($digest['date'], $locale),
            'department' => $digest['department'],
        ]);

        $html = W::chips([], '📊 '.$title)
            .W::tiles([
                [(int) $totals['open'], $w('tile_open')],
                [(int) $totals['overdue'], $w('tile_overdue'), true],
                [(int) $totals['today'] + (int) $totals['tomorrow'], $w('tile_soon')],
                [count($digest['stuck'] ?? []), $w('tile_stuck')],
            ])
            .W::tabs($pages)
            .W::meta($footer, 'margin-top:8px');

        $caption = '📊 '.$title.' · '.$t('open', ['count' => $totals['open']]).' · '.$t('overdue', ['count' => $totals['overdue']]);

        return [W::document($html, $locale), $caption];
    }

    /**
     * @param  array<string, mixed>  $digest
     * @return list<array{0: string, 1: list<string>}> heading and paragraphs of every non-empty group
     */
    private function groups(array $digest, string $locale): array
    {
        $t = fn (string $key, array $replace = []) => __('notification.digest.'.$key, $replace, $locale);
        $heading = fn (string $emoji, string $key, int $count, string $tail = '') => $emoji.' <b>'.e(mb_strtoupper($t($key))).' · '.$count.'</b>'.$tail;
        $task = fn (array $row) => F::taskLink($row['task_id'], $row['number'], $row['title'], $locale, bold: false);
        $groups = [];

        if ($digest['totals']['overdue'] > 0) {
            $paragraphs = [];

            foreach ($digest['overdue'] as $department) {
                $departmentLine = '<b>'.e($department['department']).' · '.$department['count'].'</b>';

                foreach ($department['people'] as $person) {
                    // A person with many overdue tasks is cut into portions, so a message border
                    // can fall between them instead of inside one endless paragraph.
                    foreach (array_chunk($person['tasks'], self::TASKS_PER_PARAGRAPH) as $portion => $tasks) {
                        $lines = array_filter([
                            $departmentLine,
                            '👤 '.e($person['name']).' · '.$person['count'].($portion > 0 ? ' <i>'.e($t('continued')).'</i>' : ''),
                        ]);
                        $departmentLine = null;

                        foreach ($tasks as $row) {
                            $lines[] = $task($row);
                            $lines[] = '<b>'.e(F::days(max(1, -(int) $row['days']), $locale)).'</b> · '
                                .e(__('notification.reminder.was_due', ['date' => F::shortDate($row['deadline_date'])], $locale)).' · '
                                .F::status($row['status'], $locale)
                                .($row['postponed_until'] !== null && $row['status'] === 'postponed'
                                    ? ' '.e($t('until', ['date' => F::shortDate($row['postponed_until'])]))
                                    : '');
                        }

                        $paragraphs[] = implode("\n", $lines);
                    }
                }
            }

            if ($digest['totals']['service_overdue'] > 0) {
                $paragraphs[] = '<i>'.e($t('service_overdue', ['count' => $digest['totals']['service_overdue']])).'</i>';
            }

            $groups[] = [$heading('🔴', 'group_overdue', $digest['totals']['overdue']), $paragraphs];
        }

        foreach (['today' => ['⏰', 0], 'tomorrow' => ['🟡', 1]] as $key => [$emoji, $offset]) {
            if ($digest[$key] === []) {
                continue;
            }

            $date = gmdate('Y-m-d', strtotime($digest['date'].' 12:00:00 UTC +'.$offset.' day'));

            $groups[] = [
                $heading($emoji, 'group_'.$key, count($digest[$key]), $offset > 0 ? ' · '.e(F::weekdayDate($date, $locale)) : ''),
                array_map(fn (array $row) => $task($row)."\n".'👤 '.e($row['person']).' · '.e($row['department']).' · '.F::status($row['status'], $locale), $digest[$key]),
            ];
        }

        if ($digest['escalated'] !== []) {
            $groups[] = [
                $heading('🔺', 'group_escalated', count($digest['escalated'])),
                array_map(fn (array $row) => $task($row)."\n".e($t($row['to_head'] ? 'escalated_to_head' : 'escalated_to_digest', [
                    'person' => $row['person'],
                    'recipients' => implode(', ', $row['recipients']),
                ])), $digest['escalated']),
            ];
        }

        if (($digest['stuck'] ?? []) !== []) {
            $format = app(CommentNoticeFormat::class);

            $groups[] = [
                $heading('🧍', 'group_stuck', count($digest['stuck'])),
                array_map(fn (array $row) => $format->stuckParagraph($row['notice'], $row['work_days'], $locale), $digest['stuck']),
            ];
        }

        if ($digest['stale'] !== []) {
            $groups[] = [
                '💤 <b>'.e(mb_strtoupper($t('group_stale', ['days' => $digest['stale_threshold']]))).' · '.count($digest['stale']).'</b>',
                array_map(fn (array $row) => $task($row)."\n".'<b>'.e(trans_choice('notification.digest.work_days', $row['stale_work_days'], ['count' => $row['stale_work_days']], $locale)).'</b> · '
                    .e($row['person']).' · '.F::status($row['status'], $locale), $digest['stale']),
            ];
        }

        if ($digest['no_telegram'] !== []) {
            $groups[] = [
                $heading('📵', 'group_no_telegram', count($digest['no_telegram'])),
                [
                    e($t('no_telegram_note')),
                    implode("\n", array_map(fn (array $person) => e($person['name']).' — '.e(trans_choice('notification.digest.overdue_tasks', $person['overdue'], ['count' => $person['overdue']], $locale)), $digest['no_telegram'])),
                ],
            ];
        }

        return $groups;
    }

    /** @param  array<string, mixed>  $digest */
    private function totalLine(array $digest, string $locale): string
    {
        $t = fn (string $key, array $replace = []) => __('notification.digest.'.$key, $replace, $locale);
        $totals = $digest['totals'];
        $previous = $digest['previous'];

        if ($previous === null) {
            return $t('total_first', ['open' => $totals['open'], 'overdue' => $totals['overdue']]);
        }

        $delta = fn (int $now, int $then) => $now === $then ? '±0' : sprintf('%+d', $now - $then);

        return $t('total_compared', [
            'open' => $totals['open'],
            'open_then' => $previous['open'],
            'open_delta' => $delta($totals['open'], $previous['open']),
            'overdue' => $totals['overdue'],
            'overdue_then' => $previous['overdue'],
            'overdue_delta' => $delta($totals['overdue'], $previous['overdue']),
            'date' => F::weekdayDate($previous['date'], $locale),
        ]);
    }

    /**
     * One collapsible block per group; a group too long for one block continues in the next.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function packGroup(string $heading, array $paragraphs, string $continued): array
    {
        $blocks = [];
        $current = [];
        $size = 0;

        foreach ($paragraphs as $paragraph) {
            $length = mb_strlen($paragraph) + 2;

            if ($current !== [] && $size + $length > self::GROUP_LIMIT) {
                $blocks[] = $current;
                $current = [];
                $size = 0;
            }

            $current[] = $paragraph;
            $size += $length;
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return array_map(
            fn (array $part, int $index) => F::collapsible($index === 0 ? $heading : $heading.' <i>'.e($continued).'</i>', $part),
            $blocks,
            array_keys($blocks),
        );
    }

    /**
     * @param  list<string>  $blocks
     * @return list<string>
     */
    private function packMessages(array $blocks): array
    {
        $messages = [];
        $current = [];
        $size = 0;

        foreach ($blocks as $block) {
            $length = mb_strlen($block) + 2;

            if ($current !== [] && $size + $length > self::MESSAGE_LIMIT) {
                $messages[] = implode("\n\n", $current);
                $current = [];
                $size = 0;
            }

            $current[] = $block;
            $size += $length;
        }

        if ($current !== []) {
            $messages[] = implode("\n\n", $current);
        }

        return $messages;
    }
}
