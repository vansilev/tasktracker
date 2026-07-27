<?php

namespace App\Console\Commands;

use App\Enums\AuthProvider;
use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\UserLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportOrgHierarchy extends Command
{
    protected $signature = 'import:org-hierarchy {--dry-run : Preview without writing}';

    protected $description = 'Import org hierarchy users from approved 2026-07-10 plan';

    /** @return list<array{name: string, email: string, password: string, department: string, system_type: string, role: string, head_of?: string}> */
    private function users(): array
    {
        return [
            ['name' => 'Юлия Станиславовна Васильева', 'email' => 'nys2309@gmail.com', 'password' => 'Avt39kfm6dt!', 'department' => 'Операционный отдел', 'system_type' => 'user', 'role' => 'Обзор (CEO)'],
            ['name' => 'Коханский Александр Анатол-ч', 'email' => 'head.education@tcsavant.com', 'password' => 'Avtdrx9487z!', 'department' => 'Учебный отдел', 'system_type' => 'dept_head', 'role' => 'Сотрудник', 'head_of' => 'Учебный отдел'],
            ['name' => 'Колотуп Татьяна Николаевна', 'email' => 'training@tcsavant.com', 'password' => 'Avtw6x4hnk9!', 'department' => 'Учебный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Назарова Ирина', 'email' => 'elearning@tcsavant.com', 'password' => 'Avtmtd635sh!', 'department' => 'Учебный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Григоренко Лиза', 'email' => 'training2@tcsavant.com', 'password' => 'Avtfzvwau3k!', 'department' => 'Учебный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Бабкина Наталья', 'email' => 'training3@tcsavant.com', 'password' => 'Avtu8ag9dx6!', 'department' => 'Учебный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Анна Николаевна', 'email' => 'kolunya54@ukr.net', 'password' => 'Avte2a9p83x!', 'department' => 'Учебный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Саламаха Сергей Викторович', 'email' => 'rop@tcsavant.com', 'password' => 'Avtnqkrwz8g!', 'department' => 'Отдел продаж', 'system_type' => 'dept_head', 'role' => 'Продажи', 'head_of' => 'Отдел продаж'],
            ['name' => 'Криворучко Анастасия', 'email' => 'manager@tcsavant.com', 'password' => 'Avt3bk8fdp2!', 'department' => 'Отдел продаж', 'system_type' => 'user', 'role' => 'Продажи'],
            ['name' => 'Фадеева Виктория', 'email' => 'manager2@tcsavant.com', 'password' => 'Avt39fgdeuc!', 'department' => 'Отдел продаж', 'system_type' => 'user', 'role' => 'Продажи'],
            ['name' => 'Петрова Яна', 'email' => 'manager3@tcsavant.com', 'password' => 'Avtxkutpj69!', 'department' => 'Отдел продаж', 'system_type' => 'user', 'role' => 'Продажи'],
            ['name' => 'Зинаида Федоровна', 'email' => 'accounting@tcsavant.com', 'password' => 'Avt6pxwmrs7!', 'department' => 'Финансовый отдел', 'system_type' => 'dept_head', 'role' => 'Сотрудник', 'head_of' => 'Финансовый отдел'],
            ['name' => 'Ирина Викторовна', 'email' => 'accounting2@tcsavant.com', 'password' => 'Avtgy796pzf!', 'department' => 'Финансовый отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Буряковская Анна', 'email' => 'assistant@tcsavant.com', 'password' => 'Avtde9p3zg8!', 'department' => 'Операционный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Людмила Антоновна', 'email' => 'info@tcsavant.com', 'password' => 'Avtq7e3ahzd!', 'department' => 'Операционный отдел', 'system_type' => 'user', 'role' => 'Сотрудник'],
            ['name' => 'Денис', 'email' => 'keys@tcsavant.com', 'password' => 'Avt945wn2sg!', 'department' => 'IT', 'system_type' => 'user', 'role' => 'IT'],
            ['name' => 'Наумов Александр', 'email' => 'it@tcsavant.com', 'password' => 'Avt6am8g5bc!', 'department' => 'IT', 'system_type' => 'user', 'role' => 'IT'],
            ['name' => 'Артем Сонько', 'email' => 'artemsonko7@gmail.com', 'password' => 'Avtqc4guew8!', 'department' => 'Отдел маркетинга', 'system_type' => 'dept_head', 'role' => 'Маркетинг', 'head_of' => 'Отдел маркетинга'],
            ['name' => 'Дмитрий', 'email' => 'meshman2@gmail.com', 'password' => 'Avtk82m79tu!', 'department' => 'Отдел маркетинга', 'system_type' => 'user', 'role' => 'Маркетинг'],
        ];
    }

    public function handle(UserLifecycleService $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no changes will be written.');
        }

        $departments = Department::query()->pluck('id', 'name');
        $roles = Role::query()->pluck('id', 'name');

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($dryRun, $departments, $roles, &$created, &$skipped, $lifecycle) {
            $ceoRole = $this->ensureCeoRole($roles, $departments, $dryRun);

            foreach ($this->users() as $row) {
                $email = strtolower($row['email']);

                if (User::query()->where('email', $email)->exists()) {
                    $this->line("SKIP exists: {$email}");
                    $skipped++;

                    continue;
                }

                $departmentId = $departments[$row['department']] ?? null;
                if ($departmentId === null) {
                    $this->error("Unknown department: {$row['department']} for {$email}");

                    throw new \RuntimeException("Department not found: {$row['department']}");
                }

                $roleName = $row['role'];
                $roleId = $roleName === 'Обзор (CEO)'
                    ? ($ceoRole?->id ?? $roles->get('Обзор (CEO)'))
                    : ($roles->get($roleName));

                if ($roleId === null && ! ($dryRun && $roleName === 'Обзор (CEO)')) {
                    throw new \RuntimeException("Role not found: {$roleName}");
                }

                if ($dryRun) {
                    $this->line("CREATE {$row['name']} <{$email}> dept={$row['department']} type={$row['system_type']} role={$roleName}");
                    $created++;

                    continue;
                }

                $user = User::query()->create([
                    'name' => $row['name'],
                    'email' => $email,
                    'password' => Hash::make($row['password']),
                    'email_verified_at' => now(),
                    'system_type' => SystemType::from($row['system_type']),
                    'department_id' => $departmentId,
                    'auth_provider' => AuthProvider::Password,
                    'locale' => 'ru',
                    'is_active' => true,
                ]);

                $user->syncRoles([$roleId]);

                if (! empty($row['head_of'])) {
                    $headDeptId = $departments[$row['head_of']] ?? null;
                    if ($headDeptId) {
                        $lifecycle->syncDepartmentHead(
                            Department::query()->findOrFail($headDeptId),
                            $user->id,
                        );
                    }
                }

                $this->info("Created: {$email}");
                $created++;
            }

            $this->assignItHead($departments, $lifecycle, $dryRun);
        });

        $this->info(($dryRun ? 'Would create' : 'Created').": {$created}, skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function ensureCeoRole($roles, $departments, bool $dryRun): ?Role
    {
        if ($roles->has('Обзор (CEO)')) {
            $role = Role::query()->where('name', 'Обзор (CEO)')->firstOrFail();
        } elseif ($dryRun) {
            $this->line('CREATE role: Обзор (CEO) with all department visibility');

            return null;
        } else {
            $role = Role::query()->create([
                'name' => 'Обзор (CEO)',
                'description' => 'Видимость всех отделов без доступа к /admin',
                'is_active' => true,
            ]);
            $this->info('Created role: Обзор (CEO)');
        }

        if (! $dryRun && $role) {
            $permissions = array_map(
                fn (Permission $p) => $p->value,
                array_merge(Permission::defaults(), [Permission::EditAnyTask]),
            );
            $role->syncPermissions($permissions);
            $role->syncVisibleDepartments($departments->values()->all());
        }

        return $role ?? null;
    }

    private function assignItHead($departments, UserLifecycleService $lifecycle, bool $dryRun): void
    {
        $itDeptId = $departments['IT'] ?? null;
        $adminEmail = strtolower((string) config('tasktracker.admin_email'));

        if (! $itDeptId) {
            throw new \RuntimeException('IT department not found');
        }

        $maxim = User::query()->where('email', $adminEmail)->first();
        if (! $maxim) {
            throw new \RuntimeException("Admin user not found: {$adminEmail}");
        }

        if ($dryRun) {
            $this->line("ASSIGN IT head: {$adminEmail}");

            return;
        }

        if ($maxim->department_id !== $itDeptId) {
            $maxim->update(['department_id' => $itDeptId]);
        }

        $itRole = Role::query()->where('name', 'IT')->first();
        if ($itRole) {
            $maxim->roles()->syncWithoutDetaching([$itRole->id]);
        }

        $lifecycle->syncDepartmentHead(Department::query()->findOrFail($itDeptId), $maxim->id);
        $this->info("Assigned IT head: {$adminEmail}");
    }
}
