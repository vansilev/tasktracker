<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
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

        $this->actingAs($user)
            ->get('/tasks?tab=all&peek='.$task->number)
            ->assertOk()
            ->assertSee('Peek visible task')
            ->assertSee('data-ui="sheet"', false)
            ->assertSee('task-close-peek', false)
            ->assertSee('$wire.openPeek('.$task->number.')', false);
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
}
