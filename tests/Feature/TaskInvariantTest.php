<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskInvariantTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_department_id_follows_assignee_on_create(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $task = app(TaskService::class)->create($initiator, [
            'department_id' => $dept->id,
            'assignee_id' => $assignee->id,
            'category_id' => $category->id,
            'title' => 'Invariant test',
            'description' => 'Description',
            'priority' => 5,
        ]);

        $this->assertSame($assignee->department_id, $task->department_id);
    }

    public function test_cannot_update_department_id_without_changing_assignee(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value]
        ), [$deptA->id]);
        $admin = $this->createUserInDepartment($deptA, 'Admin', role: $role);
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(ValidationException::class);
        app(TaskService::class)->update($task, $admin, ['department_id' => $deptB->id]);

        $this->assertSame($deptA->id, $task->fresh()->department_id);
    }

    public function test_department_id_updates_only_when_assignee_changes(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value]
        ), [$deptA->id, $deptB->id]);
        $admin = $this->createUserInDepartment($deptA, 'Admin', role: $role);
        $assigneeA = $this->createUserInDepartment($deptA, 'Assignee A', role: $role);
        $assigneeB = $this->createUserInDepartment($deptB, 'Assignee B', role: $role);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assigneeA, $this->createCategory());

        app(TaskService::class)->update($task, $admin, ['assignee_id' => $assigneeB->id]);

        $this->assertSame($deptB->id, $task->fresh()->department_id);
        $this->assertSame($assigneeB->id, $task->fresh()->assignee_id);
    }

    public function test_cannot_create_task_in_archived_department(): void
    {
        $dept = $this->createDepartment();
        $dept->update(['is_active' => false]);
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($this->createDepartment('Active'), 'Initiator', role: $role);
        $category = $this->createCategory();

        $this->expectException(ValidationException::class);
        app(TaskService::class)->create($initiator, [
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Archived dept',
            'description' => 'Description',
            'priority' => 5,
        ]);
    }

    public function test_cannot_create_task_with_archived_category(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $category = Category::query()->create([
            'name' => 'Archived cat',
            'is_active' => false,
            'sort_order' => 1,
        ]);

        $this->expectException(ValidationException::class);
        app(TaskService::class)->create($initiator, [
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Archived cat',
            'description' => 'Description',
            'priority' => 5,
        ]);
    }

    public function test_assignee_must_belong_to_selected_department(): void
    {
        $deptA = $this->createDepartment('A');
        $deptB = $this->createDepartment('B');
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::CreateTaskAnyDepartment->value]
        ));
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($deptB, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->expectException(ValidationException::class);
        app(TaskService::class)->create($initiator, [
            'department_id' => $deptA->id,
            'assignee_id' => $assignee->id,
            'category_id' => $category->id,
            'title' => 'Mismatch',
            'description' => 'Description',
            'priority' => 5,
        ]);
    }

    public function test_cannot_assign_to_user_without_department(): void
    {
        $dept = $this->createDepartment('Dept A');
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value]
        ), [$dept->id]);
        $admin = $this->createUserInDepartment($dept, 'Admin', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $noDeptUser = User::factory()->create([
            'name' => 'No Dept',
            'email' => 'nodept@tcsavant.com',
            'department_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $noDeptUser->roles()->attach($role);

        $this->expectException(ValidationException::class);
        app(TaskService::class)->update($task, $admin, ['assignee_id' => $noDeptUser->id]);

        $fresh = $task->fresh();
        $this->assertSame($assignee->id, $fresh->assignee_id);
        $this->assertSame($dept->id, $fresh->department_id);
    }

    public function test_assignee_change_with_matching_department_id_is_allowed(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value]
        ), [$deptA->id, $deptB->id]);
        $admin = $this->createUserInDepartment($deptA, 'Admin', role: $role);
        $assigneeA = $this->createUserInDepartment($deptA, 'Assignee A', role: $role);
        $assigneeB = $this->createUserInDepartment($deptB, 'Assignee B', role: $role);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assigneeA, $this->createCategory());

        app(TaskService::class)->update($task, $admin, [
            'assignee_id' => $assigneeB->id,
            'department_id' => $deptB->id,
        ]);

        $fresh = $task->fresh();
        $this->assertSame($assigneeB->id, $fresh->assignee_id);
        $this->assertSame($deptB->id, $fresh->department_id);
    }
}
