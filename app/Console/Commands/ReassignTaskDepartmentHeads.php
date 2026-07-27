<?php

namespace App\Console\Commands;

use App\Services\ExcelTaskImportService;
use Illuminate\Console\Command;

class ReassignTaskDepartmentHeads extends Command
{
    protected $signature = 'tasks:reassign-dept-heads';

    protected $description = 'Set task assignees to heads of initiator departments';

    public function handle(ExcelTaskImportService $importer): int
    {
        $updated = $importer->reassignTasksToDepartmentHeads();

        $this->info('Updated: '.count($updated).' tasks');

        foreach ($updated as $row) {
            $this->line(sprintf(
                '  #%s %s → %s',
                $row['number'],
                $row['department'],
                $row['assignee_email'],
            ));
        }

        return self::SUCCESS;
    }
}
