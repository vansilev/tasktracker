<?php

namespace App\Models;

use App\Enums\ContentFormat;
use App\Enums\TaskStatus;
use App\Services\HtmlContentService;
use App\Services\TaskContentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Task extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'initiator_id',
        'assignee_id',
        'department_initiator_id',
        'department_id',
        'category_id',
        'parent_id',
        'title',
        'description',
        // description_format is intentionally NOT fillable — set via attribute
        // assignment / forceFill on trusted write paths only.
        'priority',
        'status',
        'deadline',
        'completed_at',
        'closed_by',
        'rework_count',
        'review_due_at',
        'deadline_reminder_sent_at',
        'overdue_notified_at',
        'review_sla_notified_at',
        'spec_url',
        'result_url',
    ];

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if (! $task->isDirty('description') && ! $task->isDirty('description_format')) {
                return;
            }

            // Sanitize-on-write for HTML rows (defence in depth; render also sanitizes).
            // Runs before the plaintext shadow so description_text derives from the
            // sanitized value. Triggered when content OR format is dirty so a
            // format-only flip to html cannot leave raw markup in the column.
            // The conversion command writes via the query builder and bypasses model
            // events by design (CommonMark with html_input=escape is already inert).
            if ($task->resolvedDescriptionFormat() === ContentFormat::Html) {
                $task->description = app(HtmlContentService::class)
                    ->sanitize($task->description);
            }

            $task->description_text = app(HtmlContentService::class)
                ->toPlainText($task->description);
        });
    }

    public function resolvedDescriptionFormat(): ContentFormat
    {
        $format = $this->description_format;

        if ($format instanceof ContentFormat) {
            return $format;
        }

        if (is_string($format) && $format !== '') {
            return ContentFormat::tryFrom($format) ?? ContentFormat::Markdown;
        }

        return ContentFormat::Markdown;
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'description_format' => ContentFormat::class,
            'deadline' => 'datetime',
            'completed_at' => 'datetime',
            'review_due_at' => 'datetime',
            'deadline_reminder_sent_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'review_sla_notified_at' => 'datetime',
            'priority' => 'integer',
            'rework_count' => 'integer',
        ];
    }

    /**
     * Format-aware HTML for display (Markdown via CommonMark; HTML via sanitize-on-render).
     */
    public function renderedDescription(): string
    {
        return app(TaskContentService::class)->render(
            $this->description,
            $this->description_format,
        );
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function departmentInitiator(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_initiator_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('number');
    }

    public function isSubtask(): bool
    {
        return $this->parent_id !== null;
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TaskHistory::class)->latest();
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function isUrgent(): bool
    {
        return $this->priority >= 9;
    }

    /**
     * Plain-text description for previews. Uses the shadow column when present;
     * otherwise strips markup from the stored description on the fly.
     */
    public function plainDescription(): string
    {
        return $this->description_text
            ?? app(HtmlContentService::class)->toPlainText($this->description);
    }

    public function checklistProgress(): string
    {
        $total = $this->checklistItems->count();
        if ($total === 0) {
            return '';
        }

        $done = $this->checklistItems->where('is_done', true)->count();

        return "{$done}/{$total}";
    }

    public function subtaskProgress(): string
    {
        $total = $this->subtaskTotalCount();
        if ($total === 0) {
            return '';
        }

        return $this->subtaskCompletedCount().'/'.$total;
    }

    public function subtaskCompletedCount(): int
    {
        return $this->subtaskCollection()
            ->filter(fn (self $task) => $task->status === TaskStatus::Completed)
            ->count();
    }

    public function subtaskTotalCount(): int
    {
        return $this->subtaskCollection()->count();
    }

    public function subtaskProgressPercent(): int
    {
        $total = $this->subtaskTotalCount();
        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->subtaskCompletedCount() / $total) * 100);
    }

    /** @return Collection<int, self> */
    private function subtaskCollection()
    {
        return $this->relationLoaded('subtasks')
            ? $this->subtasks
            : $this->subtasks()->get();
    }

    public function openSubtasksCount(): int
    {
        return $this->subtaskCollection()
            ->filter(fn (self $task) => $task->status->isOpen())
            ->count();
    }
}
