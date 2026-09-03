<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskUnreadService;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskUnreadTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_other_comment_counts_as_unread(): void
    {
        [$me, $other, $task] = $this->actors();
        $task->comments()->create([
            'author_id' => $other->id,
            'body' => 'Please check this',
        ]);

        $this->assertSame(1, $this->countFor($me, $task->id));
    }

    public function test_own_comment_does_not_count(): void
    {
        [$me, $other, $task] = $this->actors();
        $task->comments()->create([
            'author_id' => $me->id,
            'body' => 'My own note',
        ]);

        $this->assertSame(0, $this->countFor($me, $task->id));
    }

    public function test_other_status_change_counts_and_create_row_does_not(): void
    {
        [$me, $other, $task] = $this->actors();
        $workflow = app(TaskWorkflowService::class);
        $workflow->logHistory($task, 'status', null, TaskStatus::New->value, $other);
        $workflow->logHistory($task, 'status', TaskStatus::New->value, TaskStatus::InProgress->value, $other);

        $this->assertSame(1, $this->countFor($me, $task->id));
    }

    public function test_peek_marks_the_task_seen(): void
    {
        [$me, $other, $task] = $this->actors();
        $task->comments()->create([
            'author_id' => $other->id,
            'body' => 'Unread until peek',
        ]);

        $this->actingAs($me);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('openPeek', $task->number)
            ->assertSet('peek', $task->number);

        $this->assertSame(0, $this->countFor($me, $task->id));
    }

    public function test_show_page_marks_the_task_seen(): void
    {
        [$me, $other, $task] = $this->actors();
        $task->comments()->create([
            'author_id' => $other->id,
            'body' => 'Unread until show',
        ]);

        $this->actingAs($me)
            ->get(route('tasks.show', $task))
            ->assertOk();

        $this->assertSame(0, $this->countFor($me, $task->id));
    }

    public function test_activity_after_a_visit_counts_again(): void
    {
        [$me, $other, $task] = $this->actors();
        $service = app(TaskUnreadService::class);
        $service->markSeen($me, $task);

        $this->travel(1)->second();

        $task->comments()->create([
            'author_id' => $other->id,
            'body' => 'After you left',
        ]);

        $this->assertSame(1, $this->countFor($me, $task->id));
    }

    public function test_index_renders_unread_marker(): void
    {
        [$me, $other, $task] = $this->actors();
        $task->comments()->create([
            'author_id' => $other->id,
            'body' => 'Show the dot',
        ]);

        $this->actingAs($me)
            ->get('/tasks?tab=all')
            ->assertOk()
            ->assertSee('data-ui="task-unread"', false)
            ->assertSee('data-unread-count="1"', false)
            ->assertSee($task->title);
    }

    public function test_mark_seen_clears_inbox_for_that_task(): void
    {
        [$me, $other, $task] = $this->actors();
        $me->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'task.commented',
            'data' => [
                'task_id' => $task->id,
                'task_number' => $task->number,
                'task_title' => $task->title,
            ],
        ]);

        $this->assertSame(1, $me->fresh()->unreadNotifications()->count());

        app(TaskUnreadService::class)->markSeen($me, $task);

        $this->assertSame(0, $me->fresh()->unreadNotifications()->count());
    }

    /**
     * @return array{0: User, 1: User, 2: Task}
     */
    private function actors(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $me = $this->createUserInDepartment($dept, 'Unread Viewer', role: $role);
        $other = $this->createUserInDepartment($dept, 'Unread Author', role: $role);
        $task = $this->createTask($other, $me, $this->createCategory(), [
            'title' => 'Unread marker task',
        ]);

        return [$me, $other, $task];
    }

    private function countFor(User $user, int $taskId): int
    {
        return app(TaskUnreadService::class)->counts($user, [$taskId])[$taskId] ?? 0;
    }
}
