<?php

namespace Tests\Feature;

use App\Models\UserNotificationPreference;
use App\Services\TaskService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_profile_page_contains_notification_preferences(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Profile User', role: $role);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.notification-preferences')
            ->assertSee(__('notification.preferences_title'));
    }

    public function test_disabling_database_preference_creates_db_row(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Prefs User', role: $role);

        $this->actingAs($user);

        Volt::test('profile.notification-preferences')
            ->set('preferences.task_commented.database', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notification-preferences-saved');

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'database',
            'enabled' => false,
        ]);
    }

    public function test_disabled_comment_preference_skips_notification(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $this->actingAs($assignee);

        Volt::test('profile.notification-preferences')
            ->set('preferences.task_commented.database', false)
            ->call('save')
            ->assertHasNoErrors();

        app(TaskService::class)->addComment($task, $initiator, 'Should not notify assignee');

        $this->assertCount(0, $assignee->fresh()->notifications);
    }

    public function test_re_enabling_comment_preference_restores_notifications(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        UserNotificationPreference::query()->create([
            'user_id' => $assignee->id,
            'event' => 'task.commented',
            'channel' => 'database',
            'enabled' => false,
        ]);

        $this->actingAs($assignee);

        Volt::test('profile.notification-preferences')
            ->set('preferences.task_commented.database', true)
            ->call('save')
            ->assertHasNoErrors();

        app(TaskService::class)->addComment($task, $initiator, 'Notify again please');

        $this->assertTrue(
            $assignee->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'task.commented'),
        );
    }
}
