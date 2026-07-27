<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function visibleDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'role_department_visibility');
    }

    /** @return list<string> */
    public function permissionList(): array
    {
        return $this->permissions()->pluck('permission')->all();
    }

    public function permissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function syncPermissions(array $permissions): void
    {
        $permissions = array_values(array_unique(array_filter(array_map(
            fn ($p) => $p instanceof Permission ? $p->value : (string) $p,
            $permissions
        ))));

        $this->permissions()->whereNotIn('permission', $permissions)->delete();

        foreach ($permissions as $permission) {
            $this->permissions()->firstOrCreate(['permission' => $permission]);
        }
    }

    public function syncVisibleDepartments(array $departmentIds): void
    {
        $this->visibleDepartments()->sync($departmentIds);
    }

    public function hasPermission(Permission|string $permission): bool
    {
        $value = $permission instanceof Permission ? $permission->value : $permission;

        return $this->permissions()->where('permission', $value)->exists();
    }
}
