<?php

namespace Tests\Feature;

use App\Models\TaskComment;
use App\Models\TaskCommentReaction;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskCommentReplyReactionTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_reply_stores_parent_and_notifies_original_author(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $commenter = $this->createUserInDepartment($dept, 'Commenter', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $task->watchers()->attach($commenter->id);

        $parent = app(TaskService::class)->addComment($task, $commenter, 'Original note');
        $reply = app(TaskService::class)->addComment(
            $task,
            $assignee,
            'Thanks',
            parentCommentId: $parent->id,
        );

        $this->assertSame($parent->id, $reply->parent_comment_id);
        $this->assertEquals([$parent->id], $reply->fresh()->quotedComments->pluck('id')->all());
        $this->assertTrue(
            $commenter->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.comment_replied'),
        );
        $this->assertFalse(
            $commenter->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.commented'),
        );
    }

    public function test_reply_to_own_comment_does_not_notify_author(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $parent = app(TaskService::class)->addComment($task, $assignee, 'My note');
        $before = $assignee->fresh()->notifications()->count();

        app(TaskService::class)->addComment($task, $assignee, 'Follow-up', parentCommentId: $parent->id);

        $this->assertSame($before, $assignee->fresh()->notifications()->count());
    }

    public function test_reaction_notifies_comment_author_and_toggles_off(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment($task, $assignee, 'Please look');

        $added = app(TaskService::class)->toggleCommentReaction($comment, $initiator, '👍');
        $this->assertTrue($added);
        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.comment_reacted'
                && ($n->data['emoji'] ?? '') === '👍'),
        );

        $removed = app(TaskService::class)->toggleCommentReaction($comment, $initiator, '👍');
        $this->assertFalse($removed);
        $this->assertSame(0, $comment->reactions()->count());
        $this->assertCount(
            1,
            $assignee->fresh()->notifications->filter(fn ($n) => ($n->data['event'] ?? '') === 'task.comment_reacted'),
        );
    }

    public function test_own_reaction_does_not_notify(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment($task, $assignee, 'Mine');
        $before = $assignee->fresh()->notifications()->count();

        app(TaskService::class)->toggleCommentReaction($comment, $assignee, '🔥');

        $this->assertSame($before, $assignee->fresh()->notifications()->count());
        $this->assertSame(1, $comment->reactions()->count());
    }

    public function test_extended_emoji_is_allowed(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment($task, $assignee, 'Mine');

        $this->assertTrue(app(TaskService::class)->toggleCommentReaction($comment, $initiator, '🙏'));
        $this->assertSame('🙏', $comment->reactions()->first()->emoji);
    }

    public function test_invalid_reaction_is_rejected(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $comment = app(TaskService::class)->addComment($task, $assignee, 'Nope');

        $this->expectException(ValidationException::class);
        app(TaskService::class)->toggleCommentReaction($comment, $initiator, '🍕');
    }

    public function test_show_page_quote_and_reaction_buttons_work(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $parent = app(TaskService::class)->addComment($task, $assignee, 'Need a quote');

        $this->actingAs($initiator);

        $page = Volt::test('pages.tasks.show', ['task' => $task])
            ->assertSee(__('Quote'))
            ->assertSee(__('Add reaction'))
            ->call('quoteComment', $parent->id)
            ->assertDispatched('insert-comment-quote')
            ->assertSee('Need a quote')
            ->set('commentBody', '<p>Quoted back</p>')
            ->call('addComment')
            ->assertHasNoErrors();

        $this->assertSame([], array_values($page->get('quoteCommentIds')));

        $reply = TaskComment::query()->where('parent_comment_id', $parent->id)->first();
        $this->assertNotNull($reply);
        $this->assertEquals([$parent->id], $reply->quotedComments->pluck('id')->all());

        Volt::test('pages.tasks.show', ['task' => $task])
            ->call('toggleReaction', $parent->id, '🎉')
            ->assertHasNoErrors();

        $this->assertTrue(
            TaskCommentReaction::query()
                ->where('task_comment_id', $parent->id)
                ->where('user_id', $initiator->id)
                ->where('emoji', '🎉')
                ->exists(),
        );
    }

    public function test_multiple_quotes_are_stored_and_notify_each_author(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $commenter = $this->createUserInDepartment($dept, 'Commenter', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $task->watchers()->attach($commenter->id);

        $first = app(TaskService::class)->addComment($task, $commenter, 'First note');
        $second = app(TaskService::class)->addComment($task, $assignee, 'Second note');
        $reply = app(TaskService::class)->addComment(
            $task,
            $initiator,
            'Both',
            quotedCommentIds: [$first->id, $second->id],
        );

        $this->assertSame($first->id, $reply->parent_comment_id);
        $this->assertEquals([$first->id, $second->id], $reply->quotedComments->pluck('id')->all());
        $this->assertTrue(
            $commenter->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.comment_replied'),
        );
        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.comment_replied'),
        );
    }

    public function test_show_page_accumulates_quotes_instead_of_replacing(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $first = app(TaskService::class)->addComment($task, $assignee, 'Alpha quote');
        $second = app(TaskService::class)->addComment($task, $assignee, 'Beta quote');

        $this->actingAs($initiator);

        $page = Volt::test('pages.tasks.show', ['task' => $task])
            ->call('quoteComment', $first->id)
            ->call('quoteComment', $second->id);

        $this->assertSame([$first->id, $second->id], array_map('intval', $page->get('quoteCommentIds')));
        $page->assertDispatched('insert-comment-quote');

        $page->call('removeQuote', $first->id);
        $this->assertSame([$second->id], array_map('intval', $page->get('quoteCommentIds')));

        $page->set('commentBody', '<p>Reply</p>')
            ->call('addComment')
            ->assertHasNoErrors();

        $reply = TaskComment::query()->where('parent_comment_id', $second->id)->first();
        $this->assertNotNull($reply);
        $this->assertEquals([$second->id], $reply->quotedComments->pluck('id')->all());
    }

    public function test_comment_html_quote_blocks_drive_stored_quotes(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());
        $first = app(TaskService::class)->addComment($task, $assignee, 'Alpha quote');
        $second = app(TaskService::class)->addComment($task, $assignee, 'Beta quote');

        $this->actingAs($initiator);

        $html = '<p>hello</p>'
            .'<blockquote class="comment-quote" data-quoted-comment-id="'.$first->id.'"><p>Alpha quote</p></blockquote>'
            .'<p>and then</p>'
            .'<blockquote class="comment-quote" data-quoted-comment-id="'.$second->id.'"><p>Beta quote</p></blockquote>';

        Volt::test('pages.tasks.show', ['task' => $task])
            ->set('commentBody', $html)
            ->call('addComment')
            ->assertHasNoErrors();

        $reply = TaskComment::query()->where('author_id', $initiator->id)->latest('id')->first();
        $this->assertNotNull($reply);
        $this->assertEquals([$first->id, $second->id], $reply->quotedComments->pluck('id')->all());
        $this->assertStringContainsString('data-quoted-comment-id="'.$first->id.'"', $reply->body);
        $this->assertTrue($reply->quotesAreInline());
    }
}
