<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskReminderTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_deadline_approaching_notifies_assignee_and_initiator_without_duplicates(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'status' => TaskStatus::InProgress,
            'deadline' => now()->addHours(12),
        ]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.deadline_approaching'),
        );
        $this->assertTrue(
            $initiator->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.deadline_approaching'),
        );

        $assigneeCount = $assignee->fresh()->notifications->count();
        $initiatorCount = $initiator->fresh()->notifications->count();

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertSame($assigneeCount, $assignee->fresh()->notifications->count());
        $this->assertSame($initiatorCount, $initiator->fresh()->notifications->count());
    }

    public function test_overdue_notifies_assignee_and_initiator_without_duplicates(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'status' => TaskStatus::InProgress,
            'deadline' => now()->subDay(),
        ]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.overdue'),
        );
        $this->assertTrue(
            $initiator->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.overdue'),
        );

        $assigneeCount = $assignee->fresh()->notifications->count();
        $initiatorCount = $initiator->fresh()->notifications->count();

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertSame($assigneeCount, $assignee->fresh()->notifications->count());
        $this->assertSame($initiatorCount, $initiator->fresh()->notifications->count());
    }

    public function test_review_sla_expired_notifies_initiator_and_department_head_without_duplicates(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $head = $this->createUserInDepartment($dept, 'Head', role: $role);
        $dept->update(['head_user_id' => $head->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'status' => TaskStatus::OnReview,
            'review_due_at' => now()->subDay(),
        ]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertTrue(
            $initiator->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.review_sla_expired'),
        );
        $this->assertTrue(
            $head->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.review_sla_expired'),
        );

        $initiatorCount = $initiator->fresh()->notifications->count();
        $headCount = $head->fresh()->notifications->count();

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertSame($initiatorCount, $initiator->fresh()->notifications->count());
        $this->assertSame($headCount, $head->fresh()->notifications->count());
    }

    public function test_completed_task_with_overdue_deadline_is_not_notified(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'status' => TaskStatus::Completed,
            'deadline' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertCount(0, $assignee->fresh()->notifications);
        $this->assertCount(0, $initiator->fresh()->notifications);
    }

    public function test_dry_run_does_not_send_notifications_or_set_flags(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $task = $this->createTask($initiator, $assignee, $category, [
            'status' => TaskStatus::InProgress,
            'deadline' => now()->addHours(12),
        ]);

        $this->artisan('tasks:send-reminders', ['--dry-run' => true])->assertSuccessful();

        $this->assertCount(0, $assignee->fresh()->notifications);
        $this->assertCount(0, $initiator->fresh()->notifications);
        $this->assertNull($task->fresh()->deadline_reminder_sent_at);
        $this->assertNull($task->fresh()->overdue_notified_at);
        $this->assertNull($task->fresh()->review_sla_notified_at);
    }
}
