<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Task;
use App\Services\AuditLogPresenter;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use App\Enums\TaskStatus;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskAuditTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_task_creation_writes_audit_log(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $this->actingAs($initiator);

        $task = app(TaskService::class)->create($initiator, [
            'department_id' => $dept->id,
            'assignee_id' => $assignee->id,
            'category_id' => $category->id,
            'title' => 'Audit create test',
            'description' => 'Description',
            'priority' => 5,
        ]);

        $log = AuditLog::query()->where('action', 'task.created')->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame($initiator->id, $log->actor_id);
        $this->assertSame(Task::class, $log->entity_type);
        $this->assertSame($task->id, $log->entity_id);
        $this->assertSame('Audit create test', $log->new_values['title']);
        $this->assertSame($task->number, $log->new_values['number']);
        $this->assertNotNull($log->ip);
    }

    public function test_task_update_writes_audit_log_with_changed_fields(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Before title',
        ]);

        $this->actingAs($initiator);

        app(TaskService::class)->update($task, $initiator, [
            'title' => 'After title',
        ]);

        $log = AuditLog::query()->where('action', 'task.updated')->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame($initiator->id, $log->actor_id);
        $this->assertSame('Before title', $log->old_values['title']);
        $this->assertSame('After title', $log->new_values['title']);
    }

    public function test_task_status_change_writes_audit_log(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [\App\Enums\Permission::ChangeStatus->value],
        ));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->actingAs($assignee);

        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::InProgress);

        $log = AuditLog::query()->where('action', 'task.status_changed')->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame($assignee->id, $log->actor_id);
        $this->assertSame(TaskStatus::New->value, $log->old_values['status']);
        $this->assertSame(TaskStatus::InProgress->value, $log->new_values['status']);
    }

    public function test_successful_login_writes_audit_log(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Login User');

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $log = AuditLog::query()->where('action', 'auth.login')->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->email, $log->new_values['email']);
        $this->assertSame('password', $log->new_values['method']);
        $this->assertNotNull($log->ip);
    }

    public function test_audit_page_shows_human_readable_action_and_summary(): void
    {
        app()->setLocale('ru');

        $admin = \App\Models\User::factory()->create([
            'email' => 'audit-ui-'.uniqid().'@tcsavant.com',
            'system_type' => \App\Enums\SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        AuditLog::query()->create([
            'actor_id' => $admin->id,
            'action' => 'auth.login',
            'entity_type' => \App\Models\User::class,
            'entity_id' => $admin->id,
            'old_values' => null,
            'new_values' => ['email' => $admin->email, 'method' => 'password'],
            'ip' => '127.0.0.1',
            'user_agent' => 'Test',
            'created_at' => now(),
        ]);

        $presenter = app(AuditLogPresenter::class);

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertSee('127.0.0.1')
            ->assertSee($presenter->actionLabel('auth.login'))
            ->assertSee($presenter->summarize(null, ['email' => $admin->email, 'method' => 'password'], 'auth.login'));
    }
}
