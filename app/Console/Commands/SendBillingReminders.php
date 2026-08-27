<?php

namespace App\Console\Commands;

use App\Services\BillingReminderService;
use Illuminate\Console\Command;

class SendBillingReminders extends Command
{
    protected $signature = 'billing:send-reminders {--dry-run : Count matching items without sending}';

    protected $description = 'Send billing due reminders and create payment tasks';

    public function handle(BillingReminderService $reminders): int
    {
        $result = $reminders->run((bool) $this->option('dry-run'));
        $prefix = $this->option('dry-run') ? 'Would process' : 'Processed';

        $this->info("{$prefix} resumed pauses: {$result['resumed']}");
        $this->info("{$prefix} due in 7 days: {$result['due7']}");
        $this->info("{$prefix} due in 3 days: {$result['due3']}");
        $this->info("{$prefix} overdue: {$result['overdue']}");
        $this->info("{$prefix} tasks created: {$result['created']}");

        return self::SUCCESS;
    }
}
