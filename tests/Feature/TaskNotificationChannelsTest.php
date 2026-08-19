<?php

namespace Tests\Feature;

use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentedNotification;
use App\Services\TaskNotificationService;
use App\Services\TaskService;
use App\Services\TelegramLinkService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskNotificationChannelsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_resolve_channels_respects_preferences_and_chat_id(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Channel User', role: $role);
        $user->update(['telegram_chat_id' => '12345']);

        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'database',
            'enabled' => false,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'email',
            'enabled' => true,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'telegram',
            'enabled' => true,
        ]);

        $channels = app(TaskNotificationService::class)
            ->resolveChannels($user->fresh(), 'task.commented');

        $this->assertSame(['mail'], $channels);
    }

    public function test_telegram_channel_included_when_dm_enabled(): void
    {
        config(['services.telegram.dm_enabled' => true]);

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Dm User', role: $role);
        $user->update(['telegram_chat_id' => '12345']);

        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'database',
            'enabled' => false,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'email',
            'enabled' => true,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.commented',
            'channel' => 'telegram',
            'enabled' => true,
        ]);

        $channels = app(TaskNotificationService::class)
            ->resolveChannels($user->fresh(), 'task.commented');

        $this->assertSame(['mail', 'telegram'], $channels);
    }

    public function test_telegram_channel_skipped_without_chat_id(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'No Chat', role: $role);

        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.assigned',
            'channel' => 'database',
            'enabled' => false,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.assigned',
            'channel' => 'email',
            'enabled' => false,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'task.assigned',
            'channel' => 'telegram',
            'enabled' => true,
        ]);

        $this->assertSame(
            [],
            app(TaskNotificationService::class)->resolveChannels($user->fresh(), 'task.assigned'),
        );
    }

    public function test_email_only_preference_still_notifies(): void
    {
        Notification::fake();

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
        UserNotificationPreference::query()->create([
            'user_id' => $assignee->id,
            'event' => 'task.commented',
            'channel' => 'email',
            'enabled' => true,
        ]);
        UserNotificationPreference::query()->create([
            'user_id' => $assignee->id,
            'event' => 'task.commented',
            'channel' => 'telegram',
            'enabled' => false,
        ]);

        app(TaskService::class)->addComment($task, $initiator, 'Email only please');

        Notification::assertSentTo(
            $assignee,
            TaskCommentedNotification::class,
            function (TaskCommentedNotification $notification, array $channels) use ($assignee) {
                return $channels === ['mail']
                    && $notification->via($assignee) === ['mail'];
            },
        );

        $this->assertCount(0, $assignee->fresh()->notifications);
    }

    public function test_all_channels_disabled_skips_notification(): void
    {
        Notification::fake();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        foreach (['database', 'email', 'telegram'] as $channel) {
            UserNotificationPreference::query()->create([
                'user_id' => $assignee->id,
                'event' => 'task.commented',
                'channel' => $channel,
                'enabled' => false,
            ]);
        }

        app(TaskService::class)->addComment($task, $initiator, 'Silence');

        Notification::assertNotSentTo($assignee, TaskCommentedNotification::class);
    }

    public function test_assigned_notification_includes_mail_by_default(): void
    {
        Notification::fake();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $category->id,
                'title' => 'Mail channels',
                'description' => 'desc',
                'priority' => 5,
            ],
        );

        Notification::assertSentTo(
            $assignee,
            TaskAssignedNotification::class,
            function (TaskAssignedNotification $notification, array $channels) use ($assignee) {
                $via = $notification->via($assignee);

                return in_array('database', $via, true)
                    && in_array('mail', $via, true)
                    && ! in_array('telegram', $via, true);
            },
        );
    }

    public function test_telegram_link_code_and_webhook_binds_chat_id(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.bot_username' => 'tasktracker_bot',
            'services.telegram.webhook_secret' => 'secret-token',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Telegram User', role: $role);

        $code = app(TelegramLinkService::class)->createLinkCode($user);

        $this->assertNotNull(app(TelegramLinkService::class)->deepLinkUrl($code->code));

        $this->postJson('/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 987654321],
                'from' => ['id' => 987654321, 'username' => 'herald_user'],
                'text' => '/start '.$code->code,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret-token',
        ])->assertOk();

        $this->assertSame('987654321', $user->fresh()->telegram_chat_id);
        $this->assertSame('herald_user', $user->fresh()->telegram_username);
        $this->assertDatabaseMissing('telegram_link_codes', ['code' => $code->code]);
    }

    public function test_telegram_webhook_rejects_invalid_secret(): void
    {
        config(['services.telegram.webhook_secret' => 'expected']);

        $this->postJson('/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 1],
                'text' => '/start abc',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertForbidden();
    }

    public function test_telegram_webhook_without_code_does_not_bind(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.webhook_secret' => null,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postJson('/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 111],
                'text' => '/start',
            ],
        ])->assertOk();

        $this->assertSame(0, User::query()->where('telegram_chat_id', '111')->count());
    }

    public function test_expired_link_code_is_rejected(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.webhook_secret' => null,
        ]);

        Http::fake();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Expired Link', role: $role);

        $code = TelegramLinkCode::query()->create([
            'user_id' => $user->id,
            'code' => 'expiredcode123',
            'expires_at' => now()->subMinute(),
        ]);

        $result = app(TelegramLinkService::class)
            ->consumeStartPayload('555', $code->code);

        $this->assertFalse($result['ok']);
        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_profile_telegram_link_component_generates_code(): void
    {
        config(['services.telegram.bot_username' => 'tasktracker_bot']);

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Profile TG', role: $role);

        $this->actingAs($user);

        Volt::test('profile.telegram-link')
            ->call('generateLink')
            ->assertSet('linkCode', fn ($value) => filled($value))
            ->assertSet('deepLinkUrl', fn ($value) => str_contains((string) $value, 't.me/tasktracker_bot?start='));

        $this->assertDatabaseHas('telegram_link_codes', [
            'user_id' => $user->id,
        ]);
    }

    public function test_profile_page_contains_telegram_link(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Profile Page', role: $role);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeVolt('profile.telegram-link')
            ->assertSee(__('notification.telegram_link_title'));
    }

    public function test_unlink_clears_chat_id(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $user = $this->createUserInDepartment($dept, 'Unlink User', role: $role);
        $user->update(['telegram_chat_id' => '999', 'telegram_username' => 'unlink_me']);

        $this->actingAs($user);

        Volt::test('profile.telegram-link')
            ->call('unlink')
            ->assertSet('linked', false);

        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertNull($user->fresh()->telegram_username);
    }
}
