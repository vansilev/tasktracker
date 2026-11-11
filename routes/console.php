<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tasks:send-reminders')->everyThirtyMinutes();
// Checks the clock itself: runs once, on a working morning, when reminders are switched on.
Schedule::command('tasks:send-morning-reminders')->everyFiveMinutes()->withoutOverlapping(10);
// Same pattern: waits for the digest time on a working day, then serves each recipient once.
Schedule::command('tasks:send-digest')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('billing:send-reminders')->dailyAt('09:00');
