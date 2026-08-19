<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\TaskAttachmentService;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskSoftDeleteTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_admin_can_soft_delete_task_and_writes_audit_log(): void
    {
        $admin = User::factory()->create([
            'email' => 'soft-del-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator Soft', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee Soft', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Task to delete',
        ]);

        $this->actingAs($admin);

        app(TaskService::class)->softDelete($task, $admin);

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertNull(Task::query()->find($task->id));
        $this->assertNotNull(Task::onlyTrashed()->find($task->id));

        $log = AuditLog::query()->where('action', 'task.deleted')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($task->id, $log->entity_id);
        $this->assertSame('Task to delete', $log->old_values['title']);
    }

    public function test_regular_user_cannot_soft_delete_task(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator NoDel', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee NoDel', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->expectException(AuthorizationException::class);
        app(TaskService::class)->softDelete($task, $initiator);
    }

    public function test_admin_can_restore_soft_deleted_task(): void
    {
        $admin = User::factory()->create([
            'email' => 'soft-rest-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator Rest', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee Rest', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Task to restore',
        ]);

        $this->actingAs($admin);
        $tasks = app(TaskService::class);
        $tasks->softDelete($task, $admin);

        $trashed = Task::onlyTrashed()->findOrFail($task->id);
        $tasks->restore($trashed, $admin);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'deleted_at' => null,
        ]);
        $this->assertNotNull(Task::query()->find($task->id));

        $log = AuditLog::query()->where('action', 'task.restored')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Task to restore', $log->new_values['title']);
    }

    public function test_admin_deleted_tasks_page_lists_and_restores(): void
    {
        $admin = User::factory()->create([
            'email' => 'soft-page-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator Page', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee Page', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Visible deleted task',
        ]);

        $this->actingAs($admin);
        app(TaskService::class)->softDelete($task, $admin);

        $this->get('/admin/deleted-tasks')
            ->assertOk()
            ->assertSee('Visible deleted task');

        Volt::test('pages.admin.deleted-tasks')
            ->call('restore', $task->id)
            ->assertHasNoErrors();

        $this->assertNotNull(Task::query()->find($task->id));
    }

    public function test_create_allocates_number_above_soft_deleted_tasks(): void
    {
        $admin = User::factory()->create([
            'email' => 'soft-num-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator Num', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee Num', role: $role);
        $category = $this->createCategory();

        $deleted = $this->createTask($initiator, $assignee, $category, [
            'number' => 100,
            'title' => 'Soft deleted high number',
        ]);
        $this->actingAs($admin);
        app(TaskService::class)->softDelete($deleted, $admin);

        $this->createTask($initiator, $assignee, $category, [
            'number' => 50,
            'title' => 'Live lower number',
        ]);

        $this->actingAs($initiator);
        $created = app(TaskService::class)->create($initiator, [
            'department_id' => $dept->id,
            'assignee_id' => $assignee->id,
            'category_id' => $category->id,
            'title' => 'After soft delete',
            'description' => '<p>описание после удаления</p>',
            'priority' => 5,
        ]);

        $this->assertSame(101, $created->number);
        $this->assertDatabaseHas('tasks', [
            'id' => $created->id,
            'number' => 101,
            'deleted_at' => null,
        ]);
    }

    public function test_image_attachment_view_opens_inline(): void
    {
        Storage::fake('attachments');

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator Img', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee Img', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->image('screenshot.png', 40, 40);
        $attachment = app(TaskAttachmentService::class)
            ->store($task, $initiator, $file);

        $this->actingAs($initiator)
            ->get(route('tasks.attachments.view', $attachment))
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('content-disposition', 'inline; filename="screenshot.png"');
    }

    public function test_non_image_view_falls_back_to_download(): void
    {
        Storage::fake('attachments');

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator Pdf', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee Pdf', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $path = 'tasks/'.$task->id.'/doc.pdf';
        Storage::disk('attachments')->put($path, '%PDF-1.4');

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'filename' => 'doc.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 8,
            'uploaded_by' => $initiator->id,
        ]);

        $response = $this->actingAs($initiator)
            ->get(route('tasks.attachments.view', $attachment));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_attachment_model_detects_images(): void
    {
        $image = new TaskAttachment(['mime' => 'image/jpeg']);
        $pdf = new TaskAttachment(['mime' => 'application/pdf']);

        $this->assertTrue($image->isImage());
        $this->assertFalse($pdf->isImage());
    }
}
