<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\AuditLogPresenter;
use App\Services\HtmlContentService;
use App\Services\TaskHistoryPresenter;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskPlainTextShadowTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_creating_a_task_populates_description_text(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'description' => '<p>Hello</p><p>world</p>',
        ]);

        $this->assertSame('Hello world', $task->fresh()->description_text);
    }

    public function test_updating_task_description_updates_description_text(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::EditAnyTask->value],
        ), [$dept->id]);
        $editor = $this->createUserInDepartment($dept, 'Editor', role: $role);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'description' => 'Original plain text',
        ]);

        app(TaskService::class)->update($task, $editor, [
            'description' => 'alpha<strong>beta</strong>gamma',
        ]);

        $this->assertSame('alphabetagamma', $task->fresh()->description_text);
    }

    public function test_creating_a_comment_populates_body_text(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment(
            $task,
            $assignee,
            'foo<em>bar</em>baz',
        );

        $this->assertSame('foobarbaz', $comment->fresh()->body_text);
    }

    public function test_updating_a_comment_updates_body_text(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment($task, $assignee, 'first body');
        app(TaskService::class)->updateComment($comment, $assignee, '<p>second</p><p>body</p>');

        $this->assertSame('second body', $comment->fresh()->body_text);
    }

    public function test_search_matches_via_description_text_not_raw_markup(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        // Inline markup splits the needle in the raw column; shadow text concatenates it.
        $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Unrelated Title',
            'description' => 'plain<strong>ShadowSearchNeedle</strong>text',
        ]);
        $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Other Task',
            'description' => 'no match here',
        ]);

        $admin = User::factory()->create([
            'email' => 'shadow-search-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('search', 'plainShadowSearchNeedletext')
            ->assertSee('Unrelated Title')
            ->assertDontSee('Other Task');
    }

    public function test_search_matches_via_comment_body_text_not_raw_markup(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $matching = $this->createTask($initiator, $assignee, $category, [
            'title' => 'Comment Match Task',
            'description' => 'plain description',
        ]);
        $this->createTask($initiator, $assignee, $category, [
            'title' => 'Comment Miss Task',
            'description' => 'plain description',
        ]);

        TaskComment::query()->create([
            'task_id' => $matching->id,
            'author_id' => $assignee->id,
            'body' => 'aaa<em>CommentShadowNeedle</em>bbb',
        ]);

        $admin = User::factory()->create([
            'email' => 'shadow-comment-search@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('search', 'aaaCommentShadowNeedlebbb')
            ->assertSee('Comment Match Task')
            ->assertDontSee('Comment Miss Task');
    }

    public function test_empty_title_preview_contains_no_markup(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => '',
            'description' => '<p>Preview<strong>Plain</strong>Content</p>',
        ]);

        $admin = User::factory()->create([
            'email' => 'shadow-preview-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->assertSee('PreviewPlainContent')
            ->assertDontSee('<p>')
            ->assertDontSee('<strong>')
            ->assertDontSee('</p>');
    }

    public function test_backfill_command_populates_legacy_rows_and_is_idempotent(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $now = now()->toDateTimeString();
        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Legacy Task',
            'description' => '<p>Legacy</p><p>Description</p>',
            'description_text' => null,
            'priority' => 5,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $taskId,
            'author_id' => $assignee->id,
            'body' => 'legacy<em>comment</em>body',
            'body_text' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertNull(DB::table('tasks')->where('id', $taskId)->value('description_text'));
        $this->assertNull(DB::table('task_comments')->where('id', $commentId)->value('body_text'));

        Artisan::call('tasks:backfill-plain-text');

        $this->assertSame(
            'Legacy Description',
            DB::table('tasks')->where('id', $taskId)->value('description_text'),
        );
        $this->assertSame(
            'legacycommentbody',
            DB::table('task_comments')->where('id', $commentId)->value('body_text'),
        );

        $exit = Artisan::call('tasks:backfill-plain-text');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Updated 0 task(s) and 0 comment(s).', Artisan::output());
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $now = now()->toDateTimeString();
        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Dry Run Task',
            'description' => '<p>Dry</p><p>Run</p>',
            'description_text' => null,
            'priority' => 5,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Artisan::call('tasks:backfill-plain-text', ['--dry-run' => true]);

        $this->assertStringContainsString('Would update 1 task(s)', Artisan::output());
        $this->assertNull(DB::table('tasks')->where('id', $taskId)->value('description_text'));
        $this->assertSame(
            '<p>Dry</p><p>Run</p>',
            DB::table('tasks')->where('id', $taskId)->value('description'),
        );
    }

    public function test_history_presenter_strips_markup_before_truncating_description(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $raw = '<p>'.str_repeat('x', 100).'</p>';
        app(TaskWorkflowService::class)->logHistory($task, 'description', null, $raw, $initiator);
        $entry = $task->histories()->where('field', 'description')->latest('id')->first();

        $presented = app(TaskHistoryPresenter::class)->present($entry);

        $this->assertStringNotContainsString('<', $presented['new']);
        $this->assertStringNotContainsString('>', $presented['new']);
        $this->assertSame(str_repeat('x', 80).'…', $presented['new']);
    }

    public function test_audit_presenter_strips_markup_before_truncating_description(): void
    {
        $raw = '<strong>'.str_repeat('y', 100).'</strong>';

        $summary = app(AuditLogPresenter::class)->summarize(
            ['description' => 'old'],
            ['description' => $raw],
            'task.updated',
        );

        $this->assertStringNotContainsString('<', $summary);
        $this->assertStringNotContainsString('strong', $summary);
        $this->assertStringContainsString(str_repeat('y', 60).'…', $summary);
    }

    public function test_saving_unrelated_fields_does_not_recompute_description_text(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'description' => 'stable description',
        ]);

        // Simulate a stale shadow column; updating an unrelated field must not overwrite it.
        DB::table('tasks')->where('id', $task->id)->update([
            'description_text' => 'stale-shadow',
        ]);

        $task->refresh();
        $task->update(['priority' => 9]);

        $this->assertSame('stale-shadow', $task->fresh()->description_text);
        $this->assertSame(
            'stable description',
            app(HtmlContentService::class)->toPlainText($task->fresh()->description),
        );
    }

    public function test_search_finds_rows_with_null_description_text_via_coalesce(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $now = now()->toDateTimeString();
        DB::table('tasks')->insert([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Null Shadow Search Task',
            'description' => 'UniqueNullShadowSearchTerm',
            'description_text' => null,
            'priority' => 5,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admin = User::factory()->create([
            'email' => 'null-shadow-search@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('search', 'UniqueNullShadowSearchTerm')
            ->assertSee('Null Shadow Search Task');
    }

    public function test_search_finds_comments_with_null_body_text_via_coalesce(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'Null Comment Shadow Task',
            'description' => 'unrelated',
        ]);

        $now = now()->toDateTimeString();
        DB::table('task_comments')->insert([
            'task_id' => $task->id,
            'author_id' => $assignee->id,
            'body' => 'UniqueNullCommentShadowTerm',
            'body_text' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admin = User::factory()->create([
            'email' => 'null-comment-shadow@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('search', 'UniqueNullCommentShadowTerm')
            ->assertSee('Null Comment Shadow Task');
    }

    public function test_preview_falls_back_when_description_text_is_null(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $now = now()->toDateTimeString();
        DB::table('tasks')->insert([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => '',
            'description' => '<p>NullShadow<strong>Preview</strong>Text</p>',
            'description_text' => null,
            'priority' => 5,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admin = User::factory()->create([
            'email' => 'null-shadow-preview@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->assertSee('NullShadowPreviewText')
            ->assertDontSee('<p>')
            ->assertDontSee('<strong>');
    }

    public function test_backfill_includes_soft_deleted_tasks(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'description' => '<p>Soft</p><p>Deleted</p>',
        ]);

        $task->delete();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);

        DB::table('tasks')->where('id', $task->id)->update([
            'description_text' => null,
        ]);

        Artisan::call('tasks:backfill-plain-text');

        $this->assertSame(
            'Soft Deleted',
            DB::table('tasks')->where('id', $task->id)->value('description_text'),
        );
    }

    public function test_backfill_does_not_bump_updated_at(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        $fixed = '2026-01-15 12:34:56';
        $taskId = DB::table('tasks')->insertGetId([
            'number' => (Task::query()->max('number') ?? 0) + 1,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $dept->id,
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'title' => 'Timestamp Task',
            'description' => '<p>Keep</p><p>Timestamp</p>',
            'description_text' => null,
            'priority' => 5,
            'status' => 'new',
            'created_at' => $fixed,
            'updated_at' => $fixed,
        ]);

        $commentId = DB::table('task_comments')->insertGetId([
            'task_id' => $taskId,
            'author_id' => $assignee->id,
            'body' => 'keep<em>stamp</em>',
            'body_text' => null,
            'created_at' => $fixed,
            'updated_at' => $fixed,
        ]);

        Artisan::call('tasks:backfill-plain-text');

        $this->assertSame('Keep Timestamp', DB::table('tasks')->where('id', $taskId)->value('description_text'));
        $this->assertSame('keepstamp', DB::table('task_comments')->where('id', $commentId)->value('body_text'));
        $this->assertSame($fixed, DB::table('tasks')->where('id', $taskId)->value('updated_at'));
        $this->assertSame($fixed, DB::table('task_comments')->where('id', $commentId)->value('updated_at'));
    }
}
