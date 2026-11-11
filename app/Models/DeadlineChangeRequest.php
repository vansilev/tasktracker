<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Please move the deadline", asked from Telegram and decided by the task's initiator.
 */
class DeadlineChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** The task's deadline changed while the request was waiting: nothing is applied. */
    public const STATUS_STALE = 'stale';

    protected $fillable = ['task_id', 'requested_by', 'approver_id', 'old_deadline', 'new_deadline', 'reason', 'status', 'decided_at'];

    protected function casts(): array
    {
        return [
            'old_deadline' => 'datetime',
            'new_deadline' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
