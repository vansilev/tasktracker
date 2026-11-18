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
// A mention must arrive within a minute. The lock expires in 5 minutes, so a killed run cannot stop the rest of the day.
Schedule::command('tasks:send-comment-notices')->everyMinute()->withoutOverlapping(5);
Schedule::command('billing:send-reminders')->dailyAt('09:00');
// Task 456: new Fireflies meetings appear in the tracker by themselves. Hourly in working time keeps
// the Fireflies API calls low (about 13 a day plus one per new meeting); only new meetings are fetched.
Schedule::command('meetings:import --limit=10 --new-only')
    ->weekdays()->hourlyAt(5)->between('9:00', '21:00')
    ->withoutOverlapping(30);
