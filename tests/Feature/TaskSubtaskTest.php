<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskChecklistItem;
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
            ->assertSee($parent->title)
            ->assertViewHas('tasks', function ($paginator) use ($parent) {
                $ids = $paginator->getCollection()->pluck('id');

                return $ids->contains($parent->id)
                    && $ids->count() === 1;
            });
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

    public function test_show_page_converts_checklist_item_into_subtask(): void
    {
        [$parent, $initiator] = $this->makeParent();
        $item = app(TaskService::class)->addChecklistItem($parent, $initiator, 'Hard backend piece');

        $this->actingAs($initiator);

        Volt::test('pages.tasks.show', ['task' => $parent->fresh()])
            ->assertSee(__('To subtask'))
            ->call('openSubtaskModalFromChecklist', $item->id)
            ->assertSet('creatingSubtask', true)
            ->assertSet('subtaskTitle', 'Hard backend piece')
            ->set('subtaskDescription', '<p>Enough text here</p>')
            ->call('saveSubtask')
            ->assertHasNoErrors()
            ->assertSet('creatingSubtask', false)
            ->assertSee('Hard backend piece')
            ->assertDontSee(__('To subtask'));

        $this->assertFalse(TaskChecklistItem::query()->whereKey($item->id)->exists());
        $this->assertTrue(
            Task::query()->where('parent_id', $parent->id)->where('title', 'Hard backend piece')->exists(),
        );
    }

    public function test_cannot_convert_checklist_item_from_another_task(): void
    {
        [$parent, $initiator] = $this->makeParent();
        [$other] = $this->makeParent();
        $foreign = app(TaskService::class)->addChecklistItem($other, $other->initiator, 'Not yours');

        $this->expectException(ValidationException::class);
        app(TaskService::class)->createSubtaskFromChecklist(
            $initiator,
            $parent,
            $foreign,
            ['title' => 'Stolen'],
        );
    }

    public function test_subtask_progress_counts_only_completed_children(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $done = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Done child']);
        $cancelled = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Cancelled child']);
        app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Open child']);

        $done->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
        $cancelled->update(['status' => TaskStatus::Cancelled]);

        $parent->load('subtasks');

        $this->assertSame('1/3', $parent->subtaskProgress());
        $this->assertSame(1, $parent->subtaskCompletedCount());
        $this->assertSame(33, $parent->subtaskProgressPercent());
        $this->assertSame(1, $parent->openSubtasksCount());
    }

    public function test_list_shows_parent_subtask_progress(): void
    {
        [$parent, , $assignee] = $this->makeParent(['title' => 'Big parent']);
        $done = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Done slice']);
        app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Open slice']);
        $done->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.index')
            ->set('tab', 'assigned')
            ->assertSee('Big parent')
            ->assertSee('1/2');
    }

    public function test_list_nests_child_under_parent_when_both_on_page(): void
    {
        [$parent, , $assignee] = $this->makeParent(['title' => 'Grouped parent']);
        $child = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Nested child']);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.index')
            ->set('tab', 'assigned')
            ->assertViewHas('tasks', function ($paginator) use ($parent, $child) {
                $ids = $paginator->getCollection()->pluck('id');

                return $ids->contains($parent->id)
                    && ! $ids->contains($child->id)
                    && $paginator->getCollection()->firstWhere('id', $parent->id)?->subtasks->contains('id', $child->id);
            })
            ->assertSee('Grouped parent')
            ->assertSee('Nested child');
    }

    public function test_list_keeps_child_flat_when_parent_is_not_in_tab(): void
    {
        [$parent, $initiator, $assignee] = $this->makeParent(['title' => 'Hidden parent']);
        app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Standalone child']);
        $parent->update([
            'assignee_id' => $initiator->id,
            'department_id' => $initiator->department_id,
        ]);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.index')
            ->set('tab', 'assigned')
            ->assertSee('Standalone child')
            ->assertSee(__('Part of #:number · :title', [
                'number' => $parent->number,
                'title' => 'Hidden parent',
            ]))
            ->assertViewHas('tasks', function ($paginator) use ($parent) {
                $ids = $paginator->getCollection()->pluck('id');

                return ! $ids->contains($parent->id) && $ids->count() === 1;
            });
    }

    public function test_new_subtask_is_appended_and_order_can_be_rearranged(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $first = $service->createSubtask($assignee, $parent, [
            'title' => 'Later deadline',
            'deadline' => now()->addDays(10)->toDateString(),
        ]);
        $second = $service->createSubtask($assignee, $parent, [
            'title' => 'Sooner deadline',
            'deadline' => now()->addDay()->toDateString(),
        ]);

        $parent->refresh()->load('subtasks');
        $this->assertSame([$first->id, $second->id], $parent->subtasks->pluck('id')->all());
        $this->assertSame([0, 1], $parent->subtasks->pluck('sort_order')->all());

        $this->actingAs($assignee);

        Volt::test('pages.tasks.show', ['task' => $parent])
            ->assertSee('Sooner deadline')
            ->assertSee($second->deadline->timezone(config('app.timezone'))->format('d.m.Y'))
            ->call('reorderSubtasks', [$second->id, $first->id])
            ->assertHasNoErrors();

        $parent->refresh()->load('subtasks');
        $this->assertSame([$second->id, $first->id], $parent->subtasks->pluck('id')->all());
        $this->assertSame([0, 1], $parent->subtasks->pluck('sort_order')->all());

        $service->reorderSubtasks($assignee, $parent, [(string) $first->id, (string) $second->id]);
        $parent->refresh()->load('subtasks');
        $this->assertSame([$first->id, $second->id], $parent->subtasks->pluck('id')->all());
    }

    public function test_user_without_create_permission_cannot_reorder_subtasks(): void
    {
        [$parent, $initiator] = $this->makeParent();
        $service = app(TaskService::class);
        $first = $service->createSubtask($initiator, $parent, ['title' => 'One']);
        $second = $service->createSubtask($initiator, $parent, ['title' => 'Two']);

        $perms = array_values(array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::CreateTask->value,
        ));
        $viewerRole = $this->createRoleWithPermissions($perms);
        $viewer = $this->createUserInDepartment($parent->department, 'Viewer '.uniqid(), role: $viewerRole);
        $parent->watchers()->attach($viewer);

        $this->expectException(AuthorizationException::class);
        $service->reorderSubtasks($viewer, $parent, [$second->id, $first->id]);
    }

    public function test_reorder_rejects_incomplete_id_list(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $first = $service->createSubtask($assignee, $parent, ['title' => 'One']);
        $service->createSubtask($assignee, $parent, ['title' => 'Two']);

        $this->expectException(ValidationException::class);
        $service->reorderSubtasks($assignee, $parent, [$first->id]);
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
