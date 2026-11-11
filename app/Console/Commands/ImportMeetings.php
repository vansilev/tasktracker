<?php

namespace App\Console\Commands;

use App\Services\Meetings\MeetingImporter;
use App\Services\Meetings\MeetingTaskExtractor;
use Illuminate\Console\Command;
use Throwable;

class ImportMeetings extends Command
{
    protected $signature = 'meetings:import
        {--limit=10 : How many latest Fireflies meetings to fetch}
        {--id= : Import one meeting by its Fireflies id}
        {--analyze : Also draft tasks with the model for meetings not analysed yet}';

    protected $description = 'Copy meetings from Fireflies (read-only there) and optionally draft tasks from them.';

    public function handle(MeetingImporter $importer, MeetingTaskExtractor $extractor): int
    {
        $meetings = $this->option('id')
            ? [$importer->import((string) $this->option('id'))]
            : $importer->importRecent((int) $this->option('limit'));

        foreach ($meetings as $meeting) {
            $line = "{$meeting->started_at->format('Y-m-d')}  {$meeting->title}  (".count($meeting->sentences ?? []).' sentences)';

            if ($this->option('analyze') && ! $meeting->analyzed_at) {
                try {
                    $count = $extractor->analyze($meeting);
                    $line .= "  → {$count} drafts";
                } catch (Throwable $e) {
                    $line .= '  → analysis failed: '.$e->getMessage();
                }
            }

            $this->line($line);
        }

        return self::SUCCESS;
    }
}
