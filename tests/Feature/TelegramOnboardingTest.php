<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\MentionService;
use App\Services\SettingsService;
use App\Services\TaskReminderService;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TelegramOnboardingTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_username' => 'tasktracker_bot']);
    }

    public function test_banner_is_shown_only_to_people_who_should_connect_telegram(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $unlinked = $this->createUserInDepartment($dept, 'Unlinked', role: $role);
        $linked = $this->createUserInDepartment($dept, 'Linked', role: $role);
        $linked->update(['telegram_chat_id' => '111']);
        $service = $this->createUserInDepartment($dept, 'Service', role: $role);
        app(SettingsService::class)->set('reminder_excluded_user_ids', [$service->id]);

        // While reminders are off nothing would arrive anyway: no banner.
        $this->actingAs($unlinked)->get('/tasks')->assertOk()->assertDontSee('data-ui="telegram-banner"', false);
        app(SettingsService::class)->set('reminder_enabled', true);

        $this->actingAs($unlinked)->get('/tasks')
            ->assertOk()
            ->assertSee('data-ui="telegram-banner"', false)
            ->assertSee(__('Telegram is not connected.'))
            ->assertSee(route('profile'), false);

        // Not on the profile itself: that is where the person connects it.
        $this->actingAs($unlinked)->get('/profile')->assertOk()->assertDontSee('data-ui="telegram-banner"', false);
        $this->actingAs($linked)->get('/tasks')->assertOk()->assertDontSee('data-ui="telegram-banner"', false);
        $this->actingAs($service)->get('/tasks')->assertOk()->assertDontSee('data-ui="telegram-banner"', false);

        // During a pilot only the pilot people are asked to connect.
        app(SettingsService::class)->set('reminder_pilot_user_ids', [$linked->id]);
        $this->actingAs($unlinked)->get('/tasks')->assertOk()->assertDontSee('data-ui="telegram-banner"', false);
        app(SettingsService::class)->set('reminder_pilot_user_ids', [$unlinked->id]);
        $this->actingAs($unlinked)->get('/tasks')->assertOk()->assertSee('data-ui="telegram-banner"', false);
        app(SettingsService::class)->set('reminder_pilot_user_ids', []);

        // No bot configured: nothing to connect to.
        config(['services.telegram.bot_username' => null]);
        $this->actingAs($unlinked)->get('/tasks')->assertOk()->assertDontSee('data-ui="telegram-banner"', false);
    }

    public function test_admin_sees_who_is_connected_and_who_switched_what_off(): void
    {
        $dept = $this->createDepartment('Sales');
        $admin = $this->admin();
        $linked = $this->createUserInDepartment($dept, 'Linked Person');
        $linked->update(['telegram_chat_id' => '111', 'telegram_username' => 'linked_person']);
        $unlinked = $this->createUserInDepartment($dept, 'Unlinked Person');
        $inactive = $this->createUserInDepartment($dept, 'Gone Person');
        $inactive->update(['is_active' => false]);
        $service = $this->createUserInDepartment($dept, 'Service Account');
        app(SettingsService::class)->set('reminder_excluded_user_ids', [$service->id]);
        UserNotificationPreference::query()->create(['user_id' => $linked->id, 'event' => 'task.reminder', 'channel' => 'telegram', 'enabled' => false]);

        $this->actingAs($unlinked)->get('/admin/telegram')->assertForbidden();

        $this->actingAs($admin);
        $page = Volt::test('pages.admin.telegram')
            ->assertSee('Linked Person')
            ->assertSee('@linked_person')
            ->assertSee('Unlinked Person')
            ->assertSee('Service Account')
            ->assertSee(__('excluded from reminders'))
            ->assertDontSee('Gone Person')
            ->assertSee(__('notification.events.task_reminder'))
            ->assertSee(__('Morning reminders are switched off in the system settings: nobody gets them yet.'))
            // The admin has no Telegram either; the service account is not counted.
            ->assertViewHas('total', 3)
            ->assertViewHas('linked', 1)
            ->assertViewHas('notLinked', 2)
            ->assertViewHas('switchedOff', 1);

        $page->set('show', 'not_linked')
            ->assertSee('Unlinked Person')
            ->assertDontSee('Linked Person')
            ->assertDontSee('Service Account');

        $page->set('show', 'switched_off')
            ->assertSee('Linked Person')
            ->assertDontSee('Unlinked Person');
    }

    public function test_admin_switches_a_persons_telegram_notifications_back_on(): void
    {
        $dept = $this->createDepartment();
        $admin = $this->admin();
        $person = $this->createUserInDepartment($dept, 'Quiet Person');
        $person->update(['telegram_chat_id' => '222']);

        foreach (['task.reminder' => 'telegram', 'task.mentioned' => 'telegram', 'task.commented' => 'email', 'task.assigned' => 'telegram'] as $event => $channel) {
            UserNotificationPreference::query()->create(['user_id' => $person->id, 'event' => $event, 'channel' => $channel, 'enabled' => false]);
        }
        $this->assertFalse(app(TaskReminderService::class)->telegramAllowed($person));

        $this->actingAs($admin);
        Volt::test('pages.admin.telegram')->call('enableAll', $person->id)->assertHasNoErrors();

        $this->assertTrue(app(TaskReminderService::class)->telegramAllowed($person->fresh()));
        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $person->id, 'event' => 'task.mentioned', 'channel' => 'telegram', 'enabled' => true]);
        // Only the personal Telegram messages of the reminders are touched: e-mail and other events stay as the person set them.
        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $person->id, 'event' => 'task.commented', 'channel' => 'email', 'enabled' => false]);
        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $person->id, 'event' => 'task.assigned', 'channel' => 'telegram', 'enabled' => false]);

        $audit = AuditLog::query()->where('action', 'notifications.enabled_by_admin')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertEqualsCanonicalizing(['task.reminder', 'task.mentioned'], $audit->old_values['telegram_off']);

        // Nothing to switch on: no second audit entry.
        Volt::test('pages.admin.telegram')->call('enableAll', $person->id);
        $this->assertSame(1, AuditLog::query()->where('action', 'notifications.enabled_by_admin')->count());
    }

    public function test_a_regular_user_cannot_switch_notifications_on_for_others(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Regular');

        $this->actingAs($user)->get('/admin/telegram')->assertForbidden();
    }

    public function test_handwritten_mentions_that_match_nobody_are_reported(): void
    {
        $dept = $this->createDepartment();
        $this->createUserInDepartment($dept, 'Ivan Petrenko');
        $mentions = app(MentionService::class);

        $this->assertSame(['Petrovich', 'all'], $mentions->unresolvedMentions('<p>@IvanPetrenko и @Petrovich, а также @all</p>'));
        // A chip picked from the list, an e-mail address and code are not hand-written mentions.
        $this->assertSame([], $mentions->unresolvedMentions(
            '<p><span data-type="mention" data-id="1" data-label="Кто-то">@Кто-то</span> пишите на info@tcsavant.com <code>@decorator</code></p>'
        ));
        $this->assertSame([], $mentions->unresolvedMentions('<p>Без упоминаний</p>'));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'onboarding-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
