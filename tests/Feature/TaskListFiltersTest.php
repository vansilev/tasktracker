<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskListFiltersTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'email' => 'filters-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
    }

    public function test_assignee_filter_returns_only_matching_tasks(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assigneeA = $this->createUserInDepartment($dept, 'Assignee A', role: $role);
        $assigneeB = $this->createUserInDepartment($dept, 'Assignee B', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assigneeA, $category, ['title' => 'Task For Assignee A']);
        $this->createTask($initiator, $assigneeB, $category, ['title' => 'Task For Assignee B']);

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('assigneeId', $assigneeA->id)
            ->assertSee('Task For Assignee A')
            ->assertDontSee('Task For Assignee B');
    }

    public function test_initiator_filter_returns_only_matching_tasks(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiatorA = $this->createUserInDepartment($dept, 'Initiator A', role: $role);
        $initiatorB = $this->createUserInDepartment($dept, 'Initiator B', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiatorA, $assignee, $category, ['title' => 'Task From Initiator A']);
        $this->createTask($initiatorB, $assignee, $category, ['title' => 'Task From Initiator B']);

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('initiatorId', $initiatorA->id)
            ->assertSee('Task From Initiator A')
            ->assertDontSee('Task From Initiator B');
    }

    public function test_overdue_only_shows_open_overdue_and_hides_others(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'title' => 'Overdue Open Task',
            'status' => TaskStatus::InProgress,
            'deadline' => now()->subDay(),
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'Closed Past Deadline Task',
            'status' => TaskStatus::Completed,
            'deadline' => now()->subWeek(),
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'Open Without Deadline Task',
            'status' => TaskStatus::New,
            'deadline' => null,
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('overdueOnly', true)
            ->assertSee('Overdue Open Task')
            ->assertDontSee('Closed Past Deadline Task')
            ->assertDontSee('Open Without Deadline Task');
    }

    public function test_created_at_period_excludes_tasks_outside_range(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $inside = $this->createTask($initiator, $assignee, $category, [
            'title' => 'Task Inside Period',
        ]);
        $inside->forceFill(['created_at' => '2026-06-10 10:00:00'])->save();

        $outside = $this->createTask($initiator, $assignee, $category, [
            'title' => 'Task Outside Period',
        ]);
        $outside->forceFill(['created_at' => '2026-05-01 10:00:00'])->save();

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('periodType', 'created_at')
            ->set('periodFrom', '2026-06-01')
            ->set('periodTo', '2026-06-30')
            ->assertSee('Task Inside Period')
            ->assertDontSee('Task Outside Period');

        Carbon::setTestNow();
    }

    public function test_garbage_period_dates_in_url_render_page_without_filtering(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Task Visible Despite Garbage Dates',
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/tasks?tab=all&periodFrom=garbage&periodTo=123abc')
            ->assertOk()
            ->assertSee('Task Visible Despite Garbage Dates');
    }

    public function test_valid_period_from_in_url_still_filters(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $recent = $this->createTask($initiator, $assignee, $category, [
            'title' => 'Task Within Valid Period',
        ]);
        $recent->forceFill(['created_at' => '2026-06-10 10:00:00'])->save();

        $old = $this->createTask($initiator, $assignee, $category, [
            'title' => 'Task Before Valid Period',
        ]);
        $old->forceFill(['created_at' => '2026-05-01 10:00:00'])->save();

        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/tasks?tab=all&periodType=created_at&periodFrom=2026-06-01')
            ->assertOk()
            ->assertSee('Task Within Valid Period')
            ->assertDontSee('Task Before Valid Period');
    }

    public function test_deadline_sort_asc_puts_null_deadlines_last(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'title' => 'ZZZ Late Deadline Task',
            'deadline' => now()->addWeek(),
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'AAA Early Deadline Task',
            'deadline' => now()->addDay(),
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'NNN No Deadline Task',
            'deadline' => null,
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('sortBy', 'deadline')
            ->set('sortDir', 'asc')
            ->assertSeeInOrder([
                'AAA Early Deadline Task',
                'ZZZ Late Deadline Task',
                'NNN No Deadline Task',
            ]);
    }

    public function test_title_sort_is_alphabetical(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, ['title' => 'Zebra Task']);
        $this->createTask($initiator, $assignee, $category, ['title' => 'Alpha Task']);
        $this->createTask($initiator, $assignee, $category, ['title' => 'Middle Task']);

        $this->actingAs($this->createAdmin());

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('sortByColumn', 'title')
            ->assertSet('sortBy', 'title')
            ->assertSet('sortDir', 'asc')
            ->assertSeeInOrder(['Alpha Task', 'Middle Task', 'Zebra Task']);
    }

    public function test_clicking_same_column_toggles_sort_direction(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();
        $this->createTask($initiator, $assignee, $category, ['title' => 'Only Task']);

        $this->actingAs($this->createAdmin());

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('sortByColumn', 'title')
            ->assertSet('sortDir', 'asc')
            ->call('sortByColumn', 'title')
            ->assertSet('sortBy', 'title')
            ->assertSet('sortDir', 'desc');
    }

    public function test_department_sort_orders_by_department_name(): void
    {
        $alpha = $this->createDepartment('Alpha Dept');
        $zulu = $this->createDepartment('Zulu Dept');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($alpha, 'Initiator', role: $role);
        $assigneeAlpha = $this->createUserInDepartment($alpha, 'Assignee Alpha', role: $role);
        $assigneeZulu = $this->createUserInDepartment($zulu, 'Assignee Zulu', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assigneeZulu, $category, ['title' => 'Task In Zulu']);
        $this->createTask($initiator, $assigneeAlpha, $category, ['title' => 'Task In Alpha']);

        $this->actingAs($this->createAdmin());

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('sortByColumn', 'department')
            ->assertSeeInOrder(['Task In Alpha', 'Task In Zulu']);
    }

    public function test_status_sort_follows_workflow_order(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, [
            'title' => 'Completed Task',
            'status' => TaskStatus::Completed,
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'New Task',
            'status' => TaskStatus::New,
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'In Progress Task',
            'status' => TaskStatus::InProgress,
        ]);

        $this->actingAs($this->createAdmin());

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->call('sortByColumn', 'status')
            ->assertSeeInOrder(['New Task', 'In Progress Task', 'Completed Task']);
    }

    public function test_priority_sort_high_first_by_default(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->createTask($initiator, $assignee, $category, ['title' => 'Low Priority Task', 'priority' => 2]);
        $this->createTask($initiator, $assignee, $category, ['title' => 'High Priority Task', 'priority' => 9]);

        $this->actingAs($this->createAdmin());

        $page = Volt::test('pages.tasks.index')->set('tab', 'all');
        $page->assertSet('sortBy', 'priority')
            ->assertSet('sortDir', 'desc')
            ->assertSeeInOrder(['High Priority Task', 'Low Priority Task']);

        $page->call('sortByColumn', 'priority')
            ->assertSet('sortDir', 'asc')
            ->assertSeeInOrder(['Low Priority Task', 'High Priority Task']);
    }

    public function test_table_headers_are_sortable(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $this->createTask($initiator, $assignee, $this->createCategory(), ['title' => 'Header Task']);

        $this->actingAs($this->createAdmin());

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->assertSeeHtml("wire:click=\"sortByColumn('title')\"")
            ->assertSeeHtml("wire:click=\"sortByColumn('status')\"")
            ->assertSeeHtml("wire:click=\"sortByColumn('priority')\"")
            ->assertSeeHtml("wire:click=\"sortByColumn('department')\"")
            ->assertSeeHtml("wire:click=\"sortByColumn('deadline')\"");
    }
}
