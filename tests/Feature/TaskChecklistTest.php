<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\TaskChecklistItem;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskChecklistTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_assignee_can_toggle_checklist_item(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $item = TaskChecklistItem::query()->create([
            'task_id' => $task->id,
            'text' => 'Step 1',
            'sort_order' => 0,
        ]);

        app(TaskService::class)->toggleChecklistItem($item, $assignee);

        $this->assertTrue($item->fresh()->is_done);
    }

    public function test_watcher_cannot_manage_checklist(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Watcher', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $task->watchers()->attach($watcher);

        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->addChecklistItem($task, $watcher, 'Unauthorized item');
    }

    public function test_initiator_with_edit_own_can_add_checklist_item(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $item = app(TaskService::class)->addChecklistItem($task, $initiator, 'New step');

        $this->assertSame('New step', $item->text);
    }
}
