<?php

namespace Tests\Support;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

trait CreatesTaskTrackerFixtures
{
    use RefreshDatabase;

    protected function createDepartment(string $name = 'IT', ?User $head = null): Department
    {
        $dept = Department::query()->create([
            'name' => $name,
            'is_active' => true,
            'auto_assign_enabled' => false,
        ]);

        if ($head) {
            $dept->update(['head_user_id' => $head->id]);
        }

        return $dept;
    }

    protected function createUserInDepartment(
        Department $department,
        string $name = 'User',
        SystemType $type = SystemType::User,
        ?Role $role = null,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@tcsavant.com',
            'department_id' => $department->id,
            'system_type' => $type,
            'email_verified_at' => now(),
        ]);

        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    protected function createRoleWithPermissions(array $permissions, array $visibleDepartmentIds = []): Role
    {
        $role = Role::query()->create([
            'name' => 'Test Role '.uniqid(),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissions);
        $role->syncVisibleDepartments($visibleDepartmentIds);

        return $role;
    }

    protected function createCategory(): Category
    {
        return Category::query()->create([
            'name' => 'Test Category '.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function createTask(
        User $initiator,
        User $assignee,
        Category $category,
        array $overrides = [],
    ): Task {
        $format = $overrides['description_format'] ?? null;
        unset($overrides['description_format']);

        $task = new Task(array_merge([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $initiator->department_id,
            'department_id' => $assignee->department_id,
            'category_id' => $category->id,
            'title' => 'Test task',
            'description' => 'Task description',
            'priority' => 5,
            'status' => TaskStatus::New,
        ], $overrides));

        if ($format !== null) {
            // description_format is not mass-assignable.
            $task->description_format = $format;
        }

        $task->save();

        return $task;
    }

    /** @return list<string> */
    protected function defaultPermissions(): array
    {
        return array_map(fn (Permission $p) => $p->value, Permission::defaults());
    }
}
