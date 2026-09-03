<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedFilter extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'filters',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
