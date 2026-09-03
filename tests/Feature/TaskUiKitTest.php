<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\User;
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
}
