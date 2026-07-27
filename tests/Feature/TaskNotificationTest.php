<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskNotificationTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_create_task_notifies_assignee_not_initiator(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $category->id,
                'title' => 'Notify test',
                'description' => 'desc',
                'priority' => 5,
            ],
        );

        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.assigned'),
        );
        $this->assertCount(0, $initiator->fresh()->notifications);
    }

    public function test_create_task_notifies_watchers(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Watcher', role: $role);
        $category = $this->createCategory();

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $category->id,
                'title' => 'Watcher notify',
                'description' => 'desc',
                'priority' => 5,
            ],
            [],
            [$watcher->id],
        );

        $this->assertTrue(
            $watcher->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.assigned'),
        );
    }

    public function test_transition_notifies_initiator_not_actor(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::ChangeStatus->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::InProgress);

        $this->assertTrue(
            $initiator->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.status_changed'),
        );
        $this->assertCount(0, $assignee->fresh()->notifications);
    }

    public function test_comment_notifies_assignee(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment($task, $initiator, 'Hello assignee');

        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.commented'),
        );
    }

    public function test_mention_notifies_mentioned_not_commented(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createUserInDepartment($dept, 'Mentioned User', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $emailLocal = strstr($mentioned->email, '@', true);

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            'Please review @'.$emailLocal,
        );

        $notifications = $mentioned->fresh()->notifications;

        $this->assertTrue(
            $notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
        $this->assertFalse(
            $notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.commented'),
        );
    }

    public function test_disabled_preference_skips_notification(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        UserNotificationPreference::query()->create([
            'user_id' => $assignee->id,
            'event' => 'task.commented',
            'channel' => 'database',
            'enabled' => false,
        ]);

        app(TaskService::class)->addComment($task, $initiator, 'No notify please');

        $this->assertCount(0, $assignee->fresh()->notifications);
    }

    public function test_inactive_watcher_not_notified(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Inactive Watcher', role: $role);
        $watcher->update(['is_active' => false]);
        $category = $this->createCategory();

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $category->id,
                'title' => 'Inactive watcher',
                'description' => 'desc',
                'priority' => 5,
            ],
            [],
            [$watcher->id],
        );

        $this->assertCount(0, $watcher->fresh()->notifications);
    }
}
