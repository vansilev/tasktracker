<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Services\TaskWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskBootstrapWorkflowTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_bootstrap_assignee_can_take_task_in_progress(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_bootstrap_initiator_can_return_awaiting_initiator_to_in_progress(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::AwaitingInitiator,
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_bootstrap_initiator_cannot_cancel_without_comment(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(InvalidArgumentException::class);
        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Cancelled);
    }

    public function test_bootstrap_initiator_can_cancel_with_comment(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Cancelled, 'комментарий');

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
    }

    public function test_bootstrap_initiator_can_reopen_completed_with_comment(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
            'closed_by' => $initiator->id,
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::InProgress, 'комментарий');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_bootstrap_initiator_cannot_reopen_completed_without_comment(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
            'closed_by' => $initiator->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::InProgress);
    }

    public function test_bootstrap_outsider_cannot_transition_task(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $outsider = $this->createUserInDepartment($dept, 'Outsider');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $workflow = app(TaskWorkflowService::class);

        $this->assertSame([], $workflow->allowedTransitions($outsider, $task));

        $this->expectException(AuthorizationException::class);
        $workflow->transition($task, $outsider, TaskStatus::InProgress);
    }

    public function test_bootstrap_initiator_can_complete_from_on_review(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition($task, $initiator, TaskStatus::Completed);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }
}
