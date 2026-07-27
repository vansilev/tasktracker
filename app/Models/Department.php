<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'name',
        'head_user_id',
        'auto_assign_enabled',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'auto_assign_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function assignQueue(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_assign_queue')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function archive(): void
    {
        if ($this->users()->where('is_active', true)->exists()) {
            throw new \RuntimeException(__('Cannot archive department with active employees.'));
        }

        $this->update(['is_active' => false]);
    }
}
