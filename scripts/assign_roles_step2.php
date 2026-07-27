<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    $employeeRole = Role::query()->where('name', 'Сотрудник')->firstOrFail();
    $salesRole = Role::query()->where('name', 'Продажи')->firstOrFail();
    $marketingRole = Role::query()->where('name', 'Маркетинг')->firstOrFail();

    foreach ([$employeeRole, $marketingRole] as $role) {
        $role->permissions()->firstOrCreate(['permission' => 'change_status']);
    }

    User::query()
        ->where('is_active', true)
        ->whereDoesntHave('roles')
        ->each(function (User $user) use ($employeeRole): void {
            $user->roles()->syncWithoutDetaching([$employeeRole->id]);
        });

    $salesDeptId = Department::query()->where('name', 'Отдел продаж')->value('id');
    if ($salesDeptId) {
        User::query()
            ->where('is_active', true)
            ->where('department_id', $salesDeptId)
            ->each(function (User $user) use ($salesRole): void {
                $user->roles()->syncWithoutDetaching([$salesRole->id]);
            });
    }

    $marketingDeptId = Department::query()->where('name', 'Отдел маркетинга')->value('id');
    if ($marketingDeptId) {
        User::query()
            ->where('is_active', true)
            ->where('department_id', $marketingDeptId)
            ->each(function (User $user) use ($marketingRole): void {
                $user->roles()->syncWithoutDetaching([$marketingRole->id]);
            });
    }
});

$employeeRole = Role::query()->where('name', 'Сотрудник')->firstOrFail();
$marketingRole = Role::query()->where('name', 'Маркетинг')->firstOrFail();

$users = User::query()
    ->where('is_active', true)
    ->with(['department:id,name', 'roles:id,name'])
    ->orderBy('name')
    ->get()
    ->map(fn (User $user) => [
        'name' => $user->name,
        'department' => $user->department?->name,
        'roles' => $user->roles->pluck('name')->sort()->values()->all(),
    ])
    ->all();

$report = [
    'users' => $users,
    'roles' => [
        'Сотрудник' => [
            'permissions' => $employeeRole->permissionList(),
        ],
        'Маркетинг' => [
            'permissions' => $marketingRole->permissionList(),
        ],
    ],
    'active_users_without_roles' => User::query()
        ->where('is_active', true)
        ->whereDoesntHave('roles')
        ->count(),
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
