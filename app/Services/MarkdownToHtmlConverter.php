<?php

namespace App\Services;

use App\Enums\ContentFormat;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent Markdown → HTML conversion keyed by description_format / body_format.
 * Only rows marked markdown are touched; each chunk runs in a transaction.
 *
 * Writes use the query builder and intentionally bypass model events: CommonMark
 * is configured with html_input=escape, so converted HTML is already inert and
 * must not be re-processed by the Eloquent sanitize-on-write hooks.
 */
class MarkdownToHtmlConverter
{
    public function __construct(
        private MarkdownService $markdown,
        private HtmlContentService $html,
    ) {}

    /**
     * @return array{
     *     tasks: int,
     *     comments: int,
     *     samples: list<array{type: string, id: int, before: string, after: string}>,
     *     quarantined_tasks: list<int>,
     *     quarantined_comments: list<int>
     * }
     */
    public function run(bool $dryRun = false, int $chunkSize = 100, int $sampleLimit = 5, bool $force = false): array
    {
        $samples = [];
        $quarantinedTasks = [];
        $quarantinedComments = [];

        $tasks = $this->convertTasks($dryRun, $chunkSize, $sampleLimit, $force, $samples, $quarantinedTasks);
        $comments = $this->convertComments($dryRun, $chunkSize, $sampleLimit, $force, $samples, $quarantinedComments);

        return [
            'tasks' => $tasks,
            'comments' => $comments,
            'samples' => $samples,
            'quarantined_tasks' => $quarantinedTasks,
            'quarantined_comments' => $quarantinedComments,
        ];
    }

    /**
     * @param  list<array{type: string, id: int, before: string, after: string}>  $samples
     * @param  list<int>  $quarantined
     */
    private function convertTasks(
        bool $dryRun,
        int $chunkSize,
        int $sampleLimit,
        bool $force,
        array &$samples,
        array &$quarantined,
    ): int {
        $converted = 0;

        Task::withTrashed()
            ->where('description_format', ContentFormat::Markdown->value)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($tasks) use ($dryRun, $sampleLimit, $force, &$converted, &$samples, &$quarantined): void {
                if ($dryRun) {
                    foreach ($tasks as $task) {
                        if (! $force && $this->looksLikeBlockHtml((string) $task->description)) {
                            $quarantined[] = (int) $task->id;

                            continue;
                        }

                        $converted++;
                        $this->maybeSample(
                            $samples,
                            $sampleLimit,
                            'task',
                            (int) $task->id,
                            (string) $task->description,
                            $this->markdown->toHtml($task->description),
                        );
                    }

                    return;
                }

                DB::transaction(function () use ($tasks, $sampleLimit, $force, &$converted, &$samples, &$quarantined): void {
                    foreach ($tasks as $task) {
                        $before = (string) $task->description;

                        if (! $force && $this->looksLikeBlockHtml($before)) {
                            $quarantined[] = (int) $task->id;

                            continue;
                        }

                        $html = $this->markdown->toHtml($before);
                        $plain = $this->html->toPlainText($html);

                        // Query-builder update: same write for content + marker + shadow,
                        // without bumping updated_at or firing model events.
                        DB::table('tasks')->where('id', $task->id)->update([
                            'description' => $html,
                            'description_format' => ContentFormat::Html->value,
                            'description_text' => $plain,
                        ]);

                        $converted++;
                        $this->maybeSample($samples, $sampleLimit, 'task', (int) $task->id, $before, $html);
                    }
                });
            });

        return $converted;
    }

    /**
     * @param  list<array{type: string, id: int, before: string, after: string}>  $samples
     * @param  list<int>  $quarantined
     */
    private function convertComments(
        bool $dryRun,
        int $chunkSize,
        int $sampleLimit,
        bool $force,
        array &$samples,
        array &$quarantined,
    ): int {
        $converted = 0;

        // TaskComment has no SoftDeletes; rows belonging to soft-deleted tasks remain.
        TaskComment::query()
            ->where('body_format', ContentFormat::Markdown->value)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($comments) use ($dryRun, $sampleLimit, $force, &$converted, &$samples, &$quarantined): void {
                if ($dryRun) {
                    foreach ($comments as $comment) {
                        if (! $force && $this->looksLikeBlockHtml((string) $comment->body)) {
                            $quarantined[] = (int) $comment->id;

                            continue;
                        }

                        $converted++;
                        $this->maybeSample(
                            $samples,
                            $sampleLimit,
                            'comment',
                            (int) $comment->id,
                            (string) $comment->body,
                            $this->markdown->toHtml($comment->body),
                        );
                    }

                    return;
                }

                DB::transaction(function () use ($comments, $sampleLimit, $force, &$converted, &$samples, &$quarantined): void {
                    foreach ($comments as $comment) {
                        $before = (string) $comment->body;

                        if (! $force && $this->looksLikeBlockHtml($before)) {
                            $quarantined[] = (int) $comment->id;

                            continue;
                        }

                        $html = $this->markdown->toHtml($before);
                        $plain = $this->html->toPlainText($html);

                        DB::table('task_comments')->where('id', $comment->id)->update([
                            'body' => $html,
                            'body_format' => ContentFormat::Html->value,
                            'body_text' => $plain,
                        ]);

                        $converted++;
                        $this->maybeSample($samples, $sampleLimit, 'comment', (int) $comment->id, $before, $html);
                    }
                });
            });

        return $converted;
    }

    /**
     * True when content already looks like block-level HTML despite a markdown marker.
     * Fenced code blocks are stripped first so a typed `<p>` inside ``` is not
     * quarantined; prefer false-quarantine over false-convert when still unsure.
     */
    public function looksLikeBlockHtml(string $content): bool
    {
        $stripped = preg_replace('/```[\s\S]*?```/', '', $content) ?? $content;

        return (bool) preg_match(
            '/<\s*(?:p|div|ul|ol|li|h[1-6]|table|thead|tbody|tr|td|th|blockquote|pre|br|hr|section|article)\b/i',
            $stripped,
        );
    }

    /**
     * @param  list<array{type: string, id: int, before: string, after: string}>  $samples
     */
    private function maybeSample(
        array &$samples,
        int $sampleLimit,
        string $type,
        int $id,
        string $before,
        string $after,
    ): void {
        if (count($samples) >= $sampleLimit) {
            return;
        }

        $samples[] = [
            'type' => $type,
            'id' => $id,
            'before' => mb_strlen($before) > 200 ? mb_substr($before, 0, 200).'…' : $before,
            'after' => mb_strlen($after) > 200 ? mb_substr($after, 0, 200).'…' : $after,
        ];
    }
}
