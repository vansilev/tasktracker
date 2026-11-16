<?php

namespace App\Support;

use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * A task deadline is a calendar date: it means "by the end of that day", 23:59 Kyiv time.
 * Every write path goes through here, so a time of day never reaches the database.
 */
class Deadline
{
    public const END_OF_DAY = '23:59:00';

    /**
     * Which date a value means:
     * - a bare date, or a value whose own clock reads midnight ("2026-11-19T00:00:00Z",
     *   an Excel date cell) — the date as written;
     * - anything else — the Kyiv calendar date of that moment.
     */
    public static function normalize(DateTimeInterface|string|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'Europe/Kyiv');

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return self::endOfDay($value, $timezone);
            }

            $value = Carbon::parse($value, $timezone);
        }

        $moment = Carbon::instance($value);

        $date = $moment->format('H:i:s') === '00:00:00'
            ? $moment->format('Y-m-d')
            : $moment->copy()->timezone($timezone)->format('Y-m-d');

        return self::endOfDay($date, $timezone);
    }

    /**
     * Date for people: the time of day is shown only for legacy values that carry a real one.
     */
    public static function format(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $moment = self::inAppTimezone($value);

        return in_array($moment->format('H:i:s'), ['00:00:00', self::END_OF_DAY], true)
            ? $moment->format('d.m.Y')
            : $moment->format('d.m.Y H:i');
    }

    /**
     * Whole calendar days from today to the deadline date: 0 = today, negative = days overdue.
     * Counted on dates, not on moments, so "due today" stays 0 until the day ends.
     */
    public static function daysFromToday(DateTimeInterface|string $value, ?CarbonInterface $now = null): int
    {
        $timezone = (string) config('app.timezone', 'Europe/Kyiv');
        $today = ($now ?? now())->copy()->timezone($timezone)->format('Y-m-d');
        $day = self::inAppTimezone($value)->format('Y-m-d');

        return (int) round((strtotime($day.' UTC') - strtotime($today.' UTC')) / 86400);
    }

    /**
     * Text and colour for a deadline cell in lists, the board and the task card.
     *
     * @return array{text: string, class: string}
     */
    public static function meta(Task $task): array
    {
        if ($task->deadline === null) {
            return ['text' => '—', 'class' => 'text-gray-700'];
        }

        $formatted = self::inAppTimezone($task->deadline)->format('d.m.Y');

        if (! $task->status->isOpen()) {
            return ['text' => $formatted, 'class' => 'text-gray-700'];
        }

        if ($task->deadline->isPast()) {
            return [
                'text' => __(':date (overdue :count days)', [
                    'date' => $formatted,
                    'count' => max(1, -self::daysFromToday($task->deadline)),
                ]),
                'class' => 'text-red-600 font-medium',
            ];
        }

        $days = self::daysFromToday($task->deadline);

        if ($days <= 3) {
            return [
                'text' => match (true) {
                    $days <= 0 => __(':date (today)', ['date' => $formatted]),
                    $days === 1 => __(':date (tomorrow)', ['date' => $formatted]),
                    default => __(':date (in :count days)', ['date' => $formatted, 'count' => $days]),
                },
                'class' => 'text-amber-600 font-medium',
            ];
        }

        return ['text' => $formatted, 'class' => 'text-gray-700'];
    }

    private static function inAppTimezone(DateTimeInterface|string $value): Carbon
    {
        $timezone = (string) config('app.timezone', 'Europe/Kyiv');

        return is_string($value)
            ? Carbon::parse($value, $timezone)->timezone($timezone)
            : Carbon::instance($value)->timezone($timezone);
    }

    private static function endOfDay(string $date, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.self::END_OF_DAY, $timezone);
    }
}
