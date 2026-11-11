<?php

namespace App\Support;

/**
 * Shared look of the personal reminder messages (formats approved by the customer on
 * 2026-11-11). It keeps the vocabulary of the group messages built by
 * TelegramGroupMessageBuilder — emoji-led bold headers, the same role and priority icons,
 * "🔗 Открыть задачу" as the last line — and adds what a reminder needs: urgency first,
 * a "what to do" line, weekdays in dates and collapsible groups. Telegram HTML.
 */
class TelegramReminderFormat
{
    /** The group messages mention priority only from this level up; so do reminders. */
    public const HIGH_PRIORITY_FROM = 7;

    public static function isHighPriority(int $priority): bool
    {
        return $priority >= self::HIGH_PRIORITY_FROM;
    }

    public static function priorityEmoji(int $priority): string
    {
        return $priority >= 9 ? '🔥' : '🟠';
    }

    /** "🟠 Приоритет 8/10 — высокий", or null below the highlight level. */
    public static function priorityLine(int $priority, string $locale): ?string
    {
        if (! self::isHighPriority($priority)) {
            return null;
        }

        return self::priorityEmoji($priority).' '.e(__('notification.reminder.priority_full', [
            'value' => $priority,
            'label' => __('notification.group.'.($priority >= 9 ? 'priority_critical' : 'priority_high'), [], $locale),
        ], $locale));
    }

    /** "🟠 приоритет 8/10" for a compact list line, or null. */
    public static function priorityTag(int $priority, string $locale): ?string
    {
        return self::isHighPriority($priority)
            ? self::priorityEmoji($priority).' '.e(__('notification.reminder.priority', ['value' => $priority], $locale))
            : null;
    }

    public static function urgencyEmoji(int $days): string
    {
        return match (true) {
            $days < 0 => '🔴',
            $days === 0 => '⏰',
            $days === 1 => '🟡',
            default => '🗓',
        };
    }

    /** "7 дней", "2 дня", "1 день" in the reader's language. */
    public static function days(int $count, string $locale): string
    {
        return trans_choice('notification.reminder.days', abs($count), ['count' => abs($count)], $locale);
    }

    /** "чт, 12.11" from a Y-m-d date. */
    public static function weekdayDate(string $date, string $locale): string
    {
        $time = strtotime($date.' 12:00:00 UTC');

        return __('notification.weekdays', [], $locale)[(int) gmdate('N', $time)].', '.gmdate('d.m', $time);
    }

    public static function shortDate(string $date): string
    {
        return gmdate('d.m', strtotime($date.' 12:00:00 UTC'));
    }

    public static function taskLink(int $taskId, int $number, string $title, string $locale, bool $bold = true): string
    {
        $label = e(__('notification.reminder.task', [
            'number' => $number,
            'title' => mb_strimwidth(trim($title), 0, 120, '…'),
        ], $locale));

        return '<a href="'.e(route('tasks.show', $taskId)).'">'.($bold ? '<b>'.$label.'</b>' : $label).'</a>';
    }

    public static function link(string $url, string $label): string
    {
        return '🔗 <a href="'.e($url).'">'.e($label).'</a>';
    }

    public static function status(string $status, string $locale): string
    {
        return e(__('notification.reminder.status_quoted', ['status' => __('task.status.'.$status, [], $locale)], $locale));
    }

    /** A group that Telegram shows collapsed after the first lines and opens on a tap. */
    public static function collapsible(string $heading, array $paragraphs): string
    {
        return '<blockquote expandable>'.$heading."\n\n".implode("\n\n", $paragraphs).'</blockquote>';
    }

    /**
     * Paragraphs separated by one blank line; null and empty lines are dropped.
     *
     * @param  list<list<string|null>|string|null>  $blocks
     */
    public static function join(array $blocks): string
    {
        $out = [];

        foreach ($blocks as $block) {
            $lines = array_values(array_filter((array) $block, fn (?string $line) => $line !== null && $line !== ''));

            if ($lines !== []) {
                $out[] = implode("\n", $lines);
            }
        }

        return implode("\n\n", $out);
    }
}
