<?php

namespace App\Models;

use App\Enums\ContentFormat;
use App\Services\HtmlContentService;
use App\Services\TaskContentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskComment extends Model
{
    protected $fillable = [
        'task_id',
        'author_id',
        'body',
        // body_format is intentionally NOT fillable — set via attribute
        // assignment / forceFill on trusted write paths only.
        'edited_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (TaskComment $comment): void {
            if (! $comment->isDirty('body') && ! $comment->isDirty('body_format')) {
                return;
            }

            // Sanitize-on-write for HTML rows (defence in depth; render also sanitizes).
            // Runs before the plaintext shadow so body_text derives from the
            // sanitized value. Triggered when content OR format is dirty so a
            // format-only flip to html cannot leave raw markup in the column.
            // The conversion command writes via the query builder and bypasses model
            // events by design (CommonMark with html_input=escape is already inert).
            if ($comment->resolvedBodyFormat() === ContentFormat::Html) {
                $comment->body = app(HtmlContentService::class)
                    ->sanitize($comment->body);
            }

            $comment->body_text = app(HtmlContentService::class)
                ->toPlainText($comment->body);
        });
    }

    public function resolvedBodyFormat(): ContentFormat
    {
        $format = $this->body_format;

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
            'body_format' => ContentFormat::class,
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Format-aware HTML for display (Markdown via CommonMark; HTML via sanitize-on-render).
     */
    public function renderedBody(): string
    {
        return app(TaskContentService::class)->render(
            $this->body,
            $this->body_format,
        );
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function mentionedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_comment_mentions', 'task_comment_id', 'user_id')
            ->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'comment_id');
    }

    public function canBeEditedBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->author_id === $user->id
            && $this->created_at->gte(now()->subMinutes(15));
    }
}
