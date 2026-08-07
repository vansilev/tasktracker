<?php

namespace App\Console\Commands;

use App\Services\TaskPlainTextBackfill;
use Illuminate\Console\Command;

class BackfillTaskPlainText extends Command
{
    protected $signature = 'tasks:backfill-plain-text
                            {--dry-run : Report how many rows would be updated without writing}';

    protected $description = 'Populate description_text / body_text shadow columns from stored content';

    public function handle(TaskPlainTextBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = $backfill->run($dryRun);

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$counts['tasks']} task(s) and {$counts['comments']} comment(s).");

        return self::SUCCESS;
    }
}
