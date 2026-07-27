<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FixEmptyTaskTitles extends Command
{
    protected $signature = 'tasks:fix-empty-titles';

    protected $description = 'Backfill empty task titles from description';

    public function handle(): int
    {
        $fixed = 0;

        Task::query()
            ->where(function ($query): void {
                $query->where('title', '')->orWhereNull('title');
            })
            ->eachById(function (Task $task) use (&$fixed): void {
                $normalized = trim(preg_replace('/\s+/', ' ', $task->description ?? '') ?? '');

                $task->update([
                    'title' => Str::limit($normalized, 120, ''),
                ]);

                $fixed++;
            });

        $this->info("Updated {$fixed} task title(s).");

        return self::SUCCESS;
    }
}
