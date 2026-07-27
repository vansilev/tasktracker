<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskRbacTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_initiator_can_view_own_task(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertTrue($initiator->can('view', $task));
    }

    public function test_watcher_can_view_task(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Watcher', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $task->watchers()->attach($watcher);

        $this->assertTrue($watcher->can('view', $task));
    }

    public function test_dept_head_sees_department_tasks(): void
    {
        $head = User::factory()->create([
            'email' => 'head@tcsavant.com',
            'system_type' => SystemType::DeptHead,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment('Sales', $head);
        $head->update(['department_id' => $dept->id]);

        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertTrue($head->can('view', $task));
    }

    public function test_role_visibility_grants_department_access(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$deptB->id]);
        $viewer = $this->createUserInDepartment($deptA, 'Viewer', role: $role);
        $initiator = $this->createUserInDepartment($deptB, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($deptB, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertTrue($viewer->can('view', $task));
    }

    public function test_assignee_without_edit_permission_cannot_update_task(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter($this->defaultPermissions(), fn ($p) => $p !== Permission::EditOwnTask->value);
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->update($task, $assignee, ['description' => 'Changed']);
    }

    public function test_user_without_roles_has_bootstrap_create_permission(): void
    {
        $user = User::factory()->create([
            'email' => 'bootstrap@tcsavant.com',
            'department_id' => null,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('create', Task::class));
    }

    public function test_dept_head_can_change_priority_without_edit_own(): void
    {
        $head = User::factory()->create([
            'email' => 'head2@tcsavant.com',
            'system_type' => SystemType::DeptHead,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment('Headed', $head);
        $head->update(['department_id' => $dept->id]);

        $perms = array_filter($this->defaultPermissions(), fn ($p) => $p !== Permission::EditOwnTask->value);
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Other Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), ['priority' => 5]);

        $this->assertTrue($head->can('changePriority', $task));

        app(TaskService::class)->update($task, $head, ['priority' => 8]);

        $this->assertSame(8, $task->fresh()->priority);
    }
}
