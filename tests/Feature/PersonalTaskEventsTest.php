<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Services\SettingsService;
use App\Services\TaskNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * "A task is yours" and "the status changed" in the personal Telegram chat (task 448):
 * they replace the group chat once the reminder bot is on. 2026-11-10 is a Tuesday.
 */
class PersonalTaskEventsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private User $initiator;

    private User $assignee;

    private User $watcher;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:31', 'Europe/Kyiv'));
        config(['services.telegram.dm_enabled' => false, 'services.telegram.group_enabled' => false]);
        app(SettingsService::class)->set('reminder_enabled', true);

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        [$this->initiator, $this->assignee, $this->watcher] = array_map(function (string $name) use ($dept, $role) {
            $user = $this->createUserInDepartment($dept, $name, role: $role);
            $user->update(['telegram_chat_id' => (string) (900000 + $user->id)]);

            return $user->fresh();
        }, ['Natalia', 'Ivan', 'Olena']);

        $this->task = $this->createTask($this->initiator, $this->assignee, $this->createCategory(), [
            'title' => 'Calls report', 'description' => 'Собрать <b>звонки</b> за неделю', 'status' => TaskStatus::New, 'deadline' => '2026-11-20 23:59:00',
        ]);
        $this->task->watchers()->attach($this->watcher->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_both_events_go_to_telegram_without_the_old_all_events_switch(): void
    {
        $service = app(TaskNotificationService::class);

        $this->assertContains('telegram', $service->resolveChannels($this->assignee, 'task.assigned'));
        $this->assertContains('telegram', $service->resolveChannels($this->assignee, 'task.status_changed'));
        // Reactions stay in the tracker only.
        $this->assertNotContains('telegram', $service->resolveChannels($this->assignee, 'task.comment_reacted'));
    }

    public function test_nothing_goes_while_reminders_are_off_outside_the_pilot_without_telegram_or_when_switched_off(): void
    {
        $service = app(TaskNotificationService::class);
        $settings = app(SettingsService::class);

        $settings->set('reminder_enabled', false);
        $this->assertNotContains('telegram', $service->resolveChannels($this->assignee, 'task.assigned'));
        $settings->set('reminder_enabled', true);

        $settings->set('reminder_pilot_user_ids', [$this->initiator->id]);
        $this->assertNotContains('telegram', $service->resolveChannels($this->assignee, 'task.assigned'));
        $settings->set('reminder_pilot_user_ids', []);

        UserNotificationPreference::query()->create([
            'user_id' => $this->assignee->id, 'event' => 'task.assigned', 'channel' => 'telegram', 'enabled' => false,
        ]);
        $this->assertNotContains('telegram', $service->resolveChannels($this->assignee, 'task.assigned'));
        $this->assertContains('telegram', $service->resolveChannels($this->assignee, 'task.status_changed'));

        $this->watcher->update(['telegram_chat_id' => null]);
        $this->assertNotContains('telegram', $service->resolveChannels($this->watcher->fresh(), 'task.status_changed'));
    }

    public function test_assignee_gets_the_task_card_with_buttons_and_a_watcher_is_told_who_got_it(): void
    {
        $message = (new TaskAssignedNotification($this->task, $this->initiator))->toTelegram($this->assignee)->toArray();

        $this->assertStringStartsWith("🆕 <b>Вам назначена задача</b>\n<a href=", $message['text']);
        $this->assertStringContainsString('🎯 Исполнитель — вы', $message['text']);
        $this->assertStringContainsString('👤 Кто назначил: Natalia', $message['text']);
        $this->assertStringContainsString('📝 Собрать звонки за неделю', $message['text']);
        $this->assertSame('HTML', $message['parse_mode']);
        $buttons = array_map(fn (array $row) => array_column($row, 'text'), json_decode($message['reply_markup'], true)['inline_keyboard']);
        $this->assertSame('primary', json_decode($message['reply_markup'], true)['inline_keyboard'][0][0]['style']);
        $this->assertContains('📋 Скопировать номер', end($buttons));

        $toWatcher = (new TaskAssignedNotification($this->task, $this->initiator))->toTelegram($this->watcher)->toArray();
        $this->assertStringStartsWith('🆕 <b>У задачи назначен исполнитель</b>', $toWatcher['text']);
        $this->assertStringContainsString('👀 Вы наблюдатель', $toWatcher['text']);
        $this->assertStringContainsString('🎯 Исполнитель: Ivan', $toWatcher['text']);
    }

    public function test_status_change_shows_both_statuses_who_changed_it_and_the_reason(): void
    {
        $this->task->update(['status' => TaskStatus::Rework]);

        $message = (new TaskStatusChangedNotification(
            $this->task->fresh(), $this->initiator, TaskStatus::OnReview, TaskStatus::Rework, 'Нет <итогов> по менеджерам',
        ))->toTelegram($this->assignee)->toArray();

        $this->assertStringStartsWith("🔄 <b>Статус изменён:</b> «На проверке» → <b>«На доработку»</b>\n<a href=", $message['text']);
        $this->assertStringContainsString('👤 Кто изменил: Natalia', $message['text']);
        $this->assertStringContainsString('<blockquote>Нет &lt;итогов&gt; по менеджерам</blockquote>', $message['text']);
        $this->assertNotEmpty(json_decode($message['reply_markup'], true)['inline_keyboard']);
    }

    public function test_at_night_telegram_waits_for_the_morning_and_by_day_goes_at_once(): void
    {
        Notification::fake();
        $service = app(TaskNotificationService::class);

        $service->notifyStatusChanged($this->task, $this->initiator, TaskStatus::New, TaskStatus::InProgress);
        Notification::assertSentTo($this->assignee, TaskStatusChangedNotification::class, fn ($n) => $n->delay === null);

        Carbon::setTestNow(Carbon::parse('2026-11-10 22:15', 'Europe/Kyiv'));
        $service->notifyTaskReassigned($this->task, $this->initiator);
        Notification::assertSentTo($this->assignee, TaskAssignedNotification::class, fn ($n) => is_array($n->delay)
            && array_keys($n->delay) === ['telegram']
            && $n->delay['telegram']->timezone('Europe/Kyiv')->format('Y-m-d') === '2026-11-11');
        // The author of the change is told nothing.
        Notification::assertNotSentTo($this->initiator, TaskAssignedNotification::class);
    }
}
