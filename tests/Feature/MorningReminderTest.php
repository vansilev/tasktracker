<?php

namespace Tests\Feature;

use App\Console\Commands\SendMorningReminders;
use App\Enums\ContentFormat;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\TaskEscalationNotification;
use App\Notifications\TaskMorningReminderNotification;
use App\Services\SettingsService;
use App\Services\TaskNotificationService;
use App\Services\TaskReminderService;
use Carbon\Carbon;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * Calendar used below: 2026-11-09 Mon · 10 Tue · 11 Wed · 12 Thu · 13 Fri · 14 Sat · 15 Sun · 16 Mon.
 */
class MorningReminderTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private Department $dept;

    private Role $role;

    private User $initiator;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app(SettingsService::class)->set('reminder_enabled', true);

        $this->dept = $this->createDepartment();
        $this->role = $this->createRoleWithPermissions($this->defaultPermissions(), [$this->dept->id]);
        $this->initiator = $this->person('Initiator');
        $this->assignee = $this->person('Assignee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_task_gets_each_threshold_reminder_exactly_once(): void
    {
        $task = $this->task('2026-11-13');

        $this->morning('2026-11-09');
        Notification::assertNothingSent();

        $sent = [];
        foreach (['2026-11-10' => 3, '2026-11-11' => null, '2026-11-12' => 1, '2026-11-13' => 0] as $day => $expectedDays) {
            $this->morning($day);
            $this->morning($day); // a second run the same morning adds nothing

            $rows = $this->rowsFor($this->assignee, $day);
            $sent[$day] = array_column($rows, 'days');

            $this->assertSame($expectedDays === null ? [] : [$expectedDays], $sent[$day], "morning of {$day}");
        }

        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 3);
        Notification::assertNotSentTo($this->initiator, TaskMorningReminderNotification::class);
        $this->assertSame(3, TaskReminder::query()->where('task_id', $task->id)->where('kind', 'threshold')->count());
    }

    public function test_task_on_review_reminds_the_initiator_not_the_assignee(): void
    {
        $this->task('2026-11-11', ['status' => TaskStatus::OnReview]);
        $this->task('2026-11-11', ['status' => TaskStatus::AwaitingInitiator, 'title' => 'Waiting']);
        $this->task('2026-11-11', ['status' => TaskStatus::Rework, 'title' => 'Rework']);

        $this->morning('2026-11-11');

        $this->assertCount(2, $this->rowsFor($this->initiator, '2026-11-11'));
        $this->assertSame(['Rework'], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'title'));
    }

    public function test_person_who_is_both_initiator_and_assignee_gets_one_line(): void
    {
        $this->task('2026-11-11', ['initiator_id' => $this->assignee->id]);

        $this->morning('2026-11-11');

        $this->assertCount(1, $this->rowsFor($this->assignee, '2026-11-11'));
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 1);
    }

    public function test_monday_deadline_is_announced_on_friday_once_and_again_on_monday(): void
    {
        $this->task('2026-11-16');

        $this->morning('2026-11-12');
        $this->assertSame([], $this->rowsFor($this->assignee, '2026-11-12'));

        // Friday carries both "3 days" and "1 day" (Sunday moved back) as a single line.
        $this->morning('2026-11-13');
        $this->assertSame([3], array_column($this->rowsFor($this->assignee, '2026-11-13'), 'days'));

        $this->morning('2026-11-16');
        $this->assertSame([0], array_column($this->rowsFor($this->assignee, '2026-11-16'), 'days'));

        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 2);
    }

    public function test_saturday_deadline_gets_its_last_call_on_friday_and_overdue_on_monday(): void
    {
        $this->task('2026-11-14');

        $this->morning('2026-11-11');
        $this->morning('2026-11-13');
        $this->morning('2026-11-16');

        $this->assertSame([3], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'days'));
        $this->assertSame([1], array_column($this->rowsFor($this->assignee, '2026-11-13'), 'days'));
        $this->assertSame([-2], array_column($this->rowsFor($this->assignee, '2026-11-16'), 'days'));
    }

    public function test_weekend_and_night_send_nothing(): void
    {
        $this->task('2026-11-10'); // overdue all week

        foreach (['2026-11-14 10:00', '2026-11-15 10:00', '2026-11-11 03:00', '2026-11-11 09:29', '2026-11-11 19:00', '2026-11-11 23:30'] as $moment) {
            Carbon::setTestNow(Carbon::parse($moment, 'Europe/Kyiv'));
            $this->artisan('tasks:send-morning-reminders')->assertSuccessful();
        }

        Notification::assertNothingSent();
        $this->assertSame(0, TaskReminder::query()->count());
    }

    public function test_overdue_task_is_repeated_every_working_morning_until_closed(): void
    {
        $task = $this->task('2026-11-10');

        foreach (['2026-11-11', '2026-11-12', '2026-11-13'] as $day) {
            $this->morning($day);
            $this->morning($day);
        }

        $this->assertSame([-1], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'days'));
        $this->assertSame([-3], array_column($this->rowsFor($this->assignee, '2026-11-13'), 'days'));
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 3);

        $task->update(['status' => TaskStatus::Completed]);
        $this->morning('2026-11-16');
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 3);
    }

    public function test_moved_deadline_counts_thresholds_again(): void
    {
        $task = $this->task('2026-11-12');

        $this->morning('2026-11-11'); // "tomorrow"
        $task->update(['deadline' => '2026-11-13 23:59:00']);
        $this->morning('2026-11-12'); // "tomorrow" again, for the new date

        $this->assertSame([1], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'days'));
        $this->assertSame([1], array_column($this->rowsFor($this->assignee, '2026-11-12'), 'days'));
    }

    public function test_tasks_without_deadline_closed_or_postponed_are_silent_until_the_return_date(): void
    {
        $this->task(null, ['title' => 'No deadline']);
        $this->task('2026-11-11', ['status' => TaskStatus::Completed, 'title' => 'Done']);
        $this->task('2026-11-11', ['status' => TaskStatus::Cancelled, 'title' => 'Cancelled']);
        $this->task('2026-11-05', ['status' => TaskStatus::Postponed, 'title' => 'Legacy postponed']);
        $sleeping = $this->task('2026-11-05', ['status' => TaskStatus::Postponed, 'title' => 'Sleeping', 'postponed_until' => '2026-11-12']);

        $this->morning('2026-11-11');
        Notification::assertNothingSent();

        $this->morning('2026-11-12');
        $this->assertSame(['Sleeping'], array_column($this->rowsFor($this->assignee, '2026-11-12'), 'title'));
        $this->assertSame($sleeping->id, $this->rowsFor($this->assignee, '2026-11-12')[0]['task_id']);
    }

    public function test_watcher_gets_the_line_marked_as_watcher(): void
    {
        $watcher = $this->person('Watcher');
        $task = $this->task('2026-11-11');
        $task->watchers()->attach([$watcher->id, $this->assignee->id]);

        $this->morning('2026-11-11');

        $this->assertSame([true], array_column($this->rowsFor($watcher, '2026-11-11'), 'watcher'));
        $this->assertSame([false], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'watcher'));

        $text = $this->lastReminder($watcher)->text('ru');
        $this->assertStringContainsString("👀 Вы наблюдатель — делать ничего не нужно\n🎯 Ответственный: Assignee · сейчас «В работе»", $text);
        $this->assertStringNotContainsString('👉', $text);
        $this->assertStringContainsString('🎯 Исполнитель — вы · сейчас «В работе»', $this->lastReminder($this->assignee)->text('ru'));
        $this->assertStringNotContainsString('наблюдател', $this->lastReminder($this->assignee)->text('ru'));
    }

    public function test_watcher_hears_about_thresholds_but_not_the_daily_overdue(): void
    {
        $watcher = $this->person('Watcher');
        $this->task('2026-11-11')->watchers()->attach($watcher->id);

        $this->morning('2026-11-11'); // deadline day: both are told
        $this->morning('2026-11-12'); // overdue: only the assignee
        $this->morning('2026-11-13');

        Notification::assertSentToTimes($watcher, TaskMorningReminderNotification::class, 1);
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 3);
        $this->assertSame(0, TaskReminder::query()->where('user_id', $watcher->id)->where('kind', 'overdue')->count());
    }

    public function test_excluded_service_and_inactive_people_get_nothing(): void
    {
        $excluded = $this->person('Excluded');
        $inactive = $this->person('Inactive');
        $inactive->update(['is_active' => false]);
        app(SettingsService::class)->set('reminder_excluded_user_ids', [$excluded->id]);

        $this->task('2026-11-11', ['assignee_id' => $excluded->id, 'title' => 'Of excluded'])->watchers()->attach($this->assignee->id);
        $this->task('2026-11-11', ['assignee_id' => $inactive->id, 'title' => 'Of inactive']);
        $this->task('2026-11-11', ['title' => 'Normal'])->watchers()->attach([$excluded->id, $inactive->id]);

        $this->morning('2026-11-11');

        Notification::assertNotSentTo([$excluded, $inactive], TaskMorningReminderNotification::class);
        // A task hanging on an excluded account is skipped as a whole, watchers included.
        $this->assertSame(['Normal'], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'title'));
    }

    public function test_person_without_telegram_is_not_claimed_and_catches_up_after_linking(): void
    {
        $this->assignee->update(['telegram_chat_id' => null]);
        $this->task('2026-11-13');

        $this->morning('2026-11-10');
        Notification::assertNothingSent();
        $this->assertSame(0, TaskReminder::query()->count());

        $this->assignee->update(['telegram_chat_id' => '777']);
        $this->morning('2026-11-11');

        $this->assertSame([2], array_column($this->rowsFor($this->assignee, '2026-11-11'), 'days'));
    }

    public function test_person_can_switch_the_morning_reminder_off(): void
    {
        UserNotificationPreference::query()->create([
            'user_id' => $this->assignee->id, 'event' => 'task.reminder', 'channel' => 'telegram', 'enabled' => false,
        ]);
        $this->task('2026-11-11');

        $this->morning('2026-11-11');

        Notification::assertNothingSent();
    }

    public function test_message_is_valid_html_and_long_lists_are_cut_on_line_borders(): void
    {
        $this->task('2026-11-11', ['title' => 'Fix <b>bold</b> & "quotes" _under_ *star* [x](y)']);

        $this->morning('2026-11-11');
        $text = $this->lastReminder($this->assignee)->text('ru');

        // One task: urgency first, the task on its own line, then what to do.
        $this->assertStringStartsWith("⏰ <b>Срок сегодня</b> · ср, 11.11\n<a href=", $text);
        $this->assertStringContainsString('«Fix &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quotes&quot; _under_ *star* [x](y)»</b></a>', $text);
        $this->assertStringContainsString("👉 <b>Что нужно:</b> завершить и сдать на проверку\n🎯 Исполнитель — вы · сейчас «В работе»", $text);
        $this->assertStringNotContainsString('Приоритет', $text);
        $this->assertStringEndsWith('">Открыть задачу</a>', $text);
        $this->assertBalancedHtml($text);

        // Several tasks: a summary, then one collapsible group per urgency, watched tasks last.
        $this->task('2026-11-10', ['title' => 'Late one', 'priority' => 9]);
        $this->task('2026-11-06', ['title' => 'Very late', 'status' => TaskStatus::New]);
        $this->task('2026-11-12', ['title' => 'For review', 'status' => TaskStatus::OnReview, 'initiator_id' => $this->assignee->id]);
        $this->task('2026-11-12', ['title' => 'Watched', 'assignee_id' => $this->initiator->id])->watchers()->attach($this->assignee->id);
        Notification::fake();
        TaskReminder::query()->delete();
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:30', 'Europe/Kyiv'));
        app(TaskReminderService::class)->run();
        $list = $this->lastReminder($this->assignee)->text('ru');

        $this->assertStringStartsWith("📋 <b>Ваши сроки на ср, 11.11</b>\n🔴 2 просрочено · ⏰ 1 сегодня · 🟡 1 завтра\n\n<blockquote expandable>🔴 <b>ПРОСРОЧЕНО · 2</b>", $list);
        $order = array_map(fn (string $needle) => strpos($list, $needle), [
            '🔴 <b>ПРОСРОЧЕНО · 2</b>', '⏰ <b>СЕГОДНЯ · 1</b>', '🟡 <b>ЗАВТРА · 1</b> · чт, 12.11', '👀 <b>ВЫ НАБЛЮДАТЕЛЬ · 1</b>', 'Все мои задачи',
        ]);
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertStringContainsString("«Very late»</b></a>\n<b>5 дней</b> · срок был 06.11\n👉 взять в работу — задача ещё «Новая»", $list);
        $this->assertStringContainsString("«Late one»</b></a>\n<b>1 день</b> · срок был 10.11 · 🔥 приоритет 9/10\n👉 завершить и сдать на проверку", $list);
        $this->assertStringContainsString("«For review»</b></a>\n👉 проверить работу — принять или вернуть на доработку", $list);
        $this->assertStringContainsString('Делать ничего не нужно, для информации.', $list);
        $this->assertMatchesRegularExpression('/#\d+ «Watched»<\/a>\nсрок 12\.11 · отв\. Initiator · «В работе»<\/blockquote>/u', $list);
        $this->assertSame(4, substr_count($list, '<blockquote expandable>'));
        $this->assertBalancedHtml($list);
        TaskReminder::query()->delete();
        Task::query()->whereIn('title', ['Late one', 'Very late', 'For review', 'Watched'])->update(['status' => TaskStatus::Completed]);

        for ($i = 0; $i < 60; $i++) {
            $this->task('2026-11-12', ['title' => str_repeat('Очень длинное название задачи ', 8).$i]);
        }

        Notification::fake();
        $this->morning('2026-11-12');
        $parts = Notification::sent($this->assignee, TaskMorningReminderNotification::class);

        $this->assertGreaterThan(1, $parts->count());
        $this->assertSame(61, $parts->sum(fn (TaskMorningReminderNotification $n) => count($n->rows)));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(4096, mb_strlen($part->text('ru')));
            $this->assertBalancedHtml($part->text('ru'));
        }
    }

    public function test_escalation_goes_to_the_head_after_three_days_and_repeats_weekly(): void
    {
        $head = $this->person('Head');
        $this->dept->update(['head_user_id' => $head->id]);
        $task = $this->task('2026-11-06'); // Friday

        $this->morning('2026-11-09'); // 3 calendar days overdue
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 1);
        Notification::assertNotSentTo($this->assignee, TaskEscalationNotification::class);

        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $day) {
            $this->morning($day);
        }
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 1);

        $this->morning('2026-11-16'); // a week later
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 2);

        $escalation = Notification::sent($head, TaskEscalationNotification::class)->last();
        $text = $escalation->text('ru');
        $this->assertSame([10], array_column($escalation->items, 'days_overdue'));
        $this->assertStringStartsWith("🔺 <b>Эскалация: просрочка 10 дней</b>\n<a href=", $text);
        $this->assertStringContainsString('#'.$task->number.' «Reminder task»</b></a>', $text);
        $this->assertStringContainsString("🎯 Ответственный: <b>Assignee</b> · «В работе»\n🗓 Срок был пт, 06.11", $text);
        $this->assertStringContainsString('💬 Комментариев в задаче нет', $text);
        $this->assertStringContainsString('👤 Вы получили это как руководитель отдела', $text);
        $this->assertStringEndsWith('">Открыть задачу</a>', $text);
        $this->assertBalancedHtml($text);
    }

    public function test_head_gets_one_escalation_message_for_all_tasks_with_the_last_comment(): void
    {
        $head = $this->person('Head');
        $this->dept->update(['head_user_id' => $head->id]);
        $older = $this->task('2026-11-03', ['title' => 'Older', 'priority' => 8]);
        $this->task('2026-11-06', ['title' => 'Newer']);
        $comment = $older->comments()->make(['author_id' => $this->assignee->id, 'body' => '<p>Waiting for <b>data</b> &amp; files</p>']);
        $comment->body_format = ContentFormat::Html; // not mass-assignable, as in TaskWorkflowService
        $comment->save();

        $this->morning('2026-11-11');

        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 1);
        $escalation = Notification::sent($head, TaskEscalationNotification::class)->first();
        $text = $escalation->text('ru');

        $this->assertSame(['Older', 'Newer'], array_column($escalation->items, 'title'));
        $this->assertStringStartsWith('🔺 <b>Эскалация: 2 просроченные задачи</b>', $text);
        $this->assertSame(2, substr_count($text, '<blockquote expandable>'));
        $this->assertStringContainsString("«Older»</b></a>\n🔴 <b>8 дней</b> · срок был вт, 03.11 · 🟠 приоритет 8/10\n🎯 Assignee · «В работе»\n💬 Assignee, ", $text);
        $this->assertStringContainsString('Waiting for data &amp; files', $text);
        $this->assertStringContainsString("«Newer»</b></a>\n🔴 <b>5 дней</b> · срок был пт, 06.11\n🎯 Assignee · «В работе»\n💬 Комментариев в задаче нет", $text);
        $this->assertStringEndsWith('👤 Вы получили это как руководитель отдела', $text);
        $this->assertBalancedHtml($text);

        // Delivery closes both claims at once.
        event(new NotificationSent($head, $escalation, 'telegram', ['ok' => true]));
        $this->assertSame(['sent'], TaskReminder::query()->where('kind', 'escalation')->distinct()->pluck('status')->all());
        $this->assertSame(2, TaskReminder::query()->where('kind', 'escalation')->where('user_id', $head->id)->count());
    }

    public function test_no_escalation_before_three_days_overdue(): void
    {
        $head = $this->person('Head');
        $this->dept->update(['head_user_id' => $head->id]);
        $this->task('2026-11-09');

        $this->morning('2026-11-10');
        $this->morning('2026-11-11');

        Notification::assertNotSentTo($head, TaskEscalationNotification::class);

        $this->morning('2026-11-12');
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 1);
    }

    public function test_escalation_follows_the_department_of_the_person_not_of_the_task(): void
    {
        $otherDept = $this->createDepartment('Sales');
        $otherHead = $this->createUserInDepartment($otherDept, 'Sales Head', role: $this->role);
        $otherHead->update(['telegram_chat_id' => '900']);
        $otherDept->update(['head_user_id' => $otherHead->id]);
        $taskDeptHead = $this->person('Task Dept Head');
        $this->dept->update(['head_user_id' => $taskDeptHead->id]);

        // The task sits in IT and is on review: it hangs on the initiator, who works in Sales.
        $salesInitiator = $this->createUserInDepartment($otherDept, 'Sales Person', role: $this->role);
        $salesInitiator->update(['telegram_chat_id' => '901']);
        $this->task('2026-11-06', ['status' => TaskStatus::OnReview, 'initiator_id' => $salesInitiator->id]);

        $this->morning('2026-11-11');

        Notification::assertSentToTimes($otherHead, TaskEscalationNotification::class, 1);
        Notification::assertNotSentTo($taskDeptHead, TaskEscalationNotification::class);
    }

    public function test_escalation_falls_back_to_digest_recipients(): void
    {
        $digest = $this->person('Digest');
        $ceo = $this->person('Ceo');
        app(SettingsService::class)->set('reminder_digest_recipient_ids', [$digest->id, $this->assignee->id]);
        app(SettingsService::class)->set('reminder_no_escalation_head_ids', [$ceo->id]);

        // 1) the head is on the "no escalations" list
        $this->dept->update(['head_user_id' => $ceo->id]);
        $this->task('2026-11-06', ['title' => 'Under CEO']);
        // 2) the overdue person is the head
        $headDept = $this->createDepartment('Finance');
        $selfHead = $this->createUserInDepartment($headDept, 'Self Head', role: $this->role);
        $selfHead->update(['telegram_chat_id' => '902']);
        $headDept->update(['head_user_id' => $selfHead->id]);
        $this->task('2026-11-06', ['title' => 'Of the head', 'assignee_id' => $selfHead->id]);
        // 3) the department has no head
        $noHeadDept = $this->createDepartment('Ops');
        $orphan = $this->createUserInDepartment($noHeadDept, 'Orphan', role: $this->role);
        $orphan->update(['telegram_chat_id' => '903']);
        $this->task('2026-11-06', ['title' => 'No head', 'assignee_id' => $orphan->id]);

        $this->morning('2026-11-11');

        // All three land in one message for the digest recipient.
        Notification::assertSentToTimes($digest, TaskEscalationNotification::class, 1);
        $toDigest = Notification::sent($digest, TaskEscalationNotification::class)->first();
        $this->assertEqualsCanonicalizing(['Under CEO', 'Of the head', 'No head'], array_column($toDigest->items, 'title'));
        $this->assertStringContainsString('👤 Вы получили это, потому что в отделе ответственного некому принять эскалацию', $toDigest->text('ru'));
        Notification::assertNotSentTo([$ceo, $selfHead], TaskEscalationNotification::class);
        // The assignee is a digest recipient too, but never gets an escalation about their own task.
        $toAssignee = Notification::sent($this->assignee, TaskEscalationNotification::class)->first();
        $this->assertEqualsCanonicalizing(['Of the head', 'No head'], array_column($toAssignee->items, 'title'));
    }

    public function test_escalation_with_nobody_to_tell_is_counted_and_not_claimed(): void
    {
        $this->task('2026-11-06');

        $stats = $this->morning('2026-11-11');

        $this->assertSame(1, $stats['escalations_without_recipient']);
        $this->assertSame(0, $stats['escalations']);
        $this->assertSame(0, TaskReminder::query()->where('kind', 'escalation')->count());
    }

    public function test_moved_deadline_and_return_from_postponed_restart_the_escalation_clock(): void
    {
        $head = $this->person('Head');
        $this->dept->update(['head_user_id' => $head->id]);
        $task = $this->task('2026-11-03');

        $this->morning('2026-11-09');
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 1);

        // New deadline, overdue again three days later: a fresh escalation, not "wait a week".
        $task->update(['deadline' => '2026-11-09 23:59:00']);
        $this->morning('2026-11-11');
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 1);
        $this->morning('2026-11-12');
        Notification::assertSentToTimes($head, TaskEscalationNotification::class, 2);

        // Back from "postponed" with an old deadline: the head hears about it three days after the return.
        $returned = $this->task('2026-10-01', ['status' => TaskStatus::Postponed, 'title' => 'Returned', 'postponed_until' => '2026-11-12']);
        $this->morning('2026-11-13');
        $about = fn () => Notification::sent($head, TaskEscalationNotification::class)
            ->filter(fn (TaskEscalationNotification $n) => in_array($returned->id, array_column($n->items, 'task_id'), true));
        $this->assertCount(0, $about());
        $this->morning('2026-11-16');
        $this->assertCount(1, $about());
    }

    public function test_delivery_marks_the_claim_sent_and_a_failure_is_retried_by_a_later_tick(): void
    {
        $task = $this->task('2026-11-11');
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:30', 'Europe/Kyiv'));
        $this->artisan('tasks:send-morning-reminders')->assertSuccessful();

        $notification = $this->lastReminder($this->assignee);
        $claim = fn () => TaskReminder::query()->where('task_id', $task->id)->where('mark', 0)->sole();
        $this->assertSame('pending', $claim()->status);

        event(new NotificationFailed($this->assignee, $notification, 'telegram', ['exception' => new RuntimeException('500')]));
        $this->assertSame('failed', $claim()->status);

        // Later ticks the same day re-send only what failed, up to three attempts in total.
        foreach ([2, 3] as $attempt) {
            Carbon::setTestNow(Carbon::parse('2026-11-11 09:'.(30 + 5 * $attempt), 'Europe/Kyiv'));
            $this->artisan('tasks:send-morning-reminders')->assertSuccessful();

            $this->assertSame('pending', $claim()->status);
            $this->assertSame($attempt, $claim()->attempts);
            Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, $attempt);

            event(new NotificationFailed($this->assignee, $this->lastReminder($this->assignee), 'telegram', ['exception' => new RuntimeException('500')]));
        }

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00', 'Europe/Kyiv'));
        $this->artisan('tasks:send-morning-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 3);
        $this->assertSame('failed', $claim()->status);
    }

    public function test_successful_delivery_is_final_and_a_null_telegram_response_is_not_a_delivery(): void
    {
        $task = $this->task('2026-11-11');
        $other = $this->task('2026-11-11', ['title' => 'Other', 'assignee_id' => $this->initiator->id]);
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:30', 'Europe/Kyiv'));
        app(TaskReminderService::class)->run();

        event(new NotificationSent($this->assignee, $this->lastReminder($this->assignee), 'telegram', ['ok' => true]));
        event(new NotificationSent($this->initiator, $this->lastReminder($this->initiator), 'telegram', null));

        $this->assertSame(['sent'], TaskReminder::query()->where('task_id', $task->id)->distinct()->pluck('status')->all());
        $this->assertSame(['failed'], TaskReminder::query()->where('task_id', $other->id)->distinct()->pluck('status')->all());

        Carbon::setTestNow(Carbon::parse('2026-11-11 09:40', 'Europe/Kyiv'));
        $this->artisan('tasks:send-morning-reminders', ['--force' => true])->assertSuccessful();
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 1);
    }

    public function test_later_ticks_do_not_add_new_tasks_to_the_day(): void
    {
        $this->task('2026-11-11');
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:30', 'Europe/Kyiv'));
        $this->artisan('tasks:send-morning-reminders')->assertSuccessful();
        $this->assertSame('2026-11-11', app(SettingsService::class)->get(SendMorningReminders::LAST_RUN_KEY));
        $this->deliverAll();

        $this->task('2026-11-11', ['title' => 'Created at noon']);
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00', 'Europe/Kyiv'));
        $this->artisan('tasks:send-morning-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 1);
    }

    public function test_held_back_outside_working_hours(): void
    {
        $task = $this->task('2026-11-12');
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:30', 'Europe/Kyiv'));
        app(TaskReminderService::class)->run();
        $notification = $this->lastReminder($this->assignee);

        Carbon::setTestNow(Carbon::parse('2026-11-11 18:59', 'Europe/Kyiv'));
        $this->assertTrue($notification->shouldSend($this->assignee, 'telegram'));

        Carbon::setTestNow(Carbon::parse('2026-11-11 19:00', 'Europe/Kyiv'));
        $this->assertFalse($notification->shouldSend($this->assignee, 'telegram'));

        Carbon::setTestNow(Carbon::parse('2026-11-14 11:00', 'Europe/Kyiv'));
        $this->assertFalse($notification->shouldSend($this->assignee, 'telegram'));

        // The message never left, so its claim is still pending: the next morning takes it again.
        $this->assertSame(['pending'], TaskReminder::query()->where('task_id', $task->id)->distinct()->pluck('status')->all());
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:30', 'Europe/Kyiv'));
        app(TaskReminderService::class)->run();
        Notification::assertSentToTimes($this->assignee, TaskMorningReminderNotification::class, 2);
        $this->assertSame([0], array_column($this->rowsFor($this->assignee, '2026-11-12'), 'days'));
    }

    public function test_switch_off_dry_run_and_date_guard(): void
    {
        $this->task('2026-11-11');
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:30', 'Europe/Kyiv'));

        app(SettingsService::class)->set('reminder_enabled', false);
        $this->artisan('tasks:send-morning-reminders')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->artisan('tasks:send-morning-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('Would send to people: 1 (task lines: 1)')
            ->assertSuccessful();

        $this->artisan('tasks:send-morning-reminders', ['--date' => '2026-11-12'])->assertFailed();

        Notification::assertNothingSent();
        $this->assertSame(0, TaskReminder::query()->count());
        $this->assertNull(app(SettingsService::class)->get(SendMorningReminders::LAST_RUN_KEY));
    }

    public function test_old_one_off_deadline_notifications_leave_telegram_to_the_morning_reminder(): void
    {
        config(['services.telegram.dm_enabled' => true]);
        $service = app(TaskNotificationService::class);

        $this->assertNotContains('telegram', $service->resolveChannels($this->assignee, 'task.deadline_approaching'));
        $this->assertNotContains('telegram', $service->resolveChannels($this->assignee, 'task.overdue'));
        $this->assertContains('database', $service->resolveChannels($this->assignee, 'task.overdue'));
        $this->assertContains('telegram', $service->resolveChannels($this->assignee, 'task.commented'));

        app(SettingsService::class)->set('reminder_enabled', false);
        $this->assertContains('telegram', app(TaskNotificationService::class)->resolveChannels($this->assignee, 'task.overdue'));
    }

    public function test_profile_lets_a_person_switch_the_reminder_even_while_personal_messages_are_off(): void
    {
        config(['services.telegram.dm_enabled' => false]);
        $this->actingAs($this->assignee);

        Volt::test('profile.notification-preferences')
            ->assertSee(__('notification.events.task_reminder'))
            ->set('preferences.task_reminder.telegram', false)
            ->set('preferences.task_overdue.telegram', false)
            ->call('save');

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $this->assignee->id, 'event' => 'task.reminder', 'channel' => 'telegram', 'enabled' => false,
        ]);
        $this->assertDatabaseMissing('user_notification_preferences', ['event' => 'task.reminder', 'channel' => 'email']);
        $this->assertDatabaseMissing('user_notification_preferences', ['event' => 'task.overdue', 'channel' => 'telegram']);
        $this->assertFalse(app(TaskReminderService::class)->telegramAllowed($this->assignee->fresh()));
    }

    /** @return array<string, int> */
    private function morning(string $day): array
    {
        Carbon::setTestNow(Carbon::parse($day.' 09:30:00', 'Europe/Kyiv'));

        $stats = app(TaskReminderService::class)->run();
        $this->deliverAll();

        return $stats;
    }

    /**
     * Notification::fake() swallows the delivery events, so the claims are closed here the way
     * MarkTaskReminderDelivery closes them after a real Telegram answer.
     */
    private function deliverAll(): void
    {
        TaskReminder::query()->where('status', TaskReminder::STATUS_PENDING)->update(['status' => TaskReminder::STATUS_SENT]);
    }

    private function person(string $name): User
    {
        $user = $this->createUserInDepartment($this->dept, $name, role: $this->role);
        $user->update(['telegram_chat_id' => (string) (1000 + $user->id)]);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(?string $deadlineDay, array $overrides = []): Task
    {
        $initiator = isset($overrides['initiator_id']) ? User::findOrFail($overrides['initiator_id']) : $this->initiator;
        $assignee = isset($overrides['assignee_id']) ? User::findOrFail($overrides['assignee_id']) : $this->assignee;
        unset($overrides['initiator_id'], $overrides['assignee_id']);

        return $this->createTask($initiator, $assignee, $this->category ??= $this->createCategory(), array_merge([
            'title' => 'Reminder task',
            'status' => TaskStatus::InProgress,
            'deadline' => $deadlineDay === null ? null : $deadlineDay.' 23:59:00',
        ], $overrides));
    }

    private ?Category $category = null;

    /**
     * Lines the person was sent on that morning.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFor(User $user, string $day): array
    {
        return Notification::sent($user, TaskMorningReminderNotification::class)
            ->filter(fn (TaskMorningReminderNotification $n) => $n->date === $day)
            ->flatMap(fn (TaskMorningReminderNotification $n) => $n->rows)
            ->values()
            ->all();
    }

    /** Telegram rejects the whole message when a tag is left open. */
    private function assertBalancedHtml(string $text): void
    {
        foreach (['b', 'i', 'a', 'blockquote'] as $tag) {
            $this->assertSame(
                preg_match_all('/<'.$tag.'[ >]/', $text),
                substr_count($text, '</'.$tag.'>'),
                "unbalanced <{$tag}> in: {$text}",
            );
        }
    }

    private function lastReminder(User $user): TaskMorningReminderNotification
    {
        return Notification::sent($user, TaskMorningReminderNotification::class)->last();
    }
}
