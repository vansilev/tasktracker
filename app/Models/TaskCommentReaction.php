<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCommentReaction extends Model
{
    /** @var list<string> */
    public const EMOJIS = [
        '👍', '👎', '❤️', '🔥', '😄', '😂', '😮', '😢',
        '🎉', '👀', '🙏', '👏', '✅', '❌', '💯', '🤔',
        '💪', '🚀', '⭐', '💡', '🤝', '👌', '😍', '😅',
    ];

    protected $fillable = [
        'task_comment_id',
        'user_id',
        'emoji',
    ];

    public static function isAllowed(string $emoji): bool
    {
        return in_array($emoji, self::EMOJIS, true);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'task_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
