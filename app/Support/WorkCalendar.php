<?php

namespace App\Support;

use App\Services\ReminderSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Working days and hours for reminders, in Kyiv time. Weekends come from the admin settings;
 * public holidays are not tracked (decided with the customer).
 */
class WorkCalendar
{
    public function __construct(private ReminderSettings $settings) {}

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    public function isWorkDay(CarbonInterface|string $date): bool
    {
        return in_array($this->day($date)->dayOfWeekIso, $this->settings->workDays(), true);
    }

    /**
     * The date itself when it is a working day, otherwise the closest working day before it.
     */
    public function workDayOnOrBefore(CarbonInterface|string $date): Carbon
    {
        $day = $this->day($date);

        // workDays() is never empty, so seven steps always reach a working day.
        for ($i = 0; $i < 7 && ! $this->isWorkDay($day); $i++) {
            $day->subDay();
        }

        return $day;
    }

    public function isWithinWorkHours(?CarbonInterface $moment = null): bool
    {
        $moment = ($moment ? Carbon::instance($moment) : $this->now())->timezone($this->timezone());
        $time = $moment->format('H:i');

        return $this->isWorkDay($moment)
            && $time >= $this->settings->workStart()
            && $time < $this->settings->workEnd();
    }

    /**
     * Working days strictly after $from up to and including $to (0 when $to is not later).
     */
    public function workDaysBetween(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        $cursor = $this->day($from);
        $end = $this->day($to);
        $count = 0;

        while ($cursor->lt($end)) {
            $cursor->addDay();
            $count += $this->isWorkDay($cursor) ? 1 : 0;
        }

        return $count;
    }

    private function day(CarbonInterface|string $date): Carbon
    {
        $day = is_string($date)
            ? Carbon::parse($date, $this->timezone())
            : Carbon::instance($date)->timezone($this->timezone());

        return $day->startOfDay();
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'Europe/Kyiv');
    }
}
