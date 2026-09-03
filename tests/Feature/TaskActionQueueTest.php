<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskActionQueueService;
use App\Services\TaskVisibilityService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskActionQueueTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_sections_split_review_awaiting_overdue_and_work(): void
    {
        [$me, $other, $category] = $this->actors();

        $review = $this->createTask($me, $other, $category, [
            'title' => 'Review this result',
            'status' => TaskStatus::OnReview,
        ]);
        $awaiting = $this->createTask($me, $other, $category, [
            'title' => 'Need my files',
            'status' => TaskStatus::AwaitingInitiator,
        ]);
        $overdue = $this->createTask($other, $me, $category, [
            'title' => 'Overdue assigned work',
            'status' => TaskStatus::InProgress,
            'deadline' => now()->subDay(),
        ]);
        $todo = $this->createTask($other, $me, $category, [
            'title' => 'Start this work',
            'status' => TaskStatus::New,
        ]);
        $this->createTask($other, $me, $category, [
            'title' => 'Waiting on initiator review',
            'status' => TaskStatus::OnReview,
        ]);
        $this->createTask($other, $me, $category, [
            'title' => 'Waiting on initiator data',
            'status' => TaskStatus::AwaitingInitiator,
        ]);
        $this->createTask($other, $me, $category, [
            'title' => 'Already done',
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);

        $built = $this->sectionsFor($me);

        $this->assertSame([$review->id], $this->ids($built, TaskActionQueueService::REVIEW));
        $this->assertSame([$awaiting->id], $this->ids($built, TaskActionQueueService::AWAITING));
        $this->assertSame([$overdue->id], $this->ids($built, TaskActionQueueService::OVERDUE));
        $this->assertSame([$todo->id], $this->ids($built, TaskActionQueueService::TODO));
        $this->assertSame(4, $built['count']);
        $this->assertSame(4, app(TaskActionQueueService::class)->count($me));
    }

    public function test_overdue_task_is_not_also_listed_as_work(): void
    {
        [$me, $other, $category] = $this->actors();
        $task = $this->createTask($other, $me, $category, [
            'title' => 'Only overdue',
            'status' => TaskStatus::Rework,
            'deadline' => now()->subHour(),
        ]);

        $built = $this->sectionsFor($me);

        $this->assertSame([$task->id], $this->ids($built, TaskActionQueueService::OVERDUE));
        $this->assertSame([], $this->ids($built, TaskActionQueueService::TODO));
    }

    public function test_action_tab_renders_section_headers(): void
    {
        [$me, $other, $category] = $this->actors();
        $this->createTask($me, $other, $category, [
            'title' => 'Peek review task',
            'status' => TaskStatus::OnReview,
        ]);
        $this->createTask($other, $me, $category, [
            'title' => 'Peek work task',
            'status' => TaskStatus::InProgress,
        ]);

        $this->actingAs($me)
            ->get('/tasks?tab=action')
            ->assertOk()
            ->assertSee('data-ui="action-queue"', false)
            ->assertSee('data-action-section="review"', false)
            ->assertSee('data-action-section="todo"', false)
            ->assertSee('Peek review task')
            ->assertSee('Peek work task')
            ->assertSee('data-ui="action-count"', false);
    }

    public function test_action_tab_hides_tasks_waiting_on_others(): void
    {
        [$me, $other, $category] = $this->actors();
        $this->createTask($other, $me, $category, [
            'title' => 'Hidden waiting review',
            'status' => TaskStatus::OnReview,
        ]);

        $this->actingAs($me);

        Volt::test('pages.tasks.index')
            ->set('tab', 'action')
            ->assertDontSee('Hidden waiting review')
            ->assertSee('Nothing needs your action');
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\Category}
     */
    private function actors(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $me = $this->createUserInDepartment($dept, 'Action Owner', role: $role);
        $other = $this->createUserInDepartment($dept, 'Action Other', role: $role);

        return [$me, $other, $this->createCategory()];
    }

    /**
     * @return array{items: \Illuminate\Support\Collection<int, Task>, group: array<int, string>, sections: list<array{key: string, label: string, count: int}>, count: int}
     */
    private function sectionsFor(\App\Models\User $user): array
    {
        $query = app(TaskVisibilityService::class)->accessibleQuery($user);

        return app(TaskActionQueueService::class)->buildSections($query, $user, function ($q): void {
            $q->orderBy('id');
        });
    }

    /**
     * @param  array{group: array<int, string>}  $built
     * @return list<int>
     */
    private function ids(array $built, string $section): array
    {
        return collect($built['group'])
            ->filter(fn (string $key) => $key === $section)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
