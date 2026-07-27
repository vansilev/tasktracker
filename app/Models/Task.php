<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'title',
        'description',
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

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
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

    public function checklistProgress(): string
    {
        $total = $this->checklistItems->count();
        if ($total === 0) {
            return '';
        }

        $done = $this->checklistItems->where('is_done', true)->count();

        return "{$done}/{$total}";
    }
}
