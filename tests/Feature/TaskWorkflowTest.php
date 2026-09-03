<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
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

    public function test_admin_can_take_new_task_in_progress_when_not_assignee(): void
    {
        $dept = $this->createDepartment();
        $initiator = $this->createUserInDepartment($dept, 'Workflow Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Workflow Assignee');
        $admin = $this->createUserInDepartment($dept, 'Workflow Admin', SystemType::Admin);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $admin, TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_department_head_can_take_new_task_in_progress(): void
    {
        $head = User::factory()->create([
            'name' => 'Workflow Head',
            'email' => 'workflow.head@tcsavant.com',
            'system_type' => SystemType::DeptHead,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment('IT', $head);
        $head->update(['department_id' => $dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Headed Initiator');
        $assignee = $this->createUserInDepartment($dept, 'Headed Assignee');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $head->fresh(), TaskStatus::InProgress);

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

    public function test_undo_restores_previous_status_without_workflow_reverse(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Undo User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory());
        $workflow = app(TaskWorkflowService::class);

        $item = $workflow->transition($task, $user, TaskStatus::InProgress);
        $token = $workflow->issueUndoToken($user, [$item]);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame(1, $workflow->undo($user, $token));
        $this->assertSame(TaskStatus::New, $task->fresh()->status);
    }

    public function test_undo_token_cannot_be_reused(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Undo Once User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory());
        $workflow = app(TaskWorkflowService::class);
        $token = $workflow->issueUndoToken($user, [
            $workflow->transition($task, $user, TaskStatus::InProgress),
        ]);

        $this->assertSame(1, $workflow->undo($user, $token));
        $this->expectException(InvalidArgumentException::class);
        $workflow->undo($user, $token);
    }

    public function test_undo_token_is_tied_to_the_actor(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $actor = $this->createUserInDepartment($dept, 'Undo Actor', role: $role);
        $other = $this->createUserInDepartment($dept, 'Undo Other', role: $role);
        $task = $this->createTask($actor, $actor, $this->createCategory());
        $workflow = app(TaskWorkflowService::class);
        $token = $workflow->issueUndoToken($actor, [
            $workflow->transition($task, $actor, TaskStatus::InProgress),
        ]);

        try {
            $workflow->undo($other, $token);
            $this->fail('Other users must not consume the undo token.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_undo_restores_review_due_at_snapshot(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Undo Review User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'status' => TaskStatus::InProgress,
        ]);
        $workflow = app(TaskWorkflowService::class);
        $token = $workflow->issueUndoToken($user, [
            $workflow->transition($task, $user, TaskStatus::OnReview),
        ]);

        $this->assertNotNull($task->fresh()->review_due_at);
        $workflow->undo($user, $token);
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertNull($task->fresh()->review_due_at);
    }
}
