<?php

namespace App\Console\Commands;

use App\Services\ReminderSettings;
use App\Services\TaskDigestService;
use App\Support\WorkCalendar;
use Illuminate\Console\Command;

class SendTaskDigest extends Command
{
    protected $signature = 'tasks:send-digest
        {--dry-run : Build the digest and print the numbers without sending or recording anything}
        {--force : Run now even outside the digest slot (still a working day only)}';

    protected $description = 'Send the daily task digest to the digest recipients in Telegram';

    public function handle(TaskDigestService $digest, ReminderSettings $settings, WorkCalendar $calendar): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $settings->enabled() && ! $dryRun) {
            $this->info('Reminders are switched off in the admin settings: no digest.');

            return self::SUCCESS;
        }

        $now = $calendar->now();
        $time = $now->format('H:i');

        if (! $calendar->isWorkDay($now)) {
            $this->info($now->format('Y-m-d').' is not a working day.');

            return self::SUCCESS;
        }

        // Every tick inside the slot is safe: recipients already served today are skipped,
        // failed or stuck deliveries are taken again.
        if (! $dryRun && ! $this->option('force') && ($time < $settings->digestTime() || $time >= $settings->workEnd())) {
            return self::SUCCESS;
        }

        $stats = $digest->sendScheduled($now, $dryRun);

        if ($stats['recipients'] === 0) {
            $this->warn('No digest recipients are chosen in the admin settings.');
        } elseif ($stats['recipients'] === $stats['without_telegram']) {
            $this->warn('None of the digest recipients has Telegram linked (or they switched reminders off).');
        }

        if ($stats['sent_to'] > 0 || $dryRun) {
            $prefix = $dryRun ? 'Would send' : 'Sent';
            $this->info("{$prefix} the digest to {$stats['sent_to']} of {$stats['recipients']} recipients ({$stats['messages']} messages).");
        }

        return self::SUCCESS;
    }
}
