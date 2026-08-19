<?php

namespace Tests\Feature;

use App\Services\TaskAssignmentService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskAssignmentTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_defaults_to_department_head_when_no_assignee_and_no_queue(): void
    {
        $dept = $this->createDepartment();
        $head = $this->createUserInDepartment($dept, 'Head');
        $dept->update(['head_user_id' => $head->id]);
        $dept->refresh();

        $assignee = app(TaskAssignmentService::class)->resolveAssignee($dept);

        $this->assertSame($head->id, $assignee->id);
    }

    public function test_round_robin_rotates_when_auto_assign_enabled(): void
    {
        $dept = $this->createDepartment();
        $userA = $this->createUserInDepartment($dept, 'User A');
        $userB = $this->createUserInDepartment($dept, 'User B');
        $dept->update(['auto_assign_enabled' => true]);
        $dept->assignQueue()->sync([
            $userA->id => ['sort_order' => 0],
            $userB->id => ['sort_order' => 1],
        ]);

        $service = app(TaskAssignmentService::class);

        $first = $service->resolveAssignee($dept);
        $this->assertSame($userA->id, $first->id);

        $second = $service->resolveAssignee($dept->fresh());
        $this->assertSame($userB->id, $second->id);
    }

    public function test_falls_back_to_queue_when_no_head_and_auto_assign_off(): void
    {
        $dept = $this->createDepartment();
        $userA = $this->createUserInDepartment($dept, 'User A');
        $dept->assignQueue()->sync([
            $userA->id => ['sort_order' => 0],
        ]);

        $assignee = app(TaskAssignmentService::class)->resolveAssignee($dept);

        $this->assertSame($userA->id, $assignee->id);
    }

    public function test_throws_when_no_head_and_no_queue(): void
    {
        $dept = $this->createDepartment();

        $this->expectException(\RuntimeException::class);
        app(TaskAssignmentService::class)->resolveAssignee($dept);
    }

    public function test_rejects_inactive_explicit_assignee(): void
    {
        $dept = $this->createDepartment();
        $head = $this->createUserInDepartment($dept, 'Head');
        $dept->update(['head_user_id' => $head->id]);
        $dept->refresh();

        $user = $this->createUserInDepartment($dept, 'Inactive User');
        $user->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(TaskAssignmentService::class)->resolveAssignee($dept, $user->id);
    }

    public function test_rejects_explicit_assignee_from_other_department(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $userB = $this->createUserInDepartment($deptB, 'User B');

        $this->expectException(ValidationException::class);
        app(TaskAssignmentService::class)->resolveAssignee($deptA, $userB->id);
    }

    public function test_returns_explicit_active_assignee_from_same_department(): void
    {
        $dept = $this->createDepartment();
        $userA = $this->createUserInDepartment($dept, 'User A');

        $assignee = app(TaskAssignmentService::class)->resolveAssignee($dept, $userA->id);

        $this->assertSame($userA->id, $assignee->id);
    }
}
