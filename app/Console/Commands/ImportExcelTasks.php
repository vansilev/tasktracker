<?php

namespace App\Console\Commands;

use App\Services\ExcelTaskImportService;
use Illuminate\Console\Command;

class ImportExcelTasks extends Command
{
    protected $signature = 'tasks:import-excel {path : Path to the Excel file} {--dry-run : Analyze without writing} {--approved : Approved open-tasks import with real initiators and supplemental rows}';

    protected $description = 'Import active tasks from IT Task Tracker Excel file';

    public function handle(ExcelTaskImportService $importer): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $approved = (bool) $this->option('approved');

        if ($dryRun) {
            $this->warn('Dry run — no rows will be written.');
        }

        $result = $approved
            ? $importer->importApprovedOpenTasks($path, $dryRun)
            : $importer->import($path, $dryRun);

        $this->info(($dryRun ? 'Would import' : 'Imported').": {$result['imported']} tasks");

        if (($result['preview'] ?? []) !== []) {
            $this->line('Preview:');
            foreach ($result['preview'] as $row) {
                $this->line(sprintf(
                    '  #%s row %s [%s] %s → initiator %s, assignee %s',
                    $row['number'],
                    $row['row'],
                    $row['status'],
                    $row['initiator_label'],
                    $row['initiator_email'],
                    $row['assignee_email'] ?? '—',
                ));
            }
        }

        if ($result['skipped'] !== []) {
            $this->warn('Skipped '.count($result['skipped']).' rows:');
            foreach ($result['skipped'] as $skip) {
                $this->line('  '.json_encode($skip, JSON_UNESCAPED_UNICODE));
            }
        }

        if (! $dryRun) {
            $this->info('Total tasks in DB: '.\App\Models\Task::count());
            $this->info('Max task number: '.\App\Models\Task::max('number'));
        }

        return self::SUCCESS;
    }
}
