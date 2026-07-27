<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\UserLifecycleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class UserDepartmentTransferTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_transfer_assignee_syncs_open_task_department_and_logs_history(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::InProgress,
        ]);

        $before = TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'department_id')
            ->count();

        app(UserLifecycleService::class)->updateUser($assignee, [
            'name' => $assignee->name,
            'department_id' => $deptB->id,
            'system_type' => SystemType::User->value,
        ], $assignee->roles->pluck('id')->all());

        $task->refresh();
        $this->assertSame($deptB->id, $task->department_id);
        $this->assertSame($before + 1, TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'department_id')
            ->count());

        $entry = TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'department_id')
            ->latest('id')
            ->first();

        $this->assertSame((string) $deptA->id, $entry->old_value);
        $this->assertSame((string) $deptB->id, $entry->new_value);
        $this->assertSame($assignee->id, $entry->changed_by);
    }

    public function test_transfer_assignee_does_not_change_closed_task_department(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);

        app(UserLifecycleService::class)->updateUser($assignee, [
            'name' => $assignee->name,
            'department_id' => $deptB->id,
            'system_type' => SystemType::User->value,
        ], $assignee->roles->pluck('id')->all());

        $task->refresh();
        $this->assertSame($deptA->id, $task->department_id);
        $this->assertSame(0, TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'department_id')
            ->count());
    }

    public function test_update_without_department_change_does_not_touch_tasks(): void
    {
        $dept = $this->createDepartment('Dept A');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::InProgress,
        ]);

        app(UserLifecycleService::class)->updateUser($assignee, [
            'name' => 'Renamed Assignee',
            'department_id' => $dept->id,
            'system_type' => SystemType::User->value,
        ], $assignee->roles->pluck('id')->all());

        $task->refresh();
        $this->assertSame($dept->id, $task->department_id);
        $this->assertSame(0, TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'department_id')
            ->count());
    }

    public function test_cannot_remove_department_of_user_with_open_assigned_tasks(): void
    {
        $dept = $this->createDepartment('Dept A');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::InProgress,
        ]);

        try {
            app(UserLifecycleService::class)->updateUser($assignee, [
                'name' => $assignee->name,
                'department_id' => null,
                'system_type' => SystemType::User->value,
            ], $assignee->roles->pluck('id')->all());
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('department_id', $e->errors());
            $this->assertSame($dept->id, $assignee->fresh()->department_id);
            $this->assertSame($dept->id, $task->fresh()->department_id);

            return;
        }

        $this->fail('Expected ValidationException was not thrown.');
    }

    public function test_user_without_open_tasks_can_move_to_null_department(): void
    {
        $dept = $this->createDepartment('Dept A');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);

        app(UserLifecycleService::class)->updateUser($assignee, [
            'name' => $assignee->name,
            'department_id' => null,
            'system_type' => SystemType::User->value,
        ], $assignee->roles->pluck('id')->all());

        $this->assertNull($assignee->fresh()->department_id);
        $this->assertSame($dept->id, $task->fresh()->department_id);
    }

    public function test_cannot_change_system_type_of_last_active_administrator(): void
    {
        $admin = User::factory()->create([
            'email' => 'only-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        try {
            app(UserLifecycleService::class)->updateUser($admin, [
                'name' => $admin->name,
                'department_id' => null,
                'system_type' => SystemType::User->value,
            ], []);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('system_type', $e->errors());
            $this->assertSame(SystemType::Admin, $admin->fresh()->system_type);

            return;
        }

        $this->fail('Expected ValidationException was not thrown.');
    }

    public function test_can_change_system_type_when_another_active_admin_exists(): void
    {
        User::factory()->create([
            'email' => 'admin-one@tcsavant.com',
            'system_type' => SystemType::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $admin = User::factory()->create([
            'email' => 'admin-two@tcsavant.com',
            'system_type' => SystemType::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        app(UserLifecycleService::class)->updateUser($admin, [
            'name' => $admin->name,
            'department_id' => null,
            'system_type' => SystemType::User->value,
        ], []);

        $this->assertSame(SystemType::User, $admin->fresh()->system_type);
    }
}
