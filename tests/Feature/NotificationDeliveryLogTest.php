<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskOverdueNotification;
use App\Services\ReminderSettings;
use App\Services\SettingsService;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class NotificationDeliveryLogTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_sent_notification_is_logged_with_user_task_event_and_channel(): void
    {
        [$user, $task] = $this->userWithTask();

        event(new NotificationSent($user, new TaskOverdueNotification($task), 'telegram', ['ok' => true]));

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'task_id' => $task->id,
            'event' => 'task.overdue',
            'channel' => 'telegram',
            'status' => NotificationDelivery::STATUS_SENT,
            'error' => null,
        ]);
    }

    public function test_telegram_send_with_no_recipient_is_not_logged_as_sent(): void
    {
        [$user, $task] = $this->userWithTask();

        event(new NotificationSent($user, new TaskOverdueNotification($task), 'telegram', null));

        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_database_channel_is_logged_even_without_a_response(): void
    {
        [$user, $task] = $this->userWithTask();

        event(new NotificationSent($user, new TaskOverdueNotification($task), 'database', null));

        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'database',
            'status' => NotificationDelivery::STATUS_SENT,
        ]);
    }

    public function test_failure_is_logged_without_the_message_text_and_with_the_bot_token_masked(): void
    {
        [$user, $task] = $this->userWithTask();

        event(new NotificationFailed($user, new TaskOverdueNotification($task), 'telegram', [
            'to' => '555',
            'request' => ['text' => 'SECRET MESSAGE BODY'],
            'exception' => new RuntimeException('POST https://api.telegram.org/bot123456:AAH-abc_DEF/sendMessage resulted in 403 Forbidden'),
        ]));

        $delivery = NotificationDelivery::query()->sole();

        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('403 Forbidden', $delivery->error);
        $this->assertStringContainsString('bot***', $delivery->error);
        $this->assertStringNotContainsString('AAH-abc_DEF', $delivery->error);
        $this->assertStringNotContainsString('SECRET MESSAGE BODY', $delivery->error);
    }

    public function test_real_database_notification_goes_through_the_log(): void
    {
        [$user, $task] = $this->userWithTask();

        $user->notifyNow(new TaskOverdueNotification($task), ['database']);

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'task_id' => $task->id,
            'event' => 'task.overdue',
            'channel' => 'database',
            'status' => NotificationDelivery::STATUS_SENT,
        ]);
    }

    public function test_admin_sees_the_send_log_and_regular_user_does_not(): void
    {
        [$user, $task] = $this->userWithTask();
        event(new NotificationSent($user, new TaskOverdueNotification($task), 'telegram', ['ok' => true]));

        $this->actingAs($user)->get('/admin/deliveries')->assertForbidden();

        $this->actingAs($this->admin())
            ->get('/admin/deliveries')
            ->assertOk()
            ->assertSee('data-ui="delivery-log"', false)
            ->assertSee('task.overdue')
            ->assertSee($user->name);
    }

    public function test_reminder_settings_fall_back_to_defaults(): void
    {
        $reminders = app(ReminderSettings::class);

        $this->assertSame([3, 1, 0], $reminders->thresholdDays());
        $this->assertSame('09:30', $reminders->morningTime());
        $this->assertSame('10:00', $reminders->digestTime());
        $this->assertSame('09:00', $reminders->workStart());
        $this->assertSame('19:00', $reminders->workEnd());
        $this->assertSame([1, 2, 3, 4, 5], $reminders->workDays());
        $this->assertSame(3, $reminders->escalationAfterDays());
        $this->assertSame(7, $reminders->escalationRepeatDays());
        $this->assertSame(5, $reminders->staleWorkDays());
        $this->assertSame(1, $reminders->mentionRepeatWorkDays());
        $this->assertSame(2, $reminders->mentionDigestWorkDays());
        $this->assertSame([], $reminders->digestRecipientIds());
        $this->assertSame([], $reminders->excludedUserIds());
        $this->assertSame([], $reminders->noEscalationHeadIds());
    }

    public function test_reminder_settings_ignore_broken_stored_values(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('reminder_morning_time', '25:99');
        $settings->set('reminder_work_days', [0, 9]);
        $settings->set('reminder_escalation_after_days', 0);
        $settings->set('reminder_digest_recipient_ids', 'nobody');

        $reminders = app(ReminderSettings::class);

        $this->assertSame('09:30', $reminders->morningTime());
        $this->assertSame([1, 2, 3, 4, 5], $reminders->workDays());
        $this->assertSame(3, $reminders->escalationAfterDays());
        $this->assertSame([], $reminders->digestRecipientIds());
    }

    public function test_admin_saves_reminder_settings_separately_from_system_settings(): void
    {
        [$user] = $this->userWithTask();
        $this->actingAs($this->admin());

        Volt::test('pages.admin.settings')
            ->set('allowedEmailDomains', '')
            ->set('reminderThresholdDays', '1, 5, 0')
            ->set('reminderMorningTime', '09:15')
            ->set('reminderWorkDays', ['1', '2', '3', '4', '5', '6'])
            ->set('reminderDigestRecipientIds', [(string) $user->id])
            ->call('saveReminders')
            ->assertHasNoErrors();

        $reminders = app(ReminderSettings::class);

        $this->assertSame([5, 1, 0], $reminders->thresholdDays());
        $this->assertSame('09:15', $reminders->morningTime());
        $this->assertSame([1, 2, 3, 4, 5, 6], $reminders->workDays());
        $this->assertSame([$user->id], $reminders->digestRecipientIds());
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
        $this->assertDatabaseMissing('settings', ['key' => 'allowed_email_domains']);
    }

    public function test_reminder_settings_reject_time_outside_working_hours_and_empty_work_days(): void
    {
        $this->actingAs($this->admin());

        Volt::test('pages.admin.settings')
            ->set('reminderMorningTime', '07:00')
            ->call('saveReminders')
            ->assertHasErrors('reminderMorningTime');

        Volt::test('pages.admin.settings')
            ->set('reminderWorkDays', [])
            ->call('saveReminders')
            ->assertHasErrors('reminderWorkDays');

        Volt::test('pages.admin.settings')
            ->set('reminderThresholdDays', 'three')
            ->call('saveReminders')
            ->assertHasErrors('reminderThresholdDays');

        $this->assertDatabaseMissing('settings', ['key' => 'reminder_morning_time']);
    }

    /** @return array{0: User, 1: Task} */
    private function userWithTask(): array
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Log Recipient');
        $task = $this->createTask($user, $user, $this->createCategory(), ['title' => 'Logged task']);

        return [$user, $task];
    }

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'delivery-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
