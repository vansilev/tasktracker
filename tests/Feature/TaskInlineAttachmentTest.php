<?php

namespace Tests\Feature;

use App\Enums\ContentFormat;
use App\Models\Category;
use App\Models\Department;
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
            '/wire:key="task-new-comment"[\s\S]{0,900}?enableInlineAttachments:\s*true/',
            $show->html(),
        );

        $editing = Volt::test('pages.tasks.show', ['task' => $task])->set('editing', true);
        $this->assertMatchesRegularExpression(
            '/wire:key="task-edit-description"[\s\S]{0,900}?enableInlineAttachments:\s*true/',
            $editing->html(),
        );

        $create = Volt::test('pages.tasks.create')->set('departmentId', $dept->id);
        $this->assertMatchesRegularExpression(
            '/wire:key="task-create-description"[\s\S]{0,900}?enableInlineAttachments:\s*false/',
            $create->html(),
        );
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
