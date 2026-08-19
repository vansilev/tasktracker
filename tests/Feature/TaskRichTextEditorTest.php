<?php

namespace Tests\Feature;

use App\Enums\ContentFormat;
use App\Enums\ContentSource;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\MarkdownService;
use App\Services\TaskContentService;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * Behaviour of the WYSIWYG write path: the TipTap editor posts HTML, the server
 * sanitizes it, and plain-text producers keep escaping.
 */
class TaskRichTextEditorTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    /** Markup the editor can actually produce within the task_content profile. */
    private const RICH = '<h2>Заголовок</h2>'
        .'<p>Текст <strong>жирный</strong>, <em>курсив</em>, <u>подчёркнутый</u>, <s>зачёркнутый</s> 🚀</p>'
        .'<ul><li><p>первый</p></li><li><p>второй</p></li></ul>'
        .'<ol><li><p>раз</p></li></ol>'
        .'<blockquote><p>цитата</p></blockquote>'
        .'<p><a href="https://example.com/docs?q=1" rel="nofollow noreferrer noopener" target="_blank">ссылка</a></p>'
        .'<table><tbody><tr><th colspan="1" rowspan="1"><p>Шапка</p></th></tr>'
        .'<tr><td colspan="1" rowspan="1"><p>Ячейка</p></td></tr></tbody></table>'
        .'<pre><code>echo 1;</code></pre>';

    public function test_editor_html_survives_task_creation(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $this->actingAs($initiator);

        Volt::test('pages.tasks.create')
            ->set('departmentId', $dept->id)
            ->set('assigneeId', $assignee->id)
            ->set('categoryId', $category->id)
            ->set('title', 'Rich create')
            ->set('description', self::RICH)
            ->set('priority', 5)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::query()->where('title', 'Rich create')->firstOrFail();

        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertSame(self::RICH, $task->description);
        $this->assertSame(self::RICH, $task->renderedDescription());
        $this->assertRichMarkupIntact($task->description);
    }

    public function test_editor_html_survives_task_description_update(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => '<p>старое</p>',
            'description_format' => ContentFormat::Html,
        ]);

        $this->actingAs($initiator);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editTitle', $task->title)
            ->set('editDescription', self::RICH)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $task->refresh();

        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertSame(self::RICH, $task->description);
        $this->assertRichMarkupIntact($task->renderedDescription());
    }

    public function test_editor_html_survives_comment_creation_and_editing(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('commentBody', self::RICH)
            ->call('addComment')
            ->assertHasNoErrors();

        $comment = TaskComment::query()->where('task_id', $task->id)->latest('id')->firstOrFail();

        $this->assertSame(ContentFormat::Html, $comment->body_format);
        $this->assertSame(self::RICH, $comment->body);
        $this->assertSame(self::RICH, $comment->renderedBody());

        $edited = '<p>Правка с <strong>жирным</strong> и 🚀</p><ul><li><p>пункт</p></li></ul>';

        Volt::test('pages.tasks.show', ['task' => $task])
            ->call('startEditComment', $comment->id)
            ->set('editCommentBody', $edited)
            ->call('saveCommentEdit')
            ->assertHasNoErrors();

        $comment->refresh();

        $this->assertSame(ContentFormat::Html, $comment->body_format);
        $this->assertSame($edited, $comment->body);
        $this->assertNotNull($comment->edited_at);
    }

    public function test_status_transition_comment_accepts_editor_html(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category, [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition(
            $task,
            $initiator,
            TaskStatus::Rework,
            '<p>Нужны <strong>правки</strong></p>',
        );

        $comment = TaskComment::query()->where('task_id', $task->id)->latest('id')->firstOrFail();

        $this->assertSame(ContentFormat::Html, $comment->body_format);
        $this->assertSame('<p>Нужны <strong>правки</strong></p>', $comment->body);
    }

    public function test_hostile_editor_payload_is_neutralised_on_write(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $payload = '<script>alert(1)</script>'
            .'<img src=x onerror=alert(1)>'
            .'<a href="javascript:alert(1)">клик</a>'
            .'<iframe src="https://evil.test"></iframe>'
            .'<svg onload=alert(1)></svg>'
            .'<p onclick="alert(1)">безопасно</p>';

        $this->actingAs($initiator);

        Volt::test('pages.tasks.create')
            ->set('departmentId', $dept->id)
            ->set('assigneeId', $assignee->id)
            ->set('categoryId', $category->id)
            ->set('title', 'Hostile create')
            ->set('description', $payload)
            ->set('priority', 5)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::query()->where('title', 'Hostile create')->firstOrFail();

        // Stored, not just rendered: the write path is the first line of defence.
        $this->assertHtmlIsInert($task->description);
        $this->assertHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>безопасно</p>', $task->description);
        $this->assertStringContainsString('клик', $task->description);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editTitle', $task->title)
            ->set('editDescription', '<p>ок</p>'.$payload)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertHtmlIsInert($task->refresh()->description);

        $comment = app(TaskService::class)->addComment($task, $initiator, $payload);

        $this->assertHtmlIsInert($comment->body);
        $this->assertHtmlIsInert($comment->renderedBody());

        app(TaskService::class)->updateComment($comment, $initiator, $payload.'<p>ещё</p>');

        $this->assertHtmlIsInert($comment->refresh()->body);
    }

    public function test_plain_text_sources_are_escaped_rather_than_interpreted(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);
        $content = app(TaskContentService::class);
        $literal = 'a < b & <b>жирный</b>';

        $this->assertSame(
            '<p>a &lt; b &amp; &lt;b&gt;жирный&lt;/b&gt;</p>',
            $content->fromPlainTextSource($literal),
        );

        $comment = app(TaskService::class)->addComment(
            $task,
            $assignee,
            $literal,
            ContentSource::PlainText,
        );

        $this->assertSame('<p>a &lt; b &amp; &lt;b&gt;жирный&lt;/b&gt;</p>', $comment->body);
        $this->assertSame($literal, $comment->body_text);
        $this->assertStringNotContainsString('<b>', $comment->renderedBody());

        // Same input through the editor path is parsed as markup, which is the
        // whole point of keeping the two sources apart. (<b> itself is not in
        // the profile, so the tag is dropped and only its text survives.)
        $this->assertSame('a &lt; b &amp; жирный', $content->fromEditorHtml($literal));
        $this->assertSame(
            '<p>Текст <strong>жирный</strong></p>',
            $content->fromEditorHtml('<p>Текст <strong>жирный</strong></p>'),
        );
    }

    public function test_non_ascii_link_targets_are_percent_encoded_but_stable(): void
    {
        $content = app(TaskContentService::class);
        $typed = '<p><a href="https://example.com/док?q=1">ссылка</a></p>';

        $stored = $content->fromEditorHtml($typed);

        // The sanitizer normalises the path to percent-encoding. The link still
        // resolves to the same target and re-saving does not change it again.
        $this->assertStringContainsString('https://example.com/%D0%B4%D0%BE%D0%BA?q=1', $stored);
        $this->assertSame($stored, $content->fromEditorHtml($stored));
        $this->assertSame($stored, $content->render($stored, ContentFormat::Html));
    }

    public function test_visually_empty_editor_output_fails_validation_everywhere(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);
        $comment = app(TaskService::class)->addComment($task, $assignee, '<p>исходный</p>');

        foreach (['<p></p>', '<p><br></p>', '<p>   </p>', '<p></p><p><br></p>'] as $empty) {
            $this->actingAs($initiator);

            Volt::test('pages.tasks.create')
                ->set('departmentId', $dept->id)
                ->set('assigneeId', $assignee->id)
                ->set('categoryId', $category->id)
                ->set('title', 'Empty')
                ->set('description', $empty)
                ->set('priority', 5)
                ->call('save')
                ->assertHasErrors(['description']);

            Volt::test('pages.tasks.show', ['task' => $task])
                ->set('editing', true)
                ->set('editTitle', $task->title)
                ->set('editDescription', $empty)
                ->call('saveEdit')
                ->assertHasErrors(['editDescription']);

            Volt::test('pages.tasks.show', ['task' => $task])
                ->set('commentBody', $empty)
                ->call('addComment')
                ->assertHasErrors(['commentBody']);

            $this->actingAs($assignee);

            Volt::test('pages.tasks.show', ['task' => $task])
                ->call('startEditComment', $comment->id)
                ->set('editCommentBody', $empty)
                ->call('saveCommentEdit')
                ->assertHasErrors(['editCommentBody']);
        }

        $this->assertSame('<p>исходный</p>', $comment->refresh()->body);
    }

    public function test_html_round_trip_through_the_edit_flow_is_byte_stable(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => self::RICH,
            'description_format' => ContentFormat::Html,
        ]);
        $comment = app(TaskService::class)->addComment($task, $assignee, self::RICH);

        $content = app(TaskContentService::class);

        // Open in the editor, save without touching anything: nothing may drift.
        $loaded = $content->toEditorHtml($task->description, $task->description_format);
        $this->assertSame(self::RICH, $loaded);

        app(TaskService::class)->update($task, $initiator, ['description' => $loaded]);
        $task->refresh();
        $this->assertSame(self::RICH, $task->description);

        $loadedComment = $content->toEditorHtml($comment->body, $comment->body_format);
        app(TaskService::class)->updateComment($comment, $assignee, $loadedComment);
        $this->assertSame(self::RICH, $comment->refresh()->body);

        $this->assertRichMarkupIntact($task->description);
        $this->assertRichMarkupIntact($comment->body);
    }

    public function test_legacy_markdown_description_opens_as_html_and_is_stored_as_html(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $markdown = "# Заголовок\n\nHello **world** 🚀\n\n- один\n- два\n\n[док](https://example.com)";
        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => $markdown,
            'description_format' => ContentFormat::Markdown,
        ]);

        $this->actingAs($initiator);

        $component = Volt::test('pages.tasks.show', ['task' => $task]);
        $loaded = $component->get('editDescription');

        // The editor is fed rendered HTML, not raw markdown syntax.
        $this->assertSame(app(MarkdownService::class)->toHtml($markdown), $loaded);
        $this->assertStringContainsString('<strong>world</strong>', $loaded);
        $this->assertStringContainsString('<li>один</li>', $loaded);
        $this->assertStringNotContainsString('**world**', $loaded);

        $component
            ->set('editing', true)
            ->set('editTitle', $task->title)
            ->set('editDescription', $loaded)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $task->refresh();

        // Saving flips the marker, so the row is no longer read as markdown.
        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertStringContainsString('<strong>world</strong>', $task->description);
        $this->assertStringContainsString('🚀', $task->description);
        $this->assertStringContainsString('href="https://example.com"', $task->description);
    }

    public function test_legacy_markdown_comment_opens_as_html_and_is_stored_as_html(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $comment = $task->comments()->make([
            'author_id' => $assignee->id,
            'body' => 'Please review **this** carefully',
        ]);
        $comment->body_format = ContentFormat::Markdown;
        $comment->save();

        $this->actingAs($assignee);

        $component = Volt::test('pages.tasks.show', ['task' => $task])
            ->call('startEditComment', $comment->id);

        $loaded = $component->get('editCommentBody');
        $this->assertStringContainsString('<strong>this</strong>', $loaded);
        $this->assertStringNotContainsString('**this**', $loaded);

        $component
            ->set('editCommentBody', $loaded)
            ->call('saveCommentEdit')
            ->assertHasNoErrors();

        $comment->refresh();

        $this->assertSame(ContentFormat::Html, $comment->body_format);
        $this->assertStringContainsString('<strong>this</strong>', $comment->body);
    }

    public function test_tiptap_table_markup_loses_only_its_widths_and_then_stays_stable(): void
    {
        $content = app(TaskContentService::class);

        // Exactly what TipTap emits for a 2x2 table: a width style on the table
        // and a colgroup of styled cols. The profile allows neither style.
        $emitted = '<table style="min-width: 50px"><colgroup><col style="min-width: 25px">'
            .'<col style="min-width: 25px"></colgroup><tbody>'
            .'<tr><th colspan="1" rowspan="1"><p>A</p></th><th colspan="1" rowspan="1"><p>B</p></th></tr>'
            .'<tr><td colspan="1" rowspan="1"><p>1</p></td><td colspan="1" rowspan="1"><p>2</p></td></tr>'
            .'</tbody></table>';

        $stored = $content->fromEditorHtml($emitted);

        $this->assertStringNotContainsString('style=', $stored);
        $this->assertStringContainsString('<th colspan="1" rowspan="1"><p>A</p></th>', $stored);
        $this->assertStringContainsString('<td colspan="1" rowspan="1"><p>2</p></td>', $stored);

        // Column widths are the only casualty, and saving again changes nothing.
        $this->assertSame($stored, $content->fromEditorHtml($stored));
        $this->assertSame($stored, $content->render($stored, ContentFormat::Html));
    }

    public function test_every_content_field_renders_the_shared_editor_component(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::AssignTask->value],
        ), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $assigneeB = $this->createUserInDepartment($dept, 'Assignee B', role: $role);
        $category = $this->createCategory();
        $task = $this->createTask($initiator, $assignee, $category);
        $comment = app(TaskService::class)->addComment($task, $initiator, '<p>к правке</p>');

        $this->actingAs($initiator);

        $create = Volt::test('pages.tasks.create')->set('departmentId', $dept->id);
        $this->assertStringContainsString('richTextEditor(', $create->html());
        $this->assertStringContainsString('wire:key="task-create-description"', $create->html());

        $show = Volt::test('pages.tasks.show', ['task' => $task]);
        $this->assertStringContainsString('wire:key="task-new-comment"', $show->html());

        $editing = Volt::test('pages.tasks.show', ['task' => $task])->set('editing', true);
        $this->assertStringContainsString('wire:key="task-edit-description"', $editing->html());

        $editingComment = Volt::test('pages.tasks.show', ['task' => $task])
            ->call('startEditComment', $comment->id);
        $this->assertStringContainsString(
            'wire:key="task-edit-comment-'.$comment->id.'"',
            $editingComment->html(),
        );

        // Rejected requires a comment from New, so selectTransition opens the panel.
        $transition = Volt::test('pages.tasks.show', ['task' => $task])
            ->call('selectTransition', TaskStatus::Rejected->value);
        $this->assertStringContainsString('wire:key="task-transition-comment"', $transition->html());

        // Reassignment comment editor only appears after assignee changes while editing.
        $reassign = Volt::test('pages.tasks.show', ['task' => $task])
            ->set('editing', true)
            ->set('editAssigneeId', $assigneeB->id);
        $this->assertStringContainsString('wire:key="task-reassign-comment"', $reassign->html());

        // The ProseMirror surface has to sit inside wire:ignore or a re-render
        // destroys the editor instance while the user is typing.
        $this->assertStringContainsString('wire:ignore', $show->html());

        // The textarea-driven mention dropdown is gone; nothing may still call it.
        $this->assertStringNotContainsString('mentionAutocomplete', $show->html());

        // Mentions autocomplete is on comments and description fields.
        $this->assertEditorMentionsEnabled($show->html(), 'task-new-comment', true);
        $this->assertEditorMentionsEnabled(
            $editingComment->html(),
            'task-edit-comment-'.$comment->id,
            true,
        );
        $this->assertEditorMentionsEnabled($create->html(), 'task-create-description', true);
        $this->assertEditorMentionsEnabled($editing->html(), 'task-edit-description', true);
        $this->assertEditorMentionsEnabled($transition->html(), 'task-transition-comment', true);
        $this->assertEditorMentionsEnabled($reassign->html(), 'task-reassign-comment', true);

        // Inline attachments: show uses task-scoped upload; create uses pending upload.
        $this->assertEditorInlineAttachmentsEnabled($show->html(), 'task-new-comment', true);
        $this->assertEditorInlineAttachmentsEnabled($editing->html(), 'task-edit-description', true);
        $this->assertEditorInlineAttachmentsEnabled($create->html(), 'task-create-description', true);
    }

    private function assertEditorMentionsEnabled(string $html, string $wireKey, bool $enabled): void
    {
        $this->assertMatchesRegularExpression(
            '/wire:key="'.preg_quote($wireKey, '/').'"[\s\S]{0,800}?enableMentions:\s*'.($enabled ? 'true' : 'false').'/',
            $html,
            "Editor {$wireKey} should have enableMentions: ".($enabled ? 'true' : 'false'),
        );
    }

    private function assertEditorInlineAttachmentsEnabled(string $html, string $wireKey, bool $enabled): void
    {
        $this->assertMatchesRegularExpression(
            '/wire:key="'.preg_quote($wireKey, '/').'"[\s\S]{0,900}?enableInlineAttachments:\s*'.($enabled ? 'true' : 'false').'/',
            $html,
            "Editor {$wireKey} should have enableInlineAttachments: ".($enabled ? 'true' : 'false'),
        );
    }

    private function assertRichMarkupIntact(string $html): void
    {
        $this->assertStringContainsString('<h2>Заголовок</h2>', $html);
        $this->assertStringContainsString('<strong>жирный</strong>', $html);
        $this->assertStringContainsString('<em>курсив</em>', $html);
        $this->assertStringContainsString('<u>подчёркнутый</u>', $html);
        $this->assertStringContainsString('<s>зачёркнутый</s>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th colspan="1" rowspan="1">', $html);
        $this->assertStringContainsString('href="https://example.com/', $html);
        $this->assertStringContainsString('<pre><code>echo 1;</code></pre>', $html);
        $this->assertStringContainsString('🚀', $html);
        $this->assertStringContainsString('Заголовок', $html);
    }

    private function assertHtmlIsInert(string $html): void
    {
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);
        $this->assertStringNotContainsStringIgnoringCase('onload', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $html);
        $this->assertStringNotContainsStringIgnoringCase('<svg', $html);
        // Hostile / non-attachment <img> must not survive; legitimate attachment
        // view URLs are allowlisted separately (see TaskInlineAttachmentTest).
        $this->assertStringNotContainsStringIgnoringCase('<img', $html);
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
