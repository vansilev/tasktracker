<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class BillingCycleService
{
    public function today(): Carbon
    {
        return now()->timezone(config('app.timezone', 'Europe/Kyiv'))->startOfDay();
    }

    public function clampDay(CarbonInterface $month, int $dayOfMonth): Carbon
    {
        $date = Carbon::parse($month, config('app.timezone', 'Europe/Kyiv'))->startOfDay();
        $last = $date->daysInMonth;
        $day = min(max($dayOfMonth, 1), $last);

        return $date->copy()->day($day)->startOfDay();
    }

    public function advanceToFuture(
        CarbonInterface $from,
        int $periodMonths,
        ?int $dayOfMonth = null,
        ?CarbonInterface $today = null,
    ): Carbon {
        $today = ($today ?? $this->today())->copy()->startOfDay();
        $cursor = Carbon::parse($from, config('app.timezone', 'Europe/Kyiv'))->startOfDay();

        if ($periodMonths < 1) {
            $periodMonths = 1;
        }

        do {
            $cursor = $cursor->copy()->addMonthsNoOverflow($periodMonths);
            if ($dayOfMonth !== null) {
                $cursor = $this->clampDay($cursor, $dayOfMonth);
            }
        } while ($cursor->lte($today));

        return $cursor;
    }

    public function reminderDate(CarbonInterface $due, int $daysBefore, bool $notBeforeMonthStart): Carbon
    {
        $due = Carbon::parse($due, config('app.timezone', 'Europe/Kyiv'))->startOfDay();
        $remind = $due->copy()->subDays($daysBefore);

        if ($notBeforeMonthStart) {
            $first = $due->copy()->startOfMonth();
            if ($remind->lt($first)) {
                return $first;
            }
        }

        return $remind;
    }
}
