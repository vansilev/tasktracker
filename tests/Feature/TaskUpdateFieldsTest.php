<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\TaskService;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskUpdateFieldsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    /** @return array{0: Department, 1: User, 2: Role} */
    private function createEditor(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value],
        ), [$dept->id]);

        return [$dept, $this->createUserInDepartment($dept, 'Editor', role: $role), $role];
    }

    public function test_clearing_deadline_sets_null_and_logs_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'deadline' => '2026-01-15 10:00:00',
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count();

        app(TaskService::class)->update($task, $editor, ['deadline' => null]);

        $this->assertNull($task->fresh()->deadline);
        $this->assertSame($before + 1, TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count());

        $entry = TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->latest('id')->first();
        $this->assertSame('2026-01-15 10:00:00', $entry->old_value);
        $this->assertNull($entry->new_value);
    }

    public function test_same_deadline_date_preserves_time_and_skips_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'deadline' => '2026-07-10 02:00:00',
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count();

        app(TaskService::class)->update($task, $editor, ['deadline' => '2026-07-10']);

        $fresh = $task->fresh();
        $this->assertSame('2026-07-10 02:00:00', $fresh->deadline->toDateTimeString());
        $this->assertSame($before, TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count());
    }

    public function test_different_deadline_date_updates_and_logs_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'deadline' => '2026-07-10 02:00:00',
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count();

        app(TaskService::class)->update($task, $editor, ['deadline' => '2026-07-15']);

        $fresh = $task->fresh();
        $this->assertSame('2026-07-15 00:00:00', $fresh->deadline->toDateTimeString());
        $this->assertSame($before + 1, TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count());

        $entry = TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->latest('id')->first();
        $this->assertSame('2026-07-10 02:00:00', $entry->old_value);
        $this->assertSame('2026-07-15 00:00:00', $entry->new_value);
    }

    public function test_clearing_spec_url_with_empty_string_sets_null_and_logs_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'spec_url' => 'https://example.com/spec',
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'spec_url')->count();

        app(TaskService::class)->update($task, $editor, ['spec_url' => '']);

        $this->assertNull($task->fresh()->spec_url);
        $this->assertSame($before + 1, TaskHistory::query()->where('task_id', $task->id)->where('field', 'spec_url')->count());

        $entry = TaskHistory::query()->where('task_id', $task->id)->where('field', 'spec_url')->latest('id')->first();
        $this->assertSame('https://example.com/spec', $entry->old_value);
        $this->assertNull($entry->new_value);
    }

    public function test_assignee_can_update_result_url_without_full_edit_permission(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => ! in_array($p, [Permission::EditOwnTask->value, Permission::EditAnyTask->value], true)
        );
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->updateResultUrl($task, $assignee, 'https://example.com/result');

        $this->assertSame('https://example.com/result', $task->fresh()->result_url);
    }

    public function test_clearing_result_url_with_null_sets_null_and_logs_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'result_url' => 'https://example.com/result',
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'result_url')->count();

        app(TaskService::class)->update($task, $editor, ['result_url' => null]);

        $this->assertNull($task->fresh()->result_url);
        $this->assertSame($before + 1, TaskHistory::query()->where('task_id', $task->id)->where('field', 'result_url')->count());

        $entry = TaskHistory::query()->where('task_id', $task->id)->where('field', 'result_url')->latest('id')->first();
        $this->assertSame('https://example.com/result', $entry->old_value);
        $this->assertNull($entry->new_value);
    }

    public function test_no_op_update_does_not_log_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        // Create via the service so description is stored as HTML (the production write path).
        $task = app(TaskService::class)->create($initiator, [
            'department_id' => $dept->id,
            'assignee_id' => $assignee->id,
            'category_id' => $this->createCategory()->id,
            'title' => 'Same title',
            'description' => 'Same description',
            'priority' => 5,
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->count();

        app(TaskService::class)->update($task, $editor, [
            'title' => 'Same title',
            'description' => 'Same description',
        ]);

        $this->assertSame($before, TaskHistory::query()->where('task_id', $task->id)->count());
    }

    public function test_title_change_updates_and_logs_history(): void
    {
        [$dept, $editor, $role] = $this->createEditor();
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Old title',
        ]);

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'title')->count();

        app(TaskService::class)->update($task, $editor, ['title' => 'New title']);

        $this->assertSame('New title', $task->fresh()->title);
        $this->assertSame($before + 1, TaskHistory::query()->where('task_id', $task->id)->where('field', 'title')->count());

        $entry = TaskHistory::query()->where('task_id', $task->id)->where('field', 'title')->latest('id')->first();
        $this->assertSame('Old title', $entry->old_value);
        $this->assertSame('New title', $entry->new_value);
    }
}
