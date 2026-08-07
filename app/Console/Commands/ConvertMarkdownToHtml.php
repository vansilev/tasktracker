<?php

namespace App\Console\Commands;

use App\Services\MarkdownToHtmlConverter;
use Illuminate\Console\Command;

class ConvertMarkdownToHtml extends Command
{
    protected $signature = 'tasks:convert-markdown-to-html
                            {--dry-run : Report how many rows would convert without writing}
                            {--force : Convert rows that look like HTML even when still marked markdown}
                            {--chunk=100 : Rows per transactional chunk}';

    protected $description = 'Convert task/comment content from Markdown to HTML (idempotent; keyed by format marker). Rows marked markdown whose content already looks like block-level HTML are quarantined (skipped) by default — pass --force to convert them anyway.';

    public function handle(MarkdownToHtmlConverter $converter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $chunk = max(1, (int) $this->option('chunk'));

        $result = $converter->run($dryRun, $chunk, force: $force);

        $verb = $dryRun ? 'Would convert' : 'Converted';
        $this->info("{$verb} {$result['tasks']} task(s) and {$result['comments']} comment(s).");

        $quarantinedTasks = $result['quarantined_tasks'];
        $quarantinedComments = $result['quarantined_comments'];
        $quarantinedCount = count($quarantinedTasks) + count($quarantinedComments);

        if ($quarantinedCount > 0) {
            $this->warn("Quarantined {$quarantinedCount} row(s) that look like HTML while marked markdown (use --force to convert anyway):");
            if ($quarantinedTasks !== []) {
                $this->line('  tasks: '.implode(', ', $quarantinedTasks));
            }
            if ($quarantinedComments !== []) {
                $this->line('  comments: '.implode(', ', $quarantinedComments));
            }
            $this->line('Note: fenced code blocks are stripped before the HTML check; a literal <p> outside fences is quarantined. Prefer false-quarantine over false-convert.');
        }

        if ($result['samples'] !== []) {
            $this->newLine();
            $this->line('Sample before → after:');
            foreach ($result['samples'] as $sample) {
                $this->line("--- {$sample['type']} #{$sample['id']} ---");
                $this->line('BEFORE: '.$sample['before']);
                $this->line('AFTER:  '.$sample['after']);
            }
        }

        return self::SUCCESS;
    }
}
