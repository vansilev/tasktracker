<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\MentionService;
use App\Services\TaskService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskMentionTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_mention_adds_watcher(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createUserInDepartment($dept, 'Mentioned User', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $emailLocal = strstr($mentioned->email, '@', true);

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            'Please review @'.$emailLocal,
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
    }

    public function test_cyrillic_name_mention_adds_watcher_and_notifies(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, 'Татьяна', 'tatiana.mention@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            'Привет @Татьяна, посмотри задачу',
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
        $this->assertTrue(
            $mentioned->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
    }

    public function test_cyrillic_spaced_name_mention_matches_name_without_spaces(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, 'Орешкова Валерія', 'valeria.oreshkova@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            'CC @ОрешковаВалерія',
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
        $this->assertTrue(
            $mentioned->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
    }

    public function test_at_prefixed_name_mention_matches_with_at_token_variant(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, '@anna_belka_2806', 'anna.belka.2806@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            'Ping @anna_belka_2806 please',
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
        $this->assertTrue(
            $mentioned->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
    }

    public function test_email_prefix_mention_still_works(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createUserInDepartment($dept, 'Mentioned User', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $emailLocal = strstr($mentioned->email, '@', true);

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            'Please review @'.$emailLocal,
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
        $this->assertTrue(
            $mentioned->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
    }

    public function test_mention_search_returns_active_users_only(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $tatiana = $this->createNamedUser($dept, $role, 'Татьяна', 'tatiana.search@tcsavant.com');
        $inactive = $this->createNamedUser($dept, $role, 'Татьяна Неактивная', 'tatiana.inactive@tcsavant.com');
        $inactive->update(['is_active' => false]);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->actingAs($initiator);

        $component = Volt::test('pages.tasks.show', ['task' => $task]);
        $results = $component->instance()->mentionSearch('тать');

        $this->assertCount(1, $results);
        $this->assertSame($tatiana->id, $results[0]['id']);
        $this->assertSame('Татьяна', $results[0]['name']);
        $this->assertSame('tatiana.search@tcsavant.com', $results[0]['email']);
        $this->assertSame('Татьяна', $results[0]['token']);
        $this->assertSame(
            ['id', 'name', 'email', 'token'],
            array_keys($results[0]),
        );
    }

    public function test_mention_search_on_empty_query_returns_active_people(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $inactive = $this->createNamedUser($dept, $role, 'Hidden User', 'hidden.mention@tcsavant.com');
        $inactive->update(['is_active' => false]);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->actingAs($initiator);

        $results = Volt::test('pages.tasks.show', ['task' => $task])
            ->instance()
            ->mentionSearch('');

        $this->assertNotEmpty($results, 'После @ должен сразу приходить список людей, без буквы.');
        $ids = array_column($results, 'id');
        $this->assertContains($assignee->id, $ids);
        $this->assertContains($initiator->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertSame(['id', 'name', 'email', 'token'], array_keys($results[0]));
    }

    public function test_plain_email_text_does_not_create_phantom_mentions(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $phantom = $this->createNamedUser($dept, $role, 'Phantom Domain', 'example.com@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            '<p>Reach me at user@example.com thanks</p>',
        );

        $task->refresh();
        $this->assertFalse($task->watchers->contains('id', $phantom->id));
        $this->assertFalse(
            $phantom->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
    }

    public function test_mailto_link_html_does_not_create_phantom_mentions(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $phantom = $this->createNamedUser($dept, $role, 'Phantom Domain', 'example.com@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $html = '<p>Email <a href="mailto:user@example.com">user@example.com</a> please</p>';

        $resolved = app(MentionService::class)->parseMentionedUsers($html);
        $this->assertTrue($resolved->isEmpty());

        app(TaskService::class)->addComment($task, $initiator, $html);

        $task->refresh();
        $this->assertFalse($task->watchers->contains('id', $phantom->id));
    }

    public function test_mention_adjacent_to_email_still_resolves(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, 'Alice', 'alice.mention@tcsavant.com');
        $phantom = $this->createNamedUser($dept, $role, 'Phantom Domain', 'example.com@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            '<p>Ping @Alice at user@example.com</p>',
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
        $this->assertFalse($task->watchers->contains('id', $phantom->id));
        $this->assertTrue(
            $mentioned->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.mentioned'),
        );
    }

    public function test_legitimate_at_user_mention_still_works_in_html(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, 'Bob', 'bob.mention@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment(
            $task,
            $initiator,
            '<p>Please review @Bob</p>',
        );

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
        $comment = $task->comments()->latest('id')->firstOrFail();
        $this->assertStringContainsString('@Bob', $comment->body);
        $this->assertTrue($comment->mentionedUsers->contains('id', $mentioned->id));
    }

    public function test_mention_on_its_own_tiptap_paragraph_still_resolves(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, 'Alice', 'alice.paragraph@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        // Compact TipTap markup: strip_tags alone would glue this to "Hi@Alice".
        $html = '<p>Hi</p><p>@Alice</p>';

        $resolved = app(MentionService::class)->parseMentionedUsers($html);
        $this->assertTrue($resolved->contains('id', $mentioned->id));

        app(TaskService::class)->addComment($task, $initiator, $html);

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
    }

    public function test_at_token_inside_code_or_pre_is_not_a_mention(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createNamedUser($dept, $role, 'Alice', 'alice.code@tcsavant.com');
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $html = '<p>Example:</p><pre><code>ping @Alice</code></pre><p>and really @Alice</p>';

        $resolved = app(MentionService::class)->parseMentionedUsers($html);
        $this->assertCount(1, $resolved);
        $this->assertTrue($resolved->contains('id', $mentioned->id));

        app(TaskService::class)->addComment($task, $initiator, $html);

        $task->refresh();
        $this->assertTrue($task->watchers->contains('id', $mentioned->id));
    }

    private function createNamedUser(Department $department, Role $role, string $name, string $email): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'department_id' => $department->id,
            'system_type' => SystemType::User,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
