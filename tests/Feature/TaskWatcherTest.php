<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\User;
use App\Services\TaskService;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskWatcherTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_initiator_with_edit_own_can_manage_watchers(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertTrue($initiator->can('manageWatchers', $task));
    }

    public function test_user_with_edit_any_can_manage_watchers(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(
            [...$this->defaultPermissions(), Permission::EditAnyTask->value],
            [$dept->id],
        );
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $user = $this->createUserInDepartment($dept, 'Editor', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertTrue($user->can('manageWatchers', $task));
    }

    public function test_admin_can_manage_watchers(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $admin = User::factory()->create([
            'email' => 'admin-watchers@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($admin->can('manageWatchers', $task));
    }

    public function test_watcher_cannot_manage_watchers(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Watcher', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $task->watchers()->attach($watcher);

        $this->assertFalse($watcher->can('manageWatchers', $task));
    }

    public function test_plain_viewer_without_edit_cannot_manage_watchers(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');

        $viewOnlyRole = $this->createRoleWithPermissions(
            [
                Permission::ViewTask->value,
                Permission::CreateTask->value,
                Permission::Comment->value,
            ],
            [$deptB->id],
        );
        $defaultRole = $this->createRoleWithPermissions($this->defaultPermissions(), [$deptB->id]);

        $viewer = $this->createUserInDepartment($deptA, 'Viewer', role: $viewOnlyRole);
        $initiator = $this->createUserInDepartment($deptB, 'Initiator', role: $defaultRole);
        $assignee = $this->createUserInDepartment($deptB, 'Assignee', role: $defaultRole);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertTrue($viewer->can('view', $task));
        $this->assertFalse($viewer->can('manageWatchers', $task));
    }

    public function test_create_task_syncs_watchers(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher1 = $this->createUserInDepartment($dept, 'Watcher One', role: $role);
        $watcher2 = $this->createUserInDepartment($dept, 'Watcher Two', role: $role);
        $category = $this->createCategory();

        $task = app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $category->id,
                'title' => 'W',
                'description' => 'desc',
                'priority' => 5,
            ],
            [],
            [$watcher1->id, $watcher2->id],
        );

        $this->assertTrue($task->watchers()->where('users.id', $watcher1->id)->exists());
        $this->assertTrue($task->watchers()->where('users.id', $watcher2->id)->exists());
        $this->assertSame(2, $task->watchers()->count());
    }
}
