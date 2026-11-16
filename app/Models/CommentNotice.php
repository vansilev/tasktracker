<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment, or a mention in it, owed to one person in Telegram. For a mention it also keeps
 * whether the person has reacted — the base of "mentioned and not answered".
 */
class CommentNotice extends Model
{
    public const KIND_MENTION = 'mention';

    public const KIND_COMMENT = 'comment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'task_id', 'comment_id', 'user_id', 'author_id', 'kind', 'role', 'status', 'send_after', 'sent_at',
        'attempts', 'telegram_message_id', 'acknowledged_at', 'ack_via', 'reminded_at', 'author_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'reminded_at' => 'datetime',
            'author_notified_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
