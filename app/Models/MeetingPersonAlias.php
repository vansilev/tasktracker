<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingPersonAlias extends Model
{
    protected $fillable = ['alias', 'user_id', 'is_external', 'set_by'];

    protected function casts(): array
    {
        return ['is_external' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
