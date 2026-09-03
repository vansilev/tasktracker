<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\TaskAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskShowPageTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
    }

    /** @return array{task: Task, initiator: User, assignee: User} */
    private function createTaskWithFullContent(): array
    {
        $dept = $this->createDepartment('Dept Main');
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Watcher', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $task->watchers()->attach($watcher->id);

        TaskChecklistItem::query()->create([
            'task_id' => $task->id,
            'text' => 'Checklist step',
            'is_done' => false,
            'sort_order' => 1,
        ]);

        $comment = TaskComment::query()->create([
            'task_id' => $task->id,
            'author_id' => $assignee->id,
            'body' => 'Comment with file',
        ]);

        $attachments = app(TaskAttachmentService::class);
        $attachments->store(
            $task,
            $assignee,
            UploadedFile::fake()->create('comment-file.pdf', 50, 'application/pdf'),
            $comment->id,
        );
        $attachments->store(
            $task,
            $initiator,
            UploadedFile::fake()->create('task-file.pdf', 50, 'application/pdf'),
        );

        return ['task' => $task, 'initiator' => $initiator, 'assignee' => $assignee];
    }

    public function test_initiator_can_render_task_page_with_attachments_checklist_and_watcher(): void
    {
        ['task' => $task, 'initiator' => $initiator] = $this->createTaskWithFullContent();

        $this->actingAs($initiator)
            ->get('/tasks/'.$task->id)
            ->assertOk()
            ->assertSee($task->title)
            ->assertSee('task-file.pdf')
            ->assertSee('comment-file.pdf');
    }

    public function test_assignee_can_render_task_page(): void
    {
        ['task' => $task, 'assignee' => $assignee] = $this->createTaskWithFullContent();

        $this->actingAs($assignee)
            ->get('/tasks/'.$task->id)
            ->assertOk();
    }

    public function test_outsider_without_visibility_gets_403(): void
    {
        ['task' => $task] = $this->createTaskWithFullContent();

        $otherDept = $this->createDepartment('Dept Other');
        $roleWithoutVisibility = $this->createRoleWithPermissions($this->defaultPermissions());
        $outsider = $this->createUserInDepartment($otherDept, 'Outsider', role: $roleWithoutVisibility);

        $this->actingAs($outsider)
            ->get('/tasks/'.$task->id)
            ->assertForbidden();
    }

    public function test_admin_can_render_task_page(): void
    {
        ['task' => $task] = $this->createTaskWithFullContent();

        $admin = $this->createUserInDepartment($task->department, 'Admin', SystemType::Admin);

        $this->actingAs($admin)
            ->get('/tasks/'.$task->id)
            ->assertOk();
    }

    public function test_show_page_uses_lightbox_for_image_attachments(): void
    {
        ['task' => $task, 'initiator' => $initiator] = $this->createTaskWithFullContent();

        app(TaskAttachmentService::class)->store(
            $task,
            $initiator,
            UploadedFile::fake()->image('shot.png'),
        );

        $html = $this->actingAs($initiator)
            ->get('/tasks/'.$task->id)
            ->assertOk()
            ->assertSee('shot.png', false)
            ->assertSee('x-data="attachmentLightbox"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/attachments\/\d+\/view"[^>]*target="_blank"/',
            $html,
        );
        $this->assertStringContainsString('data-attachment-preview="pdf"', $html);
        $this->assertStringContainsString('data-attachment-lightbox-prev', $html);
        $this->assertStringContainsString('data-attachment-lightbox-pdf', $html);
    }

    public function test_comments_render_as_conversation_bubbles(): void
    {
        ['task' => $task, 'initiator' => $initiator, 'assignee' => $assignee] = $this->createTaskWithFullContent();

        $this->actingAs($assignee)
            ->get('/tasks/'.$task->id)
            ->assertOk()
            ->assertSee('data-ui="message-scroller"', false)
            ->assertSee('data-ui="message"', false)
            ->assertSee('data-mine="true"', false)
            ->assertSee('Comment with file')
            ->assertSee('comment-file.pdf');

        $this->actingAs($initiator)
            ->get('/tasks/'.$task->id)
            ->assertOk()
            ->assertSee('data-ui="message"', false)
            ->assertDontSee('data-mine="true"', false);
    }
}
