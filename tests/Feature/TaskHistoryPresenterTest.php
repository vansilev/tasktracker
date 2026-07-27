<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\TaskHistory;
use App\Services\TaskHistoryPresenter;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskHistoryPresenterTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private TaskHistoryPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(TaskHistoryPresenter::class);
    }

    private function makeHistory(string $field, ?string $old, ?string $new): TaskHistory
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->logHistory($task, $field, $old, $new, $initiator);

        return TaskHistory::query()->where('task_id', $task->id)->latest('id')->first();
    }

    public function test_status_entry_uses_localized_labels(): void
    {
        $entry = $this->makeHistory('status', 'new', 'in_progress');

        $presented = $this->presenter->present($entry);

        $this->assertSame(__('task.history_field.status'), $presented['field']);
        $this->assertSame(__('task.status.new'), $presented['old']);
        $this->assertSame(__('task.status.in_progress'), $presented['new']);
    }

    public function test_assignee_id_entry_uses_user_names(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $oldAssignee = $this->createUserInDepartment($dept, 'Old Assignee', role: $role);
        $newAssignee = $this->createUserInDepartment($dept, 'New Assignee', role: $role);
        $task = $this->createTask($initiator, $oldAssignee, $this->createCategory());

        app(TaskWorkflowService::class)->logHistory(
            $task,
            'assignee_id',
            (string) $oldAssignee->id,
            (string) $newAssignee->id,
            $initiator,
        );

        $entry = TaskHistory::query()->where('task_id', $task->id)->latest('id')->first();
        $presented = $this->presenter->present($entry);

        $this->assertSame(__('task.history_field.assignee_id'), $presented['field']);
        $this->assertSame('Old Assignee', $presented['old']);
        $this->assertSame('New Assignee', $presented['new']);
    }

    public function test_department_id_entry_uses_department_names(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$deptA->id, $deptB->id]);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskWorkflowService::class)->logHistory(
            $task,
            'department_id',
            (string) $deptA->id,
            (string) $deptB->id,
            $initiator,
        );

        $entry = TaskHistory::query()->where('task_id', $task->id)->latest('id')->first();
        $presented = $this->presenter->present($entry);

        $this->assertSame(__('task.history_field.department_id'), $presented['field']);
        $this->assertSame('Dept A', $presented['old']);
        $this->assertSame('Dept B', $presented['new']);
    }

    public function test_deadline_midnight_omits_time(): void
    {
        $entry = $this->makeHistory('deadline', '2026-07-15 00:00:00', null);

        $presented = $this->presenter->present($entry);

        $this->assertSame('15.07.2026', $presented['old']);
        $this->assertNull($presented['new']);
    }

    public function test_deadline_with_time_includes_time(): void
    {
        $entry = $this->makeHistory('deadline', null, '2026-07-15 14:30:00');

        $presented = $this->presenter->present($entry);

        $this->assertNull($presented['old']);
        $this->assertSame('15.07.2026 14:30', $presented['new']);
    }

    public function test_null_values_remain_null(): void
    {
        $entry = $this->makeHistory('title', null, 'Updated title');

        $presented = $this->presenter->present($entry);

        $this->assertNull($presented['old']);
        $this->assertSame('Updated title', $presented['new']);
    }

    public function test_unknown_field_stays_as_is(): void
    {
        $entry = $this->makeHistory('custom_field', 'alpha', 'beta');

        $presented = $this->presenter->present($entry);

        $this->assertSame('custom_field', $presented['field']);
        $this->assertSame('alpha', $presented['old']);
        $this->assertSame('beta', $presented['new']);
    }

    public function test_task_show_page_renders_presented_assignee_history(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value],
        ), [$dept->id]);
        $editor = $this->createUserInDepartment($dept, 'Editor', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $oldAssignee = $this->createUserInDepartment($dept, 'Old Assignee', role: $role);
        $newAssignee = $this->createUserInDepartment($dept, 'New Assignee', role: $role);
        $task = $this->createTask($initiator, $oldAssignee, $this->createCategory());

        app(TaskService::class)->update($task, $editor, ['assignee_id' => $newAssignee->id]);

        $this->actingAs($initiator)
            ->get('/tasks/'.$task->id)
            ->assertOk()
            ->assertSee(__('task.history_field.assignee_id'))
            ->assertSee('New Assignee')
            ->assertDontSee('— assignee_id');
    }
}
