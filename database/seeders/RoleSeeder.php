<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            'Сотрудник' => [
                'description' => 'Базовые права для всех сотрудников',
                'permissions' => Permission::defaults(),
                'departments' => [],
            ],
            'Продажи' => [
                'description' => 'Отдел продаж',
                'permissions' => array_merge(
                    array_map(fn (Permission $p) => $p->value, Permission::defaults()),
                    [Permission::AssignTask->value, Permission::ChangeStatus->value]
                ),
                'departments' => ['Отдел продаж'],
            ],
            'Маркетинг' => [
                'description' => 'Отдел маркетинга',
                'permissions' => array_map(fn (Permission $p) => $p->value, Permission::defaults()),
                'departments' => ['Отдел маркетинга'],
            ],
            'IT' => [
                'description' => 'IT-подразделение',
                'permissions' => array_map(fn (Permission $p) => $p->value, Permission::cases()),
                'departments' => ['IT'],
            ],
        ];

        foreach ($definitions as $name => $config) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name],
                ['description' => $config['description'], 'is_active' => true]
            );

            $role->syncPermissions($config['permissions']);

            $departmentIds = Department::query()
                ->whereIn('name', $config['departments'])
                ->pluck('id')
                ->all();

            $role->syncVisibleDepartments($departmentIds);
        }
    }
}
