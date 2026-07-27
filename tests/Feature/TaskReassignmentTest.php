<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\TaskComment;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskReassignmentTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    /** @return array{0: \App\Models\Department, 1: User, 2: \App\Models\Role} */
    private function createEditorWithAssign(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value],
        ), [$dept->id]);

        return [$dept, $this->createUserInDepartment($dept, 'Editor', role: $role), $role];
    }

    public function test_cross_department_reassignment_updates_department_and_logs_history(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value, Permission::AssignTask->value],
        ), [$deptA->id, $deptB->id]);
        $editor = $this->createUserInDepartment($deptA, 'Editor', role: $role);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $assigneeA = $this->createUserInDepartment($deptA, 'Assignee A', role: $role);
        $assigneeB = $this->createUserInDepartment($deptB, 'Assignee B', role: $role);
        $task = $this->createTask($initiator, $assigneeA, $this->createCategory());

        app(TaskService::class)->update($task, $editor, ['assignee_id' => $assigneeB->id]);

        $fresh = $task->fresh();
        $this->assertSame($assigneeB->id, $fresh->assignee_id);
        $this->assertSame($deptB->id, $fresh->department_id);

        $this->assertTrue(
            TaskHistory::query()
                ->where('task_id', $task->id)
                ->where('field', 'assignee_id')
                ->where('new_value', (string) $assigneeB->id)
                ->exists()
        );
        $this->assertTrue(
            TaskHistory::query()
                ->where('task_id', $task->id)
                ->where('field', 'department_id')
                ->where('new_value', (string) $deptB->id)
                ->exists()
        );
    }

    public function test_reassignment_without_comment_fails_validation_and_keeps_assignee(): void
    {
        [$dept, $editor, $role] = $this->createEditorWithAssign();
        $deptB = $this->createDepartment('Dept B');
        $role->syncVisibleDepartments([$dept->id, $deptB->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assigneeA = $this->createUserInDepartment($dept, 'Assignee A', role: $role);
        $assigneeB = $this->createUserInDepartment($deptB, 'Assignee B', role: $role);
        $task = $this->createTask($initiator, $assigneeA, $this->createCategory());

        $this->actingAs($editor);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editAssigneeDepartmentId', $deptB->id)
            ->set('editAssigneeId', $assigneeB->id)
            ->call('saveEdit')
            ->assertHasErrors(['reassignComment']);

        $fresh = $task->fresh();
        $this->assertSame($assigneeA->id, $fresh->assignee_id);
        $this->assertSame($dept->id, $fresh->department_id);
    }

    public function test_reassignment_with_comment_updates_assignee_and_creates_comment(): void
    {
        [$dept, $editor, $role] = $this->createEditorWithAssign();
        $deptB = $this->createDepartment('Dept B');
        $role->syncVisibleDepartments([$dept->id, $deptB->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assigneeA = $this->createUserInDepartment($dept, 'Assignee A', role: $role);
        $assigneeB = $this->createUserInDepartment($deptB, 'Assignee B', role: $role);
        $task = $this->createTask($initiator, $assigneeA, $this->createCategory());

        $this->actingAs($editor);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editAssigneeDepartmentId', $deptB->id)
            ->set('editAssigneeId', $assigneeB->id)
            ->set('reassignComment', 'Handing over to another department.')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $fresh = $task->fresh();
        $this->assertSame($assigneeB->id, $fresh->assignee_id);
        $this->assertSame($deptB->id, $fresh->department_id);

        $this->assertTrue(
            TaskComment::query()
                ->where('task_id', $task->id)
                ->where('author_id', $editor->id)
                ->where('body', 'Handing over to another department.')
                ->exists()
        );
    }

    public function test_user_without_assign_permission_cannot_reassign_via_service(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::AssignTask->value,
        );
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $other = $this->createUserInDepartment($dept, 'Other', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->update($task, $initiator, ['assignee_id' => $other->id]);
    }

    public function test_user_without_assign_permission_does_not_see_reassignment_controls(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => ! in_array($p, [Permission::AssignTask->value, Permission::EditAnyTask->value], true),
        );
        $role = $this->createRoleWithPermissions(array_merge(array_values($perms), [Permission::EditOwnTask->value]));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->actingAs($initiator);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->assertDontSee(__('Department'))
            ->assertDontSee(__('Reassignment comment'));
    }

    public function test_save_edit_without_assign_permission_does_not_change_assignee(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn ($p) => $p !== Permission::AssignTask->value,
        );
        $role = $this->createRoleWithPermissions(array_merge(array_values($perms), [Permission::EditAnyTask->value]), [$dept->id]);
        $editor = $this->createUserInDepartment($dept, 'Editor', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $other = $this->createUserInDepartment($dept, 'Other', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), ['title' => 'Original title']);

        $this->actingAs($editor);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editAssigneeId', $other->id)
            ->set('editTitle', 'Updated title')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $fresh = $task->fresh();
        $this->assertSame('Updated title', $fresh->title);
        $this->assertSame($assignee->id, $fresh->assignee_id);
    }
}
