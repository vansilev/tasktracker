<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per notification handed to a channel: who, what, when, about which task, and whether it went out.
 * The message text is deliberately not stored.
 */
class NotificationDelivery extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'task_id',
        'event',
        'channel',
        'status',
        'error',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
