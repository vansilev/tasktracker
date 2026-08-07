<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

class TaskPlainTextBackfill
{
    public function __construct(
        private HtmlContentService $html,
    ) {}

    /**
     * @return array{tasks: int, comments: int}
     */
    public function run(bool $dryRun = false): array
    {
        return [
            'tasks' => $this->backfillTasks($dryRun),
            'comments' => $this->backfillComments($dryRun),
        ];
    }

    private function backfillTasks(bool $dryRun): int
    {
        $updated = 0;

        Task::withTrashed()
            ->orderBy('id')
            ->chunkById(200, function ($tasks) use ($dryRun, &$updated): void {
                foreach ($tasks as $task) {
                    $plain = $this->html->toPlainText($task->description);

                    if ($task->description_text === $plain) {
                        continue;
                    }

                    $updated++;

                    if (! $dryRun) {
                        // Query-builder update writes only the shadow column and
                        // leaves updated_at (and other columns) untouched.
                        DB::table('tasks')->where('id', $task->id)->update([
                            'description_text' => $plain,
                        ]);
                    }
                }
            });

        return $updated;
    }

    private function backfillComments(bool $dryRun): int
    {
        $updated = 0;

        TaskComment::query()
            ->orderBy('id')
            ->chunkById(200, function ($comments) use ($dryRun, &$updated): void {
                foreach ($comments as $comment) {
                    $plain = $this->html->toPlainText($comment->body);

                    if ($comment->body_text === $plain) {
                        continue;
                    }

                    $updated++;

                    if (! $dryRun) {
                        DB::table('task_comments')->where('id', $comment->id)->update([
                            'body_text' => $plain,
                        ]);
                    }
                }
            });

        return $updated;
    }
}
