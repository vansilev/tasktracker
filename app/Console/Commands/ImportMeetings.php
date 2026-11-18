<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\Meetings\FirefliesClient;
use App\Services\Meetings\MeetingImporter;
use App\Services\Meetings\MeetingTaskExtractor;
use Illuminate\Console\Command;
use Throwable;

class ImportMeetings extends Command
{
    protected $signature = 'meetings:import
        {--limit=10 : How many latest Fireflies meetings to fetch}
        {--id= : Import one meeting by its Fireflies id}
        {--analyze : Also draft tasks with the model for meetings not analysed yet}
        {--new-only : Print only the number of new meetings (for the scheduler)}';

    protected $description = 'Copy meetings from Fireflies (read-only there) and optionally draft tasks from them.';

    public function handle(MeetingImporter $importer, MeetingTaskExtractor $extractor, FirefliesClient $fireflies): int
    {
        // Runs from the scheduler too: without a key there is nothing to do, and that is not an error.
        if (! $fireflies->configured()) {
            $this->line('FIREFLIES_API_KEY is not set — nothing to import.');

            return self::SUCCESS;
        }

        $before = Meeting::query()->count();

        try {
            $meetings = $this->option('id')
                ? [$importer->import((string) $this->option('id'))]
                : $importer->importRecent((int) $this->option('limit'));
        } catch (Throwable $e) {
            report($e);
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $new = Meeting::query()->count() - $before;
        if ($this->option('new-only')) {
            if ($new > 0) {
                $this->line("New meetings: {$new}");
            }

            return self::SUCCESS;
        }

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
