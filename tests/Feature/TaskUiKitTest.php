<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\TaskWorkflowService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskUiKitTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_task_index_renders_search_shortcut_and_combobox(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Ui Kit User', role: $role);
        $this->createTask($user, $user, $this->createCategory(), ['title' => 'Visible task for ui kit']);

        $this->actingAs($user)
            ->get('/tasks?tab=all')
            ->assertOk()
            ->assertSee('data-shortcut="task-search"', false)
            ->assertSee('data-shortcut="create-task"', false)
            ->assertSee('Visible task for ui kit');

        $this->actingAs($user)
            ->get('/tasks?tab=all&status=new')
            ->assertOk()
            ->assertSee('uiCombobox', false)
            ->assertSee('wire:model.live="assigneeId"', false);
    }

    public function test_task_create_renders_assignee_combobox(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Creator', role: $role);

        $this->actingAs($user)
            ->get('/tasks/create')
            ->assertOk()
            ->assertSee('uiCombobox', false)
            ->assertSee('wire:model="assigneeId"', false);
    }

    public function test_empty_filtered_list_offers_reset(): void
    {
        $admin = User::factory()->create([
            'email' => 'ui-kit-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/tasks?tab=all&search='.urlencode('zzznomatchxyz'))
            ->assertOk()
            ->assertSee('zzznomatchxyz')
            ->assertSee('wire:click="resetFilters"', false);
    }

    public function test_task_index_wires_context_menu_and_hover_preview(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Context User', role: $role);
        $this->createTask($user, $user, $this->createCategory(), ['title' => 'Context menu task']);

        $this->actingAs($user)
            ->get('/tasks?tab=all')
            ->assertOk()
            ->assertSee('window.uiContext.show', false)
            ->assertSee('window.uiHover.show', false)
            ->assertSee('ui-command-toggle', false);
    }

    public function test_quick_transition_from_list_updates_status(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Status User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Start this task',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('quickTransition', $task->id, TaskStatus::InProgress->value)
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_quick_transition_undo_restores_status(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Undo Toast User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Undo this status',
            'status' => TaskStatus::New,
        ]);
        $workflow = app(TaskWorkflowService::class);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('quickTransition', $task->id, TaskStatus::InProgress->value)
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        $token = $workflow->issueUndoToken($user, [[
            'task_id' => $task->id,
            'from' => TaskStatus::New->value,
            'to' => TaskStatus::InProgress->value,
            'snapshot' => [
                'completed_at' => null,
                'closed_by' => null,
                'review_due_at' => null,
                'rework_count' => 0,
            ],
        ]]);

        Volt::test('layout.status-undo')
            ->call('undo', $token)
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::New, $task->fresh()->status);
    }

    public function test_task_layout_mounts_status_undo_listener(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Undo Layout User', role: $role);
        $this->createTask($user, $user, $this->createCategory(), ['title' => 'Undo layout task']);

        $this->actingAs($user)
            ->get('/tasks?tab=all')
            ->assertOk()
            ->assertSee('data-ui="status-undo"', false)
            ->assertSee('data-ui="toast-undo"', false);
    }

    public function test_command_palette_finds_task_by_number(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Palette User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), ['title' => 'Palette visible task']);

        $this->actingAs($user);

        Volt::test('layout.command-palette')
            ->call('show')
            ->set('query', (string) $task->number)
            ->assertSee('Palette visible task')
            ->assertSee('#'.$task->number);
    }

    public function test_peek_query_opens_sheet(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Peek User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), ['title' => 'Peek visible task']);
        $task->comments()->create([
            'author_id' => $user->id,
            'body' => 'Peek bubble comment',
        ]);

        $this->actingAs($user)
            ->get('/tasks?tab=all&peek='.$task->number)
            ->assertOk()
            ->assertSee('Peek visible task')
            ->assertSee('data-ui="sheet"', false)
            ->assertSee('data-ui="sheet-resize-handle"', false)
            ->assertSee('task-close-peek', false)
            ->assertSee('$wire.openPeek('.$task->number.')', false)
            ->assertSee('data-ui="message"', false)
            ->assertSee('Peek bubble comment');
    }

    public function test_open_peek_sets_state_and_close_clears_it(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Peek State User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), ['title' => 'Peek state task']);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('openPeek', $task->number)
            ->assertSet('peek', $task->number)
            ->assertSee('Open full page')
            ->call('closePeek')
            ->assertSet('peek', null)
            ->assertDontSee('Open full page');
    }

    public function test_inaccessible_peek_is_cleared(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $owner = $this->createUserInDepartment($dept, 'Peek Owner', role: $role);
        $viewer = $this->createUserInDepartment($dept, 'Peek Viewer', role: $role);
        $task = $this->createTask($owner, $owner, $this->createCategory(), ['title' => 'Hidden peek task']);

        $this->actingAs($viewer);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('openPeek', $task->number)
            ->assertSet('peek', null)
            ->assertDontSee('Hidden peek task');
    }

    public function test_peek_transition_updates_status(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Peek Status User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Peek status task',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user);

        Volt::test('pages.tasks.peek', ['number' => $task->number])
            ->call('selectTransition', TaskStatus::InProgress->value)
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_task_index_renders_bulk_selection_controls(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Bulk Ui User', role: $role);
        $this->createTask($user, $user, $this->createCategory(), ['title' => 'Bulk checkbox task']);

        $this->actingAs($user)
            ->get('/tasks?tab=all')
            ->assertOk()
            ->assertSee('data-ui="task-select"', false)
            ->assertSee('data-ui="task-select-all"', false)
            ->assertDontSee('data-ui="bulk-bar"', false);
    }

    public function test_bulk_transition_updates_selected_tasks(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Bulk Status User', role: $role);
        $category = $this->createCategory();
        $first = $this->createTask($user, $user, $category, [
            'title' => 'Bulk first',
            'status' => TaskStatus::New,
        ]);
        $second = $this->createTask($user, $user, $category, [
            'title' => 'Bulk second',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('toggleSelected', $first->id)
            ->call('toggleSelected', $second->id)
            ->assertSee('data-ui="bulk-bar"', false)
            ->call('chooseBulkStatus', TaskStatus::InProgress->value)
            ->assertHasNoErrors()
            ->assertSet('selectedIds', []);

        $this->assertSame(TaskStatus::InProgress, $first->fresh()->status);
        $this->assertSame(TaskStatus::InProgress, $second->fresh()->status);
    }

    public function test_bulk_transition_requires_comment_when_needed(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Bulk Comment User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Bulk cancel me',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user);

        $component = Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('toggleSelected', $task->id)
            ->call('chooseBulkStatus', TaskStatus::Cancelled->value)
            ->assertSet('pendingBulkStatus', TaskStatus::Cancelled->value);

        $component->call('confirmBulkAction');
        $this->assertSame(TaskStatus::New, $task->fresh()->status);

        $component->set('bulkComment', 'Not needed anymore')
            ->call('confirmBulkAction')
            ->assertSet('selectedIds', []);

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
    }

    public function test_bulk_assign_updates_selected_tasks(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value],
        ), [$dept->id]);
        $editor = $this->createUserInDepartment($dept, 'Bulk Assign Editor', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Bulk Assign Target', role: $role);
        $category = $this->createCategory();
        $first = $this->createTask($editor, $editor, $category, ['title' => 'Assign first']);
        $second = $this->createTask($editor, $editor, $category, ['title' => 'Assign second']);

        $this->actingAs($editor);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('toggleSelected', $first->id)
            ->call('toggleSelected', $second->id)
            ->set('bulkAssigneeId', $assignee->id)
            ->set('bulkComment', 'Handing these over')
            ->call('confirmBulkAction')
            ->assertHasNoErrors()
            ->assertSet('selectedIds', []);

        $this->assertSame($assignee->id, $first->fresh()->assignee_id);
        $this->assertSame($assignee->id, $second->fresh()->assignee_id);
    }

    public function test_bulk_watch_adds_current_user(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Bulk Watch User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), ['title' => 'Watch me']);

        $this->actingAs($user);
        $this->assertFalse($task->watchers()->where('users.id', $user->id)->exists());

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('toggleSelected', $task->id)
            ->call('bulkWatch')
            ->assertSet('selectedIds', []);

        $this->assertTrue($task->watchers()->where('users.id', $user->id)->exists());
    }

    public function test_inaccessible_selected_ids_are_ignored(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $owner = $this->createUserInDepartment($dept, 'Bulk Owner', role: $role);
        $viewer = $this->createUserInDepartment($dept, 'Bulk Viewer', role: $role);
        $visible = $this->createTask($viewer, $viewer, $this->createCategory(), [
            'title' => 'Visible bulk task',
            'status' => TaskStatus::New,
        ]);
        $hidden = $this->createTask($owner, $owner, $this->createCategory(), [
            'title' => 'Hidden bulk task',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($viewer);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('selectedIds', [(string) $visible->id, (string) $hidden->id])
            ->call('chooseBulkStatus', TaskStatus::InProgress->value);

        $this->assertSame(TaskStatus::InProgress, $visible->fresh()->status);
        $this->assertSame(TaskStatus::New, $hidden->fresh()->status);
    }

    // ── Saved Filters ──────────────────────────────────────────

    public function test_save_and_load_filter(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Filter User', role: $role);

        $this->actingAs($user);

        $component = Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('status', TaskStatus::InProgress->value)
            ->set('savedFilterName', 'My view')
            ->call('saveCurrentFilter');

        $component->assertSee('My view');

        $filter = \App\Models\SavedFilter::where('user_id', $user->id)->first();
        $this->assertNotNull($filter);
        $this->assertSame('My view', $filter->name);
        $this->assertSame('all', $filter->filters['tab']);
        $this->assertSame(TaskStatus::InProgress->value, $filter->filters['status']);

        // Load it back after resetting
        $component
            ->call('resetFilters')
            ->call('loadSavedFilter', $filter->id);

        $this->assertSame('all', $component->get('tab'));
        $this->assertSame(TaskStatus::InProgress->value, $component->get('status'));
        $this->assertSame($filter->id, $component->get('activeSavedFilterId'));
    }

    public function test_update_saved_filter(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Filter User', role: $role);

        $this->actingAs($user);

        $filter = \App\Models\SavedFilter::create([
            'user_id' => $user->id,
            'name' => 'Old view',
            'filters' => ['tab' => 'assigned', 'status' => '', 'sortBy' => 'priority', 'sortDir' => 'desc'],
        ]);

        Volt::test('pages.tasks.index')
            ->set('tab', 'created')
            ->set('status', TaskStatus::Completed->value)
            ->call('updateSavedFilter', $filter->id);

        $filter->refresh();
        $this->assertSame('created', $filter->filters['tab']);
        $this->assertSame(TaskStatus::Completed->value, $filter->filters['status']);
    }

    public function test_delete_saved_filter(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Filter User', role: $role);

        $this->actingAs($user);

        $filter = \App\Models\SavedFilter::create([
            'user_id' => $user->id,
            'name' => 'To delete',
            'filters' => ['tab' => 'all'],
        ]);

        Volt::test('pages.tasks.index')
            ->set('activeSavedFilterId', $filter->id)
            ->call('deleteSavedFilter', $filter->id);

        $this->assertNull(\App\Models\SavedFilter::find($filter->id));
    }

    public function test_toggle_default_filter(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Filter User', role: $role);

        $this->actingAs($user);

        $f1 = \App\Models\SavedFilter::create([
            'user_id' => $user->id,
            'name' => 'View A',
            'filters' => ['tab' => 'all'],
            'is_default' => true,
        ]);
        $f2 = \App\Models\SavedFilter::create([
            'user_id' => $user->id,
            'name' => 'View B',
            'filters' => ['tab' => 'created'],
        ]);

        Volt::test('pages.tasks.index')
            ->call('toggleDefaultFilter', $f2->id);

        $this->assertTrue($f2->fresh()->is_default);
        $this->assertFalse($f1->fresh()->is_default);
    }

    public function test_cannot_load_another_users_filter(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'User A', role: $role);
        $other = $this->createUserInDepartment($dept, 'User B', role: $role);

        $filter = \App\Models\SavedFilter::create([
            'user_id' => $other->id,
            'name' => 'Other view',
            'filters' => ['tab' => 'all', 'status' => TaskStatus::Completed->value],
        ]);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->call('loadSavedFilter', $filter->id);

        // Should not have changed tab since filter belongs to another user
        $this->assertSame('assigned', Volt::test('pages.tasks.index')->get('tab'));
    }

    public function test_board_layout_renders_kanban_columns(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Board User', role: $role);
        $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Board visible task',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user)
            ->get('/tasks?tab=all&layout=board')
            ->assertOk()
            ->assertSee('data-ui="kanban"', false)
            ->assertSee('data-kanban-column="new"', false)
            ->assertSee('data-ui="kanban-toggle"', false)
            ->assertSee('Board visible task')
            ->assertDontSee('data-ui="task-select-all"', false);
    }

    public function test_kanban_move_updates_status(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Board Move User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Board move task',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('layout', 'board')
            ->call('kanbanMove', $task->id, TaskStatus::InProgress->value)
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_kanban_move_requires_comment_when_needed(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Board Comment User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Board comment task',
            'status' => TaskStatus::InProgress,
        ]);

        $this->actingAs($user);

        $component = Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('layout', 'board')
            ->call('kanbanMove', $task->id, TaskStatus::Postponed->value);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame($task->id, $component->get('pendingKanbanId'));
        $component
            ->assertSee('data-ui="kanban-comment-dialog"', false)
            ->assertSee('Comment required')
            ->assertSee('Board comment task')
            ->assertSee('Postponed');

        $component
            ->set('kanbanComment', 'Wait for the client')
            ->call('confirmKanbanMove');

        $this->assertSame(TaskStatus::Postponed, $task->fresh()->status);
    }

    public function test_last_board_layout_is_restored_from_cookie(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Remember User', role: $role);
        $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Remembered board task',
            'status' => TaskStatus::New,
        ]);

        $this->actingAs($user);

        Volt::test('pages.tasks.index')
            ->call('restoreUiState', [
                'userId' => $user->id,
                'tab' => 'all',
                'layout' => 'board',
                'search' => '',
                'status' => '',
                'sortBy' => 'priority',
                'sortDir' => 'desc',
            ])
            ->assertSet('layout', 'board')
            ->assertSet('tab', 'all');

        $this->withCookie('tasktracker_tasks_ui', json_encode([
            'userId' => $user->id,
            'tab' => 'all',
            'layout' => 'board',
            'search' => '',
            'status' => '',
            'sortBy' => 'priority',
            'sortDir' => 'desc',
        ]))
            ->get('/tasks')
            ->assertOk()
            ->assertSee('data-ui="kanban"', false)
            ->assertSee('Remembered board task');
    }
}
