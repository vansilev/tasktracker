<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Services\TaskWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_assignee_can_take_task_in_progress(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::ChangeStatus->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_user_without_change_status_cannot_transition(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::ChangeStatus->value
        );
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(AuthorizationException::class);
        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::InProgress);
    }

    public function test_rejected_transition_requires_comment(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::ChangeStatus->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(InvalidArgumentException::class);
        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::Rejected);
    }

    public function test_on_review_does_not_require_result_url(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::ChangeStatus->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), ['status' => TaskStatus::InProgress]);

        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::OnReview);

        $this->assertSame(TaskStatus::OnReview, $task->fresh()->status);
    }

    public function test_initiator_can_take_new_task_in_progress_when_not_assignee(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_rework_increments_counter(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::ChangeStatus->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Rework, 'Needs fixes');

        $this->assertSame(1, $task->fresh()->rework_count);
    }

    public function test_initiator_with_review_task_can_complete_from_on_review(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Completed);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_initiator_with_review_task_can_send_back_to_rework(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Rework, 'Needs fixes');

        $this->assertSame(TaskStatus::Rework, $task->fresh()->status);
    }

    public function test_initiator_without_review_task_can_complete_from_on_review(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::ReviewTask->value
        );
        $role = $this->createRoleWithPermissions(array_merge(array_values($perms), [Permission::ChangeStatus->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Completed);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_assignee_without_change_status_cannot_take_task_in_progress(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::ChangeStatus->value
        );
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(AuthorizationException::class);
        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::InProgress);
    }
}
