<?php

namespace App\Models;

use App\Enums\AuthProvider;
use App\Enums\Permission;
use App\Enums\SystemType;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'password',
    'system_type',
    'department_id',
    'locale',
    'telegram_chat_id',
    'telegram_username',
    'auth_provider',
    'google_id',
    'avatar',
    'is_active',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'system_type' => SystemType::class,
            'auth_provider' => AuthProvider::class,
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function headedDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'head_user_id');
    }

    public function isAdmin(): bool
    {
        return $this->system_type === SystemType::Admin;
    }

    public function isDeptHead(): bool
    {
        return $this->system_type === SystemType::DeptHead;
    }

    public function hasPermission(Permission|string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $value = $permission instanceof Permission ? $permission->value : $permission;

        $activeRoles = $this->roles()->where('is_active', true);

        if (! $activeRoles->exists()) {
            $defaults = array_map(fn (Permission $p) => $p->value, Permission::defaults());

            return in_array($value, $defaults, true);
        }

        return $activeRoles
            ->whereHas('permissions', fn ($q) => $q->where('permission', $value))
            ->exists();
    }

    /** @return Collection<int, int> */
    public function visibleDepartmentIds(): Collection
    {
        if ($this->isAdmin()) {
            return Department::query()->pluck('id');
        }

        return $this->roles()
            ->where('is_active', true)
            ->with('visibleDepartments:id')
            ->get()
            ->flatMap(fn (Role $role) => $role->visibleDepartments->pluck('id'))
            ->unique()
            ->values();
    }

    public function syncRoles(array $roleIds): void
    {
        $this->roles()->sync($roleIds);
    }

    public function preferredLocale(): string
    {
        return $this->locale ?: (string) config('app.locale');
    }

    public function routeNotificationForTelegram(): ?string
    {
        return $this->telegram_chat_id;
    }

    public function hasTelegramLinked(): bool
    {
        return filled($this->telegram_chat_id);
    }
}
