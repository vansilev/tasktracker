<?php

namespace App\Console\Commands;

use App\Services\ReminderSettings;
use App\Services\SettingsService;
use App\Services\TaskReminderService;
use App\Support\WorkCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMorningReminders extends Command
{
    public const LAST_RUN_KEY = 'reminder_morning_last_run';

    protected $signature = 'tasks:send-morning-reminders
        {--dry-run : Count what would go out without sending or recording anything}
        {--force : Run the full pass now, even outside the morning slot or when today already ran (still a working day only)}
        {--date= : Pretend today is this date, Y-m-d; only together with --dry-run}';

    protected $description = 'Send each person one Telegram message with their task deadlines and escalate long-overdue tasks';

    public function handle(
        TaskReminderService $reminders,
        ReminderSettings $settings,
        SettingsService $store,
        WorkCalendar $calendar,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $date = $this->option('date');
        $force = (bool) $this->option('force');

        if (filled($date) && ! $dryRun) {
            $this->error('--date only works together with --dry-run: reminders for another day are never really sent.');

            return self::FAILURE;
        }

        if (! $settings->enabled() && ! $dryRun) {
            $this->info('Morning reminders are switched off in the admin settings.');

            return self::SUCCESS;
        }

        $now = filled($date)
            ? Carbon::createFromFormat('!Y-m-d', (string) $date, config('app.timezone'))->setTimeFromTimeString($settings->morningTime())
            : $calendar->now();
        $today = $now->format('Y-m-d');
        $time = $now->format('H:i');

        if (! $calendar->isWorkDay($now)) {
            $this->info("{$today} is not a working day.");

            return self::SUCCESS;
        }

        // Before the morning slot nothing happens; after the working day the morning is simply lost.
        $inSlot = $time >= $settings->morningTime() && $time < $settings->workEnd();

        if (! $inSlot && ! $force && ! filled($date)) {
            return self::SUCCESS;
        }

        // One full pass per working day keeps it "one message in the morning". Later ticks only
        // re-send what failed or got stuck; the claims table makes every pass safe to repeat.
        $retryOnly = ! $force && ! filled($date) && $store->get(self::LAST_RUN_KEY) === $today;

        $stats = $reminders->run($now, $dryRun, $retryOnly);

        if (! $dryRun && ! $retryOnly) {
            $store->set(self::LAST_RUN_KEY, $today);
        }

        if ($retryOnly && $stats['people'] === 0 && $stats['escalations'] === 0) {
            return self::SUCCESS;
        }

        $prefix = $dryRun ? 'Would send' : 'Sent';
        $this->info("{$prefix} to people: {$stats['people']} (task lines: {$stats['lines']})");
        $this->info("{$prefix} escalations: {$stats['escalations']}");
        $this->info("People without Telegram or with reminders off: {$stats['no_telegram']}");
        $this->info("Escalations with nobody to tell: {$stats['escalations_without_recipient']}");

        return self::SUCCESS;
    }
}
