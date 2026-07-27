<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
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
        $this->assertSame('Татьяна', $results[0]['token']);
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
