<?php

namespace Tests\Feature;

use App\Services\TaskService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskNotificationInboxTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_task_layout_renders_notification_inbox(): void
    {
        [$assignee] = $this->notifyAssignee('Inbox visible task');

        $this->actingAs($assignee)
            ->get('/tasks?tab=all')
            ->assertOk()
            ->assertSee('data-ui="notifications"', false)
            ->assertSee('data-ui="notifications-unread-count"', false)
            ->assertSee('Inbox visible task')
            ->assertSee('data-ui="notification-item"', false);
    }

    public function test_opening_a_notification_marks_it_read_and_opens_peek(): void
    {
        [$assignee, $task] = $this->notifyAssignee('Peek from inbox');
        $notification = $assignee->fresh()->notifications->first();

        $this->actingAs($assignee);

        Volt::test('layout.notifications')
            ->call('open', $notification->id)
            ->assertDispatched('task-open-peek', number: $task->number);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_clears_unread(): void
    {
        [$assignee] = $this->notifyAssignee('Mark all inbox');
        $this->assertSame(1, $assignee->fresh()->unreadNotifications()->count());

        $this->actingAs($assignee);

        Volt::test('layout.notifications')
            ->call('markAllAsRead');

        $this->assertSame(0, $assignee->fresh()->unreadNotifications()->count());
    }

    public function test_notifications_page_lists_items(): void
    {
        [$assignee] = $this->notifyAssignee('Full inbox task');

        $this->actingAs($assignee)
            ->get('/notifications')
            ->assertOk()
            ->assertSee('data-ui="notifications-page"', false)
            ->assertSee('Full inbox task');
    }

    public function test_notifications_page_open_marks_read_and_goes_to_peek(): void
    {
        [$assignee, $task] = $this->notifyAssignee('Page open task');
        $notification = $assignee->fresh()->notifications->first();

        $this->actingAs($assignee);

        Volt::test('pages.notifications.index')
            ->call('open', $notification->id)
            ->assertRedirect(route('tasks.index', ['peek' => $task->number]));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Task} */
    private function notifyAssignee(string $title): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Inbox Initiator '.uniqid(), role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Inbox Assignee '.uniqid(), role: $role);

        $task = app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $this->createCategory()->id,
                'title' => $title,
                'description' => 'desc',
                'priority' => 5,
            ],
        );

        return [$assignee->fresh(), $task];
    }
}
