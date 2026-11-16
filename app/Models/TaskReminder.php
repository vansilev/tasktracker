<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One reminder already claimed for one person: the guard behind "once per task and threshold".
 */
class TaskReminder extends Model
{
    public const KIND_THRESHOLD = 'threshold';

    public const KIND_OVERDUE = 'overdue';

    public const KIND_ESCALATION = 'escalation';

    public const ROLE_RESPONSIBLE = 'responsible';

    public const ROLE_WATCHER = 'watcher';

    public const ROLE_HEAD = 'head';

    public const ROLE_DIGEST = 'digest';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** How many times one reminder is handed to the queue before it is given up. */
    public const MAX_ATTEMPTS = 3;

    /** A claim still pending after this many minutes is taken for lost (worker died, message held back). */
    public const STALE_MINUTES = 30;

    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'user_id', 'kind', 'mark', 'ref_date', 'deadline_date', 'role', 'status', 'attempts', 'claimed_at'];
}
