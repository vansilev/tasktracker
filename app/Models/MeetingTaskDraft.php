<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A task the model found in a meeting; becomes a real Task only after a person confirms it. */
class MeetingTaskDraft extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CREATED = 'created';

    public const STATUS_DISMISSED = 'dismissed';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'meeting_id',
        'title',
        'description',
        'assignee_id',
        'assignment_reason',
        'mentioned_name',
        'mentioned_user_id',
        'speaker_name',
        'speaker_user_id',
        'deadline',
        'deadline_phrase',
        'quote',
        'start_seconds',
        'status',
        'task_id',
        'created_by',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'start_seconds' => 'float',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    public function speakerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'speaker_user_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
