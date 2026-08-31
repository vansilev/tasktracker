<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskHistory;
use App\Services\TaskAttachmentService;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskAttachmentTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
    }

    public function test_attachment_download_forbidden_without_view_access(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $role);
        $outsider = $this->createUserInDepartment($deptB, 'Outsider', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $path = 'tasks/'.$task->id.'/sample.pdf';
        Storage::disk('attachments')->put($path, 'pdf-content');

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'filename' => 'sample.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 11,
            'uploaded_by' => $initiator->id,
        ]);

        $this->expectException(AuthorizationException::class);
        app(TaskAttachmentService::class)->downloadPath($attachment, $outsider);
    }

    public function test_attachment_download_allowed_for_viewer(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $path = 'tasks/'.$task->id.'/sample.pdf';
        Storage::disk('attachments')->put($path, 'pdf-content');

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'filename' => 'sample.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 11,
            'uploaded_by' => $initiator->id,
        ]);

        $resolved = app(TaskAttachmentService::class)->downloadPath($attachment, $initiator);

        $this->assertFileExists($resolved);
    }

    public function test_comment_attachment_saved_with_comment_id_and_visible_via_relation(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment($task, $assignee, 'See attached file');

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
        $attachment = app(TaskAttachmentService::class)->store($task, $assignee, $file, $comment->id);

        $this->assertSame($comment->id, $attachment->comment_id);
        $this->assertTrue($comment->fresh()->attachments->contains('id', $attachment->id));
    }

    public function test_user_with_comment_permission_can_attach_to_comment_without_update(): void
    {
        $dept = $this->createDepartment();
        $perms = array_filter(
            $this->defaultPermissions(),
            fn (string $p) => ! in_array($p, [Permission::EditOwnTask->value, Permission::EditAnyTask->value], true),
        );
        $role = $this->createRoleWithPermissions(array_values($perms));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->assertFalse($assignee->can('update', $task));
        $this->assertTrue($assignee->can('comment', $task));
        $this->assertTrue($assignee->can('uploadAttachment', $task));

        $comment = app(TaskService::class)->addComment($task, $assignee, 'Attachment test');
        $file = UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf');

        $attachment = app(TaskAttachmentService::class)->store($task, $assignee, $file, $comment->id);

        $this->assertSame($assignee->id, $attachment->uploaded_by);
        $this->assertSame($comment->id, $attachment->comment_id);
    }

    public function test_uploader_can_delete_own_attachment(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $attachment = app(TaskAttachmentService::class)->store($task, $assignee, $file);

        app(TaskAttachmentService::class)->delete($attachment, $assignee);

        $this->assertDatabaseMissing('task_attachments', ['id' => $attachment->id]);
    }

    public function test_outsider_with_visibility_cannot_delete_attachment(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $roleA = $this->createRoleWithPermissions($this->defaultPermissions());
        $roleB = $this->createRoleWithPermissions($this->defaultPermissions(), [$deptA->id]);
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $roleA);
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $roleA);
        $viewer = $this->createUserInDepartment($deptB, 'Viewer', role: $roleB);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $attachment = app(TaskAttachmentService::class)->store($task, $initiator, $file);

        $this->assertTrue($viewer->can('view', $task));
        $this->assertFalse($viewer->can('deleteAttachment', [$task, $attachment]));

        $this->expectException(AuthorizationException::class);
        app(TaskAttachmentService::class)->delete($attachment, $viewer);
    }

    public function test_admin_can_delete_attachment(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $attachment = app(TaskAttachmentService::class)->store($task, $initiator, $file);

        app(TaskAttachmentService::class)->delete($attachment, $admin);

        $this->assertDatabaseMissing('task_attachments', ['id' => $attachment->id]);
    }

    public function test_delete_removes_file_from_disk(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $attachment = app(TaskAttachmentService::class)->store($task, $initiator, $file);

        Storage::disk('attachments')->assertExists($attachment->path);

        app(TaskAttachmentService::class)->delete($attachment, $initiator);

        Storage::disk('attachments')->assertMissing($attachment->path);
    }

    public function test_store_writes_attachment_history(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('spec.pdf', 50, 'application/pdf');
        app(TaskAttachmentService::class)->store($task, $initiator, $file);

        $entry = TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'attachment')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->old_value);
        $this->assertSame('spec.pdf', $entry->new_value);
    }

    public function test_delete_writes_attachment_history(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('spec.pdf', 50, 'application/pdf');
        $attachment = app(TaskAttachmentService::class)->store($task, $initiator, $file);

        app(TaskAttachmentService::class)->delete($attachment, $initiator);

        $entry = TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('field', 'attachment')
            ->where('old_value', 'spec.pdf')
            ->whereNull('new_value')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
    }

    public function test_store_without_history_flag_skips_history(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $before = TaskHistory::query()->where('task_id', $task->id)->where('field', 'attachment')->count();

        $file = UploadedFile::fake()->create('spec.pdf', 50, 'application/pdf');
        app(TaskAttachmentService::class)->store($task, $initiator, $file, null, false);

        $this->assertSame($before, TaskHistory::query()->where('task_id', $task->id)->where('field', 'attachment')->count());
    }

    public function test_comment_attachment_download_allowed_for_participant(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = TaskComment::query()->create([
            'task_id' => $task->id,
            'author_id' => $assignee->id,
            'body' => 'With file',
        ]);

        $path = 'tasks/'.$task->id.'/comment-file.pdf';
        Storage::disk('attachments')->put($path, 'pdf-content');

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'comment_id' => $comment->id,
            'filename' => 'comment-file.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 11,
            'uploaded_by' => $assignee->id,
        ]);

        $resolved = app(TaskAttachmentService::class)->downloadPath($attachment, $initiator);

        $this->assertFileExists($resolved);
    }

    public function test_comment_attachment_download_forbidden_for_outsider(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($deptA, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($deptA, 'Assignee', role: $role);
        $outsider = $this->createUserInDepartment($deptB, 'Outsider', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = TaskComment::query()->create([
            'task_id' => $task->id,
            'author_id' => $assignee->id,
            'body' => 'With file',
        ]);

        $path = 'tasks/'.$task->id.'/comment-file.pdf';
        Storage::disk('attachments')->put($path, 'pdf-content');

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'comment_id' => $comment->id,
            'filename' => 'comment-file.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 11,
            'uploaded_by' => $assignee->id,
        ]);

        $this->expectException(AuthorizationException::class);
        app(TaskAttachmentService::class)->downloadPath($attachment, $outsider);
    }

    public function test_forbidden_mime_rejected(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

        $this->expectException(ValidationException::class);
        app(TaskAttachmentService::class)->store($task, $initiator, $file);
    }

    public function test_image_view_returns_inline_file(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $attachment = app(TaskAttachmentService::class)->store(
            $task,
            $initiator,
            UploadedFile::fake()->image('shot.png'),
        );

        $this->actingAs($initiator)
            ->get(route('tasks.attachments.view', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
