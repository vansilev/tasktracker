<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskVisibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskSubtaskTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_create_subtask_links_parent_and_inherits_category_priority(): void
    {
        [$parent, $initiator, $assignee] = $this->makeParent();

        $child = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Backend']);

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame($parent->category_id, $child->category_id);
        $this->assertSame($parent->priority, $child->priority);
        $this->assertSame($assignee->id, $child->assignee_id);
        $this->assertSame($assignee->id, $child->initiator_id);
        $this->assertSame(TaskStatus::New, $child->status);
        $this->assertTrue($child->isSubtask());
        $this->assertContains($initiator->id, $child->watchers()->pluck('users.id'));
        $this->assertFalse($child->watchers()->where('user_id', $assignee->id)->exists());
    }

    public function test_subtask_appears_in_assignee_list_with_parent_link(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Visible child']);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.index')
            ->set('tab', 'assigned')
            ->assertSee('Visible child')
            ->assertSee(__('Part of #:number · :title', [
                'number' => $parent->number,
                'title' => $parent->title,
            ]));
    }

    public function test_parent_initiator_can_view_child_in_another_department(): void
    {
        $sales = $this->createDepartment('Sales');
        $it = $this->createDepartment('IT');
        $salesRole = $this->createRoleWithPermissions($this->defaultPermissions(), [$sales->id]);
        $itRole = $this->createRoleWithPermissions($this->defaultPermissions(), [$it->id]);
        $pavel = $this->createUserInDepartment($sales, 'Pavel Sales', role: $salesRole);
        $max = $this->createUserInDepartment($it, 'Max IT', role: $itRole);
        $category = $this->createCategory();
        $parent = $this->createTask($pavel, $pavel, $category, ['title' => 'Big job']);
        $parent->watchers()->attach($max);

        $child = app(TaskService::class)->createSubtask($max, $parent, ['title' => 'IT slice']);

        $this->assertSame($it->id, $child->department_id);
        $this->assertTrue($pavel->can('view', $child));
        $this->assertTrue(
            app(TaskVisibilityService::class)
                ->accessibleQuery($pavel)
                ->whereKey($child->id)
                ->exists(),
        );
    }

    public function test_cannot_create_subtask_of_a_subtask(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $child = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'First cut']);

        $this->assertFalse($assignee->can('createSubtask', $child));

        $this->expectException(ValidationException::class);
        app(TaskService::class)->createSubtask($assignee, $child, ['title' => 'Too deep']);
    }

    public function test_empty_title_is_rejected(): void
    {
        [$parent, , $assignee] = $this->makeParent();

        $this->expectException(ValidationException::class);
        app(TaskService::class)->createSubtask($assignee, $parent, ['title' => '   ']);
    }

    public function test_user_without_create_permission_cannot_add_subtask(): void
    {
        $dept = $this->createDepartment();
        $perms = array_values(array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::CreateTask->value,
        ));
        $role = $this->createRoleWithPermissions($perms);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $parent = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertFalse($assignee->can('createSubtask', $parent));

        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Nope']);
    }

    public function test_checklist_stays_independent_of_subtasks(): void
    {
        [$parent, $initiator] = $this->makeParent();
        app(TaskService::class)->addChecklistItem($parent, $initiator, 'Check desktop');
        app(TaskService::class)->createSubtask($initiator, $parent, ['title' => 'Hard piece']);

        $parent->refresh()->load(['checklistItems', 'subtasks']);

        $this->assertSame(1, $parent->checklistItems->count());
        $this->assertSame('Check desktop', $parent->checklistItems->first()->text);
        $this->assertSame(1, $parent->subtasks->count());
        $this->assertSame(0, $parent->subtasks->first()->checklistItems()->count());
    }

    public function test_parent_can_be_completed_while_child_is_open(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $child = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Still open']);

        $parent->update([
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->assertSame(TaskStatus::Completed, $parent->fresh()->status);
        $this->assertTrue($child->fresh()->status->isOpen());
        $this->assertSame(1, $parent->fresh()->load('subtasks')->openSubtasksCount());
    }

    public function test_show_page_modal_creates_subtask(): void
    {
        [$parent, , $assignee] = $this->makeParent();

        $this->actingAs($assignee);

        Volt::test('pages.tasks.show', ['task' => $parent])
            ->assertSee(__('Add subtask'))
            ->call('openSubtaskModal')
            ->assertSet('creatingSubtask', true)
            ->set('subtaskTitle', 'From card')
            ->set('subtaskDescription', '<p>Enough text here</p>')
            ->call('saveSubtask')
            ->assertHasNoErrors()
            ->assertSet('creatingSubtask', false)
            ->assertSee('From card');

        $child = Task::query()->where('parent_id', $parent->id)->where('title', 'From card')->first();
        $this->assertNotNull($child);
        $this->assertSame($assignee->id, $child->assignee_id);
        $this->assertSame($parent->category_id, $child->category_id);
        $this->assertSame($parent->priority, $child->priority);
    }

    public function test_child_page_shows_parent_link(): void
    {
        [$parent, , $assignee] = $this->makeParent(['title' => 'Parent title']);
        $child = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Child title']);

        $this->actingAs($assignee)
            ->get('/tasks/'.$child->id)
            ->assertOk()
            ->assertSee('Child title')
            ->assertSee(__('Part of #:number · :title', [
                'number' => $parent->number,
                'title' => 'Parent title',
            ]));
    }

    /** @return array{0: Task, 1: User, 2: User} */
    private function makeParent(array $overrides = []): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator '.uniqid(), role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee '.uniqid(), role: $role);
        $parent = $this->createTask($initiator, $assignee, $this->createCategory(), array_merge([
            'title' => 'Parent task',
            'priority' => 7,
        ], $overrides));

        return [$parent, $initiator, $assignee];
    }
}
