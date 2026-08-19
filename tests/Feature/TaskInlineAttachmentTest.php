<?php

namespace Tests\Feature;

use App\Enums\ContentFormat;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\HtmlContentService;
use App\Services\SettingsService;
use App\Services\TaskAttachmentService;
use App\Services\TaskContentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskInlineAttachmentTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
    }

    public function test_http_inline_attachment_returns_payload_with_relative_urls(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($assignee);

        $response = $this->postJson(route('tasks.attachments.inline', $task), [
            'file' => UploadedFile::fake()->image('shot.png', 40, 40),
        ]);

        $response->assertOk();
        $payload = $response->json();
        $attachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();

        $this->assertSame($attachment->id, $payload['id']);
        $this->assertSame('shot.png', $payload['filename']);
        $this->assertTrue($payload['isImage']);
        $this->assertNull($attachment->comment_id);
        $this->assertSame('/tasks/attachments/'.$attachment->id.'/view', $payload['viewUrl']);
        $this->assertSame('/tasks/attachments/'.$attachment->id.'/download', $payload['downloadUrl']);
    }

    public function test_http_inline_attachment_forbidden_without_permission(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $outsider = User::factory()->create(['is_active' => true]);
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($outsider);

        $this->postJson(route('tasks.attachments.inline', $task), [
            'file' => UploadedFile::fake()->image('shot.png', 40, 40),
        ])->assertForbidden();
    }

    public function test_store_inline_attachment_returns_payload_with_relative_urls(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($assignee);

        $component = Volt::test('pages.tasks.show', ['task' => $task])
            ->set('inlineAttachmentFile', UploadedFile::fake()->image('shot.png', 40, 40));

        $payload = $component->instance()->storeInlineAttachment(
            app(TaskAttachmentService::class),
            app(SettingsService::class),
        );

        $attachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();

        $this->assertSame($attachment->id, $payload['id']);
        $this->assertSame('shot.png', $payload['filename']);
        $this->assertTrue($payload['isImage']);
        $this->assertNull($attachment->comment_id);
        $this->assertSame('/tasks/attachments/'.$attachment->id.'/view', $payload['viewUrl']);
        $this->assertSame('/tasks/attachments/'.$attachment->id.'/download', $payload['downloadUrl']);
        $this->assertNull($component->get('inlineAttachmentFile'));
    }

    public function test_store_inline_document_returns_non_image_payload(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($initiator);

        $component = Volt::test('pages.tasks.show', ['task' => $task])
            ->set('inlineAttachmentFile', UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf'));

        $payload = $component->instance()->storeInlineAttachment(
            app(TaskAttachmentService::class),
            app(SettingsService::class),
        );

        $this->assertFalse($payload['isImage']);
        $this->assertSame('brief.pdf', $payload['filename']);
        $this->assertStringEndsWith('/download', $payload['downloadUrl']);
    }

    public function test_inline_attachment_image_survives_sanitize_and_task_save(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => '<p>start</p>',
            'description_format' => ContentFormat::Html,
        ]);

        $this->actingAs($initiator);

        $component = Volt::test('pages.tasks.show', ['task' => $task])
            ->set('inlineAttachmentFile', UploadedFile::fake()->image('diagram.png', 32, 32));

        $payload = $component->instance()->storeInlineAttachment(
            app(TaskAttachmentService::class),
            app(SettingsService::class),
        );

        $html = '<p><a href="'.$payload['viewUrl'].'"><img src="'.$payload['viewUrl'].'" alt="diagram.png"></a></p>';

        $sanitized = app(HtmlContentService::class)->sanitize($html);
        $this->assertStringContainsString('src="'.$payload['viewUrl'].'"', $sanitized);
        $this->assertStringContainsString('alt="diagram.png"', $sanitized);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editTitle', $task->title)
            ->set('editDescription', $html)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $task->refresh();

        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertStringContainsString('src="'.$payload['viewUrl'].'"', $task->description);
        $this->assertStringContainsString(
            'src="'.$payload['viewUrl'].'"',
            app(TaskContentService::class)->render($task->description, $task->description_format),
        );
        $this->assertStringNotContainsString('onerror', $task->description);
    }

    public function test_inline_document_chip_survives_comment_save(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($assignee);

        $component = Volt::test('pages.tasks.show', ['task' => $task])
            ->set('inlineAttachmentFile', UploadedFile::fake()->create('notes.docx', 80, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

        $payload = $component->instance()->storeInlineAttachment(
            app(TaskAttachmentService::class),
            app(SettingsService::class),
        );

        $html = '<p><a href="'.$payload['downloadUrl'].'" title="notes.docx">📄 notes.docx</a></p>';

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('commentBody', $html)
            ->call('addComment')
            ->assertHasNoErrors();

        $comment = $task->fresh()->comments()->latest('id')->firstOrFail();

        $this->assertStringContainsString('href="'.$payload['downloadUrl'].'"', $comment->body);
        $this->assertStringContainsString('notes.docx', $comment->body);
        $this->assertStringContainsString('📄', $comment->renderedBody());
    }

    public function test_show_editors_enable_inline_attachments_create_does_not(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($initiator);

        $show = Volt::test('pages.tasks.show', ['task' => $task]);
        $this->assertMatchesRegularExpression(
            '/wire:key="task-new-comment"[\s\S]{0,1200}?enableInlineAttachments:\s*true/',
            $show->html(),
        );
        $this->assertStringContainsString('inlineUploadUrl:', $show->html());
        $this->assertTrue(
            str_contains($show->html(), 'attachments/inline')
            || str_contains($show->html(), 'attachments\\/inline'),
            'Show editors should expose the HTTP inline upload URL',
        );

        $editing = Volt::test('pages.tasks.show', ['task' => $task])->set('editing', true);
        $this->assertMatchesRegularExpression(
            '/wire:key="task-edit-description"[\s\S]{0,1200}?enableInlineAttachments:\s*true/',
            $editing->html(),
        );

        $create = Volt::test('pages.tasks.create')->set('departmentId', $dept->id);
        $this->assertMatchesRegularExpression(
            '/wire:key="task-create-description"[\s\S]{0,1200}?enableInlineAttachments:\s*true/',
            $create->html(),
        );
        $this->assertTrue(
            str_contains($create->html(), 'pending-attachments/inline')
            || str_contains($create->html(), 'pending-attachments\\/inline'),
            'Create editor should expose the pending inline upload URL',
        );
    }

    public function test_pending_inline_attachment_promotes_on_task_create(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $this->actingAs($initiator);

        $response = $this->postJson(route('pending.attachments.inline'), [
            'file' => UploadedFile::fake()->image('create-shot.png', 40, 40),
        ]);

        $response->assertOk();
        $pendingId = $response->json('id');
        $this->assertSame('/pending-attachments/'.$pendingId.'/view', $response->json('viewUrl'));

        $description = '<p><a href="/pending-attachments/'.$pendingId.'/view">'
            .'<img src="/pending-attachments/'.$pendingId.'/view" alt="create-shot.png"></a></p>';

        Volt::test('pages.tasks.create')
            ->set('departmentId', $dept->id)
            ->set('categoryId', $category->id)
            ->set('assigneeId', $assignee->id)
            ->set('title', 'With inline image')
            ->set('description', $description)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $task = Task::query()->where('title', 'With inline image')->firstOrFail();
        $attachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();

        $this->assertStringContainsString(
            '/tasks/attachments/'.$attachment->id.'/view',
            $task->description,
        );
        $this->assertStringNotContainsString('/pending-attachments/', $task->description);
        $this->assertDatabaseMissing('pending_inline_attachments', ['id' => $pendingId]);
    }

    public function test_create_page_refresh_attachments_is_noop(): void
    {
        [$initiator, , , $dept] = $this->seedActors();
        $this->actingAs($initiator);

        Volt::test('pages.tasks.create')
            ->set('departmentId', $dept->id)
            ->call('refreshAttachments')
            ->assertHasNoErrors()
            ->assertOk();
    }

    public function test_pending_inline_forbidden_without_create_permission(): void
    {
        // Users with no roles inherit Permission::defaults() (includes create_task).
        // Attach an empty role so create is denied.
        $role = $this->createRoleWithPermissions([]);
        $dept = $this->createDepartment();
        $outsider = $this->createUserInDepartment($dept, 'No Create', role: $role);
        $this->actingAs($outsider);

        $this->postJson(route('pending.attachments.inline'), [
            'file' => UploadedFile::fake()->image('shot.png', 40, 40),
        ])->assertForbidden();
    }

    /** @return array{0: User, 1: User, 2: Category, 3: Department} */
    private function seedActors(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        return [$initiator, $assignee, $this->createCategory(), $dept];
    }
}
