<?php

namespace Tests\Feature;

use App\Enums\ContentFormat;
use App\Enums\ContentSource;
use App\Enums\SystemType;
use App\Models\Category;
use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\HtmlContentService;
use App\Services\MarkdownService;
use App\Services\MarkdownToHtmlConverter;
use App\Services\MentionService;
use App\Services\TaskContentService;
use App\Services\TaskNotificationService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskContentCutoverTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_conversion_command_is_idempotent_and_byte_stable(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $markdown = "Hello **world**\n\nSee https://example.com and <script>alert(1)</script> & more.";

        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Convert Me',
            'description' => $markdown,
            'description_format' => ContentFormat::Markdown->value,
            'description_text' => null,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $taskId,
            'author_id' => $assignee->id,
            'body' => 'Ping @assignee please',
            'body_format' => ContentFormat::Markdown->value,
            'body_text' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('tasks:convert-markdown-to-html');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Converted 1 task(s) and 1 comment(s).', Artisan::output());

        $afterFirstTask = DB::table('tasks')->where('id', $taskId)->first();
        $afterFirstComment = DB::table('task_comments')->where('id', $commentId)->first();

        $this->assertSame(ContentFormat::Html->value, $afterFirstTask->description_format);
        $this->assertSame(ContentFormat::Html->value, $afterFirstComment->body_format);

        $exit = Artisan::call('tasks:convert-markdown-to-html');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Converted 0 task(s) and 0 comment(s).', Artisan::output());

        $afterSecondTask = DB::table('tasks')->where('id', $taskId)->first();
        $afterSecondComment = DB::table('task_comments')->where('id', $commentId)->first();

        $this->assertSame($afterFirstTask->description, $afterSecondTask->description);
        $this->assertSame($afterFirstComment->body, $afterSecondComment->body);
        $this->assertSame($afterFirstTask->description_text, $afterSecondTask->description_text);
        $this->assertSame($afterFirstComment->body_text, $afterSecondComment->body_text);
    }

    public function test_conversion_matches_markdown_service_and_keeps_markup_inert(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $markdown = "Compare < > & and typed <script>alert(1)</script>\n\nDone.";
        $expectedHtml = app(MarkdownService::class)->toHtml($markdown);

        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Fidelity',
            'description' => $markdown,
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(MarkdownToHtmlConverter::class)->run();

        $task = Task::query()->findOrFail($taskId);

        $this->assertSame($expectedHtml, $task->description);
        $this->assertSame($expectedHtml, $task->renderedDescription());
        $this->assertStringContainsString('&lt;', $task->description);
        $this->assertStringContainsString('&gt;', $task->description);
        $this->assertStringContainsString('&amp;', $task->description);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $task->description);
        $this->assertStringNotContainsString('<script>', $task->description);
        $this->assertStringNotContainsString('<script>', $task->renderedDescription());
    }

    public function test_conversion_preserves_autolinked_urls(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $markdown = 'Visit https://example.com/path?q=1 for details';
        $expectedHtml = app(MarkdownService::class)->toHtml($markdown);

        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Autolink',
            'description' => $markdown,
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(MarkdownToHtmlConverter::class)->run();

        $task = Task::query()->findOrFail($taskId);

        $this->assertSame($expectedHtml, $task->description);
        $this->assertStringContainsString('href="https://example.com/path?q=1"', $task->description);
    }

    public function test_literal_mention_survives_conversion_and_still_resolves(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $role = $assignee->roles()->first();
        $mentioned = $this->createNamedUser($dept, $role, 'MentionTarget', 'mention.target@tcsavant.com');

        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Mention convert',
            'description' => 'desc',
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $taskId,
            'author_id' => $assignee->id,
            'body' => 'Please review @MentionTarget',
            'body_format' => ContentFormat::Markdown->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(MarkdownToHtmlConverter::class)->run();

        $comment = TaskComment::query()->findOrFail($commentId);
        $this->assertSame(ContentFormat::Html, $comment->body_format);
        $this->assertStringContainsString('@MentionTarget', $comment->body);

        $resolved = app(MentionService::class)->parseMentionedUsers($comment->body);
        $this->assertTrue($resolved->contains('id', $mentioned->id));
    }

    public function test_mailto_autolink_does_not_create_phantom_mention_tokens(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $role = $assignee->roles()->first();

        // After CommonMark email autolinking, href/text contain "@example.com".
        // A user whose email local-part is that token must not be falsely mentioned.
        $phantom = $this->createNamedUser($dept, $role, 'Phantom Example', 'example.com@tcsavant.com');

        $markdownBody = 'Reach me at user@example.com thanks';
        $htmlBody = app(MarkdownService::class)->toHtml($markdownBody);

        $this->assertStringContainsString('mailto:user@example.com', $htmlBody);

        $resolved = app(MentionService::class)->parseMentionedUsers($htmlBody);

        $this->assertFalse(
            $resolved->contains('id', $phantom->id),
            'mailto autolinks must not yield @tokens that match unrelated users.',
        );
        $this->assertFalse($resolved->contains('id', $assignee->id));
        $this->assertTrue($resolved->isEmpty());
    }

    public function test_format_aware_rendering_for_markdown_and_html_rows(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();

        $markdownTask = $this->createTask($initiator, $assignee, $category, [
            'description' => 'Hello **bold**',
            'description_format' => ContentFormat::Markdown,
        ]);

        $html = '<p>Already <strong>html</strong></p>';
        $htmlTask = $this->createTask($initiator, $assignee, $category, [
            'description' => $html,
            'description_format' => ContentFormat::Html,
        ]);

        $this->assertSame(
            app(MarkdownService::class)->toHtml('Hello **bold**'),
            $markdownTask->renderedDescription(),
        );
        $this->assertSame($html, $htmlTask->renderedDescription());
        $this->assertStringContainsString('<strong>bold</strong>', $markdownTask->renderedDescription());
        $this->assertStringNotContainsString('**bold**', $markdownTask->renderedDescription());
    }

    public function test_empty_markup_fails_description_and_comment_validation_including_edit(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();
        $task = $this->createTask($initiator, $assignee, $category);

        $comment = app(TaskService::class)->addComment($task, $assignee, 'editable comment');

        $this->actingAs($initiator);

        Volt::test('pages.tasks.create')
            ->set('departmentId', $dept->id)
            ->set('assigneeId', $assignee->id)
            ->set('categoryId', $category->id)
            ->set('title', 'Empty desc')
            ->set('description', '<p></p>')
            ->set('priority', 5)
            ->call('save')
            ->assertHasErrors(['description']);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('commentBody', '<p></p>')
            ->call('addComment')
            ->assertHasErrors(['commentBody']);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->call('startEditComment', $comment->id)
            ->set('editCommentBody', '<p></p>')
            ->call('saveCommentEdit')
            ->assertHasErrors(['editCommentBody']);
    }

    public function test_notification_excerpt_for_html_comment_has_no_tags_or_entities(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        app(TaskService::class)->addComment(
            $task,
            $assignee,
            "Line one & two\n\n<script>alert(1)</script>",
            ContentSource::PlainText,
        );

        $notification = $initiator->fresh()->notifications
            ->first(fn ($n) => ($n->data['event'] ?? '') === 'task.commented');

        $this->assertNotNull($notification);
        $excerpt = $notification->data['comment_excerpt'] ?? '';
        $this->assertNotSame('', $excerpt);
        // Storage markup is stripped; entities are decoded to real characters.
        $this->assertStringNotContainsString('<p>', $excerpt);
        $this->assertStringNotContainsString('<br>', $excerpt);
        $this->assertStringNotContainsString('&amp;', $excerpt);
        $this->assertStringNotContainsString('&lt;', $excerpt);
        $this->assertSame('Line one & two <script>alert(1)</script>', $excerpt);
    }

    public function test_single_newlines_are_preserved_as_br_on_new_writes(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();

        $task = app(TaskService::class)->create($initiator, [
            'department_id' => $assignee->department_id,
            'assignee_id' => $assignee->id,
            'category_id' => $category->id,
            'title' => 'Line breaks',
            'description' => "first line\nsecond line",
            'priority' => 5,
        ], descriptionSource: ContentSource::PlainText);

        $this->assertSame('<p>first line<br>second line</p>', $task->description);
        $this->assertStringContainsString('<br>', $task->renderedDescription());

        // Contrast: CommonMark (used by the conversion path) does not emit <br> for soft breaks.
        $converted = app(MarkdownService::class)->toHtml("first line\nsecond line");
        $this->assertStringNotContainsString('<br>', $converted);
    }

    public function test_conversion_includes_soft_deleted_tasks_and_does_not_bump_updated_at(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $fixed = '2026-03-01 10:20:30';
        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Soft deleted',
            'description' => 'Soft **deleted** markdown',
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => $fixed,
            'updated_at' => $fixed,
            'deleted_at' => $fixed,
        ]);

        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $taskId,
            'author_id' => $assignee->id,
            'body' => 'comment on soft-deleted task',
            'body_format' => ContentFormat::Markdown->value,
            'created_at' => $fixed,
            'updated_at' => $fixed,
        ]);

        Artisan::call('tasks:convert-markdown-to-html');

        $taskRow = DB::table('tasks')->where('id', $taskId)->first();
        $commentRow = DB::table('task_comments')->where('id', $commentId)->first();

        $this->assertSame(ContentFormat::Html->value, $taskRow->description_format);
        $this->assertSame(ContentFormat::Html->value, $commentRow->body_format);
        $this->assertStringContainsString('<strong>deleted</strong>', $taskRow->description);
        $this->assertSame($fixed, $taskRow->updated_at);
        $this->assertSame($fixed, $commentRow->updated_at);
        $this->assertNotNull($taskRow->deleted_at);
    }

    public function test_plain_text_comment_source_escapes_instead_of_parsing(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $comment = app(TaskService::class)->addComment(
            $task,
            $assignee,
            'a < b & c',
            ContentSource::PlainText,
        );

        $this->assertSame(ContentFormat::Html, $comment->body_format);
        $this->assertSame('<p>a &lt; b &amp; c</p>', $comment->body);
        $this->assertSame('a < b & c', $comment->body_text);
    }

    public function test_mass_assignment_cannot_mark_description_as_trusted_html(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => 'safe',
            'description_format' => ContentFormat::Markdown,
        ]);

        $task->update([
            'description' => '<script>alert(1)</script>',
            'description_format' => 'html',
        ]);

        $task->refresh();

        $this->assertSame(ContentFormat::Markdown, $task->description_format);
        $rendered = $task->renderedDescription();
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringNotContainsString('onerror', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_html_row_description_is_sanitized_on_write(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => '<p>safe</p>',
            'description_format' => ContentFormat::Html,
        ]);

        $task->update([
            'description' => '<script>alert(1)</script><p>ok</p><img src=x onerror=alert(1)>',
        ]);

        $task->refresh();

        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertStringNotContainsString('<script>', $task->description);
        $this->assertStringNotContainsString('onerror', $task->description);
        $this->assertStringNotContainsString('<script>', $task->renderedDescription());
        $this->assertStringNotContainsString('onerror', $task->renderedDescription());
        $this->assertStringContainsString('<p>ok</p>', $task->description);
    }

    public function test_mass_assignment_cannot_mark_comment_body_as_trusted_html(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $comment = $task->comments()->create([
            'author_id' => $assignee->id,
            'body' => '<script>alert(1)</script><p>hi</p>',
            'body_format' => 'html',
        ]);

        $comment->refresh();

        $this->assertSame(ContentFormat::Markdown, $comment->body_format);
        $rendered = $comment->renderedBody();
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringNotContainsString('onerror', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_html_sanitize_on_write_is_byte_idempotent(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $html = '<p>Hello <strong>world</strong> and <em>more</em></p><ul><li>one</li><li>two</li></ul>';

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => $html,
            'description_format' => ContentFormat::Html,
        ]);

        $first = $task->fresh()->description;

        $task->description = $first;
        $task->save();

        $this->assertSame($first, $task->fresh()->description);
    }

    public function test_sanitize_on_write_preserves_from_plain_text_output(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $fromPlain = app(HtmlContentService::class)
            ->fromPlainText("line one\nline two\n\n<script>alert(1)</script> & more");

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => $fromPlain,
            'description_format' => ContentFormat::Html,
        ]);

        $this->assertSame($fromPlain, $task->fresh()->description);
        $this->assertStringContainsString('&lt;script&gt;', $task->description);
        $this->assertStringContainsString('<p>', $task->description);
        $this->assertStringContainsString('<br>', $task->description);
    }

    public function test_sanitize_on_write_preserves_cyrillic_emoji_and_long_content(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $long = str_repeat('я', 4000);
        $html = '<p>Привіт 🚀 '.$long.'</p>';

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => $html,
            'description_format' => ContentFormat::Html,
        ]);

        $stored = $task->fresh()->description;
        $this->assertStringContainsString('Привіт', $stored);
        $this->assertStringContainsString('🚀', $stored);
        $this->assertStringContainsString($long, $stored);
        $this->assertSame($html, $stored);
    }

    public function test_conversion_quarantines_dishonest_html_marked_as_markdown(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $htmlAsMarkdownId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Dishonest',
            'description' => '<p>Already HTML</p>',
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legitId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Legit md',
            'description' => 'Hello **world**',
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('tasks:convert-markdown-to-html');
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Converted 1 task(s) and 0 comment(s).', $output);
        $this->assertStringContainsString('Quarantined 1 row(s)', $output);
        $this->assertStringContainsString((string) $htmlAsMarkdownId, $output);

        $this->assertSame(
            ContentFormat::Markdown->value,
            DB::table('tasks')->where('id', $htmlAsMarkdownId)->value('description_format'),
        );
        $this->assertSame(
            '<p>Already HTML</p>',
            DB::table('tasks')->where('id', $htmlAsMarkdownId)->value('description'),
        );
        $this->assertSame(
            ContentFormat::Html->value,
            DB::table('tasks')->where('id', $legitId)->value('description_format'),
        );
    }

    public function test_conversion_force_converts_quarantined_html_marked_as_markdown(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $id = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Force convert',
            'description' => '<p>Already HTML</p>',
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('tasks:convert-markdown-to-html', ['--force' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Converted 1 task(s) and 0 comment(s).', Artisan::output());
        $this->assertStringNotContainsString('Quarantined', Artisan::output());

        $row = DB::table('tasks')->where('id', $id)->first();
        $this->assertSame(ContentFormat::Html->value, $row->description_format);
        // Forced through CommonMark with html_input=escape → visible escaped tags.
        $this->assertStringContainsString('&lt;p&gt;', $row->description);
    }

    public function test_conversion_does_not_quarantine_html_inside_markdown_code_fence(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();

        $markdown = "Example:\n\n```\n<p>hello</p>\n```\n\nDone.";

        $id = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Fenced p',
            'description' => $markdown,
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(MarkdownToHtmlConverter::class)->looksLikeBlockHtml($markdown));

        $exit = Artisan::call('tasks:convert-markdown-to-html');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Converted 1 task(s) and 0 comment(s).', Artisan::output());
        $this->assertStringNotContainsString('Quarantined', Artisan::output());
        $this->assertSame(
            ContentFormat::Html->value,
            DB::table('tasks')->where('id', $id)->value('description_format'),
        );
    }

    public function test_notification_excerpt_renders_markdown_and_html_comments(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);

        $htmlComment = app(TaskService::class)->addComment($task, $assignee, 'Hello **not bold**');
        $this->assertSame(ContentFormat::Html, $htmlComment->body_format);

        $htmlNotification = $initiator->fresh()->notifications
            ->first(fn ($n) => ($n->data['event'] ?? '') === 'task.commented');
        $this->assertNotNull($htmlNotification);
        $this->assertSame('Hello **not bold**', $htmlNotification->data['comment_excerpt']);
        $this->assertStringNotContainsString('<p>', $htmlNotification->data['comment_excerpt']);

        $initiator->notifications()->delete();

        $mdComment = $task->comments()->make([
            'author_id' => $assignee->id,
            'body' => 'Please review **this** carefully',
        ]);
        $mdComment->body_format = ContentFormat::Markdown;
        $mdComment->save();

        app(TaskNotificationService::class)
            ->notifyComment($task, $assignee, $mdComment, collect());

        $mdNotification = $initiator->fresh()->notifications
            ->first(fn ($n) => ($n->data['event'] ?? '') === 'task.commented');
        $this->assertNotNull($mdNotification);
        $this->assertSame('Please review this carefully', $mdNotification->data['comment_excerpt']);
        $this->assertStringNotContainsString('**', $mdNotification->data['comment_excerpt']);
    }

    public function test_format_only_flip_via_save_does_not_render_executable_markup(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a><iframe src="https://evil.test"></iframe><svg onload=alert(1)></svg><p>ok</p>';

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => $payload,
            'description_format' => ContentFormat::Markdown,
        ]);

        $task->description_format = ContentFormat::Html;
        $task->save();
        $task->refresh();

        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertRenderedHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>ok</p>', $task->renderedDescription());
    }

    public function test_force_fill_format_only_does_not_render_executable_markup(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><p>ok</p>';

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => $payload,
            'description_format' => ContentFormat::Markdown,
        ]);

        $task->forceFill(['description_format' => ContentFormat::Html])->save();
        $task->refresh();

        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertRenderedHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>ok</p>', $task->renderedDescription());
    }

    public function test_save_quietly_raw_html_is_sanitized_on_render(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a><p>ok</p>';

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => '<p>safe</p>',
            'description_format' => ContentFormat::Html,
        ]);

        $task->description = $payload;
        $task->description_format = ContentFormat::Html;
        $task->saveQuietly();
        $task->refresh();

        $this->assertSame($payload, $task->description);
        $this->assertRenderedHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>ok</p>', $task->renderedDescription());
    }

    public function test_without_events_raw_html_is_sanitized_on_render(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $payload = '<script>alert(1)</script><svg onload=alert(1)></svg><iframe src="https://evil.test"></iframe><p>ok</p>';

        $task = $this->createTask($initiator, $assignee, $category, [
            'description' => '<p>safe</p>',
            'description_format' => ContentFormat::Html,
        ]);

        Task::withoutEvents(function () use ($task, $payload): void {
            $task->description = $payload;
            $task->description_format = ContentFormat::Html;
            $task->save();
        });
        $task->refresh();

        $this->assertSame($payload, $task->description);
        $this->assertRenderedHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>ok</p>', $task->renderedDescription());
    }

    public function test_query_builder_task_insert_and_update_raw_html_sanitized_on_render(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a><p>ok</p>';

        $id = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'QB insert',
            'description' => $payload,
            'description_format' => ContentFormat::Html->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::query()->findOrFail($id);
        $this->assertSame($payload, $task->description);
        $this->assertRenderedHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>ok</p>', $task->renderedDescription());

        $updated = '<svg onload=alert(1)></svg><iframe src="https://evil.test"></iframe><p>updated</p>';
        DB::table('tasks')->where('id', $id)->update([
            'description' => $updated,
            'description_format' => ContentFormat::Html->value,
        ]);

        $task = Task::query()->findOrFail($id);
        $this->assertSame($updated, $task->description);
        $this->assertRenderedHtmlIsInert($task->renderedDescription());
        $this->assertStringContainsString('<p>updated</p>', $task->renderedDescription());
    }

    public function test_query_builder_comment_insert_and_update_raw_html_sanitized_on_render(): void
    {
        [$initiator, $assignee, $category] = $this->seedActors();
        $task = $this->createTask($initiator, $assignee, $category);
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a><p>hi</p>';

        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $task->id,
            'author_id' => $assignee->id,
            'body' => $payload,
            'body_format' => ContentFormat::Html->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $comment = TaskComment::query()->findOrFail($commentId);
        $this->assertSame($payload, $comment->body);
        $this->assertRenderedHtmlIsInert($comment->renderedBody());
        $this->assertStringContainsString('<p>hi</p>', $comment->renderedBody());

        $updated = '<svg onload=alert(1)></svg><iframe src="https://evil.test"></iframe><p>later</p>';
        DB::table('task_comments')->where('id', $commentId)->update([
            'body' => $updated,
            'body_format' => ContentFormat::Html->value,
        ]);

        $comment = TaskComment::query()->findOrFail($commentId);
        $this->assertSame($updated, $comment->body);
        $this->assertRenderedHtmlIsInert($comment->renderedBody());
        $this->assertStringContainsString('<p>later</p>', $comment->renderedBody());
    }

    public function test_conversion_output_without_autolinks_renders_byte_identically(): void
    {
        [$initiator, $assignee, $category, $dept] = $this->seedActors();
        $markdown = "Compare < > & and typed <script>alert(1)</script>\n\nDone.";
        $expectedHtml = app(MarkdownService::class)->toHtml($markdown);

        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Convert render noop',
            'description' => $markdown,
            'description_format' => ContentFormat::Markdown->value,
            'priority' => 5,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(MarkdownToHtmlConverter::class)->run();

        $task = Task::query()->findOrFail($taskId);
        $this->assertSame($expectedHtml, $task->description);
        // CommonMark with html_input=escape and no autolinks: sanitize-on-render is a no-op.
        $this->assertSame($task->description, $task->renderedDescription());
        $this->assertSame($expectedHtml, $task->renderedDescription());
    }

    public function test_rich_html_render_is_byte_stable_across_repeated_calls(): void
    {
        $rich = <<<'HTML'
<p>Привіт <strong>bold</strong> and <em>italic</em> 🚀 — see <a href="https://example.com/path?q=1" target="_blank" rel="noopener">docs</a>.</p>
<ul><li>First</li><li>Nested:<ul><li>a</li><li>b</li></ul></li></ul>
<h2>Section</h2>
<blockquote>Quoted note</blockquote>
<table><thead><tr><th colspan="2">Head</th></tr></thead><tbody><tr><td>c1</td><td>c2</td></tr></tbody></table>
<pre><code>echo "hi";</code></pre>
HTML;

        $content = app(TaskContentService::class);
        $first = $content->render($rich, ContentFormat::Html);
        $second = $content->render($rich, ContentFormat::Html);
        $third = $content->render($first, ContentFormat::Html);

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertStringContainsString('<strong>bold</strong>', $first);
        $this->assertStringContainsString('<ul>', $first);
        $this->assertStringContainsString('colspan="2"', $first);
        $this->assertStringContainsString('href="https://example.com/path?q=1"', $first);
        $this->assertStringContainsString('rel="nofollow noreferrer noopener"', $first);
        $this->assertStringContainsString('target="_blank"', $first);
        $this->assertStringContainsString('<h2>', $first);
        $this->assertStringContainsString('<blockquote>', $first);
        $this->assertStringContainsString('<pre>', $first);
        $this->assertStringContainsString('Привіт', $first);
        $this->assertStringContainsString('🚀', $first);
        $this->assertRenderedHtmlIsInert($first);
    }

    /**
     * @return array{0: User, 1: User, 2: Category, 3: Department}
     */
    private function seedActors(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        return [$initiator, $assignee, $this->createCategory(), $dept];
    }

    private function createNamedUser(Department $department, ?Role $role, string $name, string $email): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'department_id' => $department->id,
            'system_type' => SystemType::User,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    private function assertRenderedHtmlIsInert(string $rendered): void
    {
        // Case-insensitive: attackers write <SCRIPT> and OnErRoR just as easily.
        $this->assertStringNotContainsStringIgnoringCase('<script', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('</script', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('onload', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('<svg', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('<style', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('expression(', $rendered);
    }
}
