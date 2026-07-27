<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\UserLifecycleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_deactivation_reassigns_open_tasks_to_head(): void
    {
        $head = User::factory()->create([
            'email' => 'head@tcsavant.com',
            'system_type' => SystemType::DeptHead,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment('Ops', $head);
        $head->update(['department_id' => $dept->id]);

        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), ['status' => TaskStatus::InProgress]);

        app(UserLifecycleService::class)->deactivate($assignee);

        $task->refresh();
        $this->assertSame($head->id, $task->assignee_id);
        $this->assertFalse($assignee->fresh()->is_active);
    }

    public function test_deactivation_blocked_without_active_head(): void
    {
        $dept = $this->createDepartment('No Head');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $this->createTask($initiator, $assignee, $this->createCategory(), ['status' => TaskStatus::InProgress]);

        $this->expectException(ValidationException::class);
        app(UserLifecycleService::class)->deactivate($assignee);
    }
}
