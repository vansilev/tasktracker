<?php

namespace Tests\Feature;

use App\Enums\ContentFormat;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\TaskDigestNotification;
use App\Services\SettingsService;
use App\Services\TaskDigestService;
use App\Services\TaskQueryService;
use App\Support\TaskDigestFormatter;
use Carbon\Carbon;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * Calendar used below: 2026-11-02 Mon … 06 Fri · 09 Mon · 10 Tue · 11 Wed · 12 Thu · 13 Fri · 14 Sat · 16 Mon.
 */
class TaskDigestTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private Department $it;

    private Department $sales;

    private Role $role;

    private User $ivan;

    private User $anna;

    private User $natalia;

    private ?Category $category = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Europe/Kyiv'));

        $this->it = $this->createDepartment('IT');
        $this->sales = $this->createDepartment('Sales');
        $this->role = $this->createRoleWithPermissions($this->defaultPermissions(), [$this->it->id, $this->sales->id]);
        $this->ivan = $this->person('Ivan', $this->it);
        $this->anna = $this->person('Anna', $this->sales);
        $this->natalia = $this->person('Natalia', $this->sales);

        $settings = app(SettingsService::class);
        $settings->set('reminder_enabled', true);
        $settings->set('reminder_digest_recipient_ids', [$this->natalia->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overdue_block_matches_the_tracker_filter_and_is_grouped_oldest_first(): void
    {
        $this->task('Ivan old', $this->ivan, '2026-11-03');
        $this->task('Ivan new', $this->ivan, '2026-11-10');
        $this->task('Anna mid', $this->anna, '2026-11-06');
        $this->task('On review', $this->ivan, '2026-11-09', ['status' => TaskStatus::OnReview]); // hangs on Natalia, the initiator
        $this->task('Sleeping', $this->anna, '2026-11-05', ['status' => TaskStatus::Postponed, 'postponed_until' => '2026-11-20']);
        $parent = $this->task('Parent', $this->ivan, '2026-11-20');
        $this->task('Subtask', $this->ivan, '2026-11-10', ['parent_id' => $parent->id]);
        $this->task('Due today', $this->ivan, '2026-11-11');
        $this->task('Done late', $this->ivan, '2026-11-01', ['status' => TaskStatus::Completed]);
        $this->task('No deadline', $this->ivan, null);
        $this->task('Deleted', $this->ivan, '2026-11-01')->delete();

        $digest = app(TaskDigestService::class)->build();

        $admin = User::factory()->create(['email' => 'digest-admin@tcsavant.com', 'system_type' => SystemType::Admin]);
        $filter = app(TaskQueryService::class)->paginate($admin, ['overdue' => true, 'per_page' => 100]);
        $this->assertSame(6, $filter->total());
        $this->assertSame($filter->total(), $digest['totals']['overdue']);

        // Departments and people in the order of their oldest overdue task.
        $this->assertSame(['IT', 'Sales'], array_column($digest['overdue'], 'department'));
        $this->assertSame([3, 3], array_column($digest['overdue'], 'count'));
        $this->assertSame(['Ivan'], array_column($digest['overdue'][0]['people'], 'name'));
        $this->assertSame(['Ivan old', 'Ivan new', 'Subtask'], array_column($digest['overdue'][0]['people'][0]['tasks'], 'title'));
        $this->assertSame([-8, -1, -1], array_column($digest['overdue'][0]['people'][0]['tasks'], 'days'));
        $this->assertSame(['Anna', 'Natalia'], array_column($digest['overdue'][1]['people'], 'name'));
        $this->assertSame(['Sleeping', 'Anna mid'], array_column($digest['overdue'][1]['people'][0]['tasks'], 'title'));
        $this->assertSame(['On review'], array_column($digest['overdue'][1]['people'][1]['tasks'], 'title'));

        $this->assertSame(9, $digest['totals']['open']);
        $this->assertSame(['Due today'], array_column($digest['today'], 'title'));
        $this->assertSame(1, $digest['totals']['today']);
    }

    public function test_tasks_of_excluded_and_service_accounts_are_left_out_and_counted_apart(): void
    {
        $service = $this->person('Service', $this->it);
        app(SettingsService::class)->set('reminder_excluded_user_ids', [$service->id]);
        $this->task('Of service', $service, '2026-11-03');
        $this->task('Of service, fine', $service, '2026-11-20');
        $this->task('Normal', $this->ivan, '2026-11-03');

        $digest = app(TaskDigestService::class)->build();

        $this->assertSame(1, $digest['totals']['overdue']);
        $this->assertSame(1, $digest['totals']['service_overdue']);
        $this->assertSame(1, $digest['totals']['open']);
        $this->assertStringContainsString('Ещё 1 на служебных аккаунтах', implode("\n", (new TaskDigestFormatter)->messages($digest, 'ru')));
    }

    public function test_today_tomorrow_and_people_without_telegram(): void
    {
        $this->anna->update(['telegram_chat_id' => null]);
        $this->task('Today', $this->ivan, '2026-11-11');
        $this->task('Tomorrow', $this->anna, '2026-11-12');
        $this->task('Later', $this->anna, '2026-11-13');
        $this->task('Anna late 1', $this->anna, '2026-11-09');
        $this->task('Anna late 2', $this->anna, '2026-11-10');
        $this->task('Ivan late', $this->ivan, '2026-11-10');

        $digest = app(TaskDigestService::class)->build();

        $this->assertSame(['Today'], array_column($digest['today'], 'title'));
        $this->assertSame(['Tomorrow'], array_column($digest['tomorrow'], 'title'));
        $this->assertSame([['name' => 'Anna', 'overdue' => 2]], $digest['no_telegram']);
    }

    public function test_stale_tasks_are_counted_in_working_days_from_the_last_comment_or_status_change(): void
    {
        $age = fn (Task $task, string $day) => DB::table('tasks')->where('id', $task->id)->update(['created_at' => $day.' 12:00:00']);

        $age($quiet = $this->task('Quiet', $this->ivan, null), '2026-11-04'); // Wed → 5 working days by Wed 11
        $age($this->task('Fresh', $this->ivan, null), '2026-11-05'); // 4 working days
        $age($commented = $this->task('Commented', $this->ivan, null), '2026-10-01');
        $age($moved = $this->task('Status moved', $this->ivan, null), '2026-10-01');
        $age($this->task('Rework is not watched', $this->ivan, null, ['status' => TaskStatus::Rework]), '2026-10-01');
        $age($this->task('Postponed is not watched', $this->ivan, null, ['status' => TaskStatus::Postponed]), '2026-10-01');
        $age($this->task('Waiting on initiator', $this->ivan, null, ['status' => TaskStatus::AwaitingInitiator]), '2026-10-26'); // 12 working days

        $comment = $commented->comments()->make(['author_id' => $this->ivan->id, 'body' => '<p>Working on it</p>']);
        $comment->body_format = ContentFormat::Html;
        $comment->save();
        DB::table('task_comments')->where('id', $comment->id)->update(['created_at' => '2026-11-09 12:00:00']);

        DB::table('task_histories')->insert([
            'task_id' => $moved->id, 'field' => 'status', 'old_value' => 'new', 'new_value' => 'in_progress',
            'changed_by' => $this->ivan->id, 'created_at' => '2026-11-10 12:00:00', 'updated_at' => '2026-11-10 12:00:00',
        ]);
        // A change of another field is not "movement" by the customer's definition.
        DB::table('task_histories')->insert([
            'task_id' => $quiet->id, 'field' => 'priority', 'old_value' => '5', 'new_value' => '7',
            'changed_by' => $this->ivan->id, 'created_at' => '2026-11-10 12:00:00', 'updated_at' => '2026-11-10 12:00:00',
        ]);

        $digest = app(TaskDigestService::class)->build();

        $this->assertSame(['Waiting on initiator', 'Quiet'], array_column($digest['stale'], 'title'));
        $this->assertSame([12, 5], array_column($digest['stale'], 'stale_work_days'));
    }

    public function test_escalations_raised_this_morning_are_listed_with_their_recipients(): void
    {
        $head = $this->person('Head', $this->it);
        $this->it->update(['head_user_id' => $head->id]);
        $task = $this->task('Escalated', $this->ivan, '2026-11-05');
        $old = $this->task('Escalated last week', $this->ivan, '2026-10-20');

        foreach ([[$task, '2026-11-11'], [$old, '2026-11-04']] as [$escalated, $day]) {
            TaskReminder::query()->create([
                'task_id' => $escalated->id, 'user_id' => $head->id, 'kind' => TaskReminder::KIND_ESCALATION, 'mark' => -1,
                'ref_date' => $day, 'deadline_date' => $escalated->deadline->format('Y-m-d'), 'role' => TaskReminder::ROLE_HEAD,
                'status' => TaskReminder::STATUS_SENT,
            ]);
        }

        $digest = app(TaskDigestService::class)->build();

        $this->assertSame(['Escalated'], array_column($digest['escalated'], 'title'));
        $this->assertSame(['Head'], $digest['escalated'][0]['recipients']);
        $this->assertTrue($digest['escalated'][0]['to_head']);
        $this->assertStringContainsString('Ivan → руководителю: Head', implode("\n", (new TaskDigestFormatter)->messages($digest, 'ru')));
    }

    public function test_department_digest_shows_only_tasks_hanging_on_that_department(): void
    {
        $this->task('IT task', $this->ivan, '2026-11-03');
        $this->task('Sales task', $this->anna, '2026-11-03');

        $digest = app(TaskDigestService::class)->build(null, $this->it);

        $this->assertSame('IT', $digest['department']);
        $this->assertSame(1, $digest['totals']['open']);
        $this->assertSame(['IT'], array_column($digest['overdue'], 'department'));
        $this->assertStringContainsString('Сводка по отделу «IT» · ср, 11.11', (new TaskDigestFormatter)->messages($digest, 'ru')[0]);
    }

    public function test_message_layout_and_change_since_the_previous_digest(): void
    {
        $this->task('Late <b>one</b> & co', $this->ivan, '2026-11-03');
        $this->task('Sleeping', $this->anna, '2026-11-05', ['status' => TaskStatus::Postponed, 'postponed_until' => '2026-11-20']);
        $this->task('Today', $this->ivan, '2026-11-11');
        DB::table('digest_snapshots')->insert(['date' => '2026-11-10', 'department_id' => 0, 'open_count' => 5, 'overdue_count' => 1]);
        DB::table('digest_snapshots')->insert(['date' => '2026-11-10', 'department_id' => $this->it->id, 'open_count' => 99, 'overdue_count' => 99]);

        $messages = (new TaskDigestFormatter)->messages(app(TaskDigestService::class)->build(), 'ru');

        $this->assertCount(1, $messages);
        $text = $messages[0];

        $this->assertStringStartsWith("📊 <b>Сводка по задачам · ср, 11.11</b>\nОткрыто 3 · 🔴 просрочено 2 · ⏰ сегодня 1\n\n<blockquote expandable>🔴 <b>ПРОСРОЧЕНО · 2</b>\n\n<b>IT · 1</b>\n👤 Ivan · 1\n<a href=", $text);
        $this->assertStringContainsString("«Late &lt;b&gt;one&lt;/b&gt; &amp; co»</a>\n<b>8 дней</b> · срок был 03.11 · «В работе»", $text);
        $this->assertStringContainsString("«Sleeping»</a>\n<b>6 дней</b> · срок был 05.11 · «Отложена» до 20.11", $text);
        $this->assertStringContainsString("<blockquote expandable>⏰ <b>СРОК СЕГОДНЯ · 1</b>\n\n<a href=", $text);
        $this->assertStringContainsString('👤 Ivan · IT · «В работе»</blockquote>', $text);
        $this->assertStringNotContainsString('СРОК ЗАВТРА', $text);
        $this->assertStringNotContainsString('ЭСКАЛИРОВАНО', $text);
        $this->assertStringContainsString('📌 <b>Итог:</b> открыто 3 (вт, 10.11 было 5, -2) · просрочено 2 (было 1, +1)', $text);
        $this->assertStringEndsWith('tab=all&amp;overdueOnly=1">Просроченные в трекере</a>', $text);
        $this->assertBalancedHtml($text);
    }

    public function test_long_digest_is_cut_into_messages_that_fit_telegram(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->task(str_repeat('Длинное название задачи ', 5).$i, $i % 2 ? $this->ivan : $this->anna, '2026-11-0'.(2 + $i % 7));
        }

        $messages = (new TaskDigestFormatter)->messages(app(TaskDigestService::class)->build(), 'ru');

        $this->assertGreaterThan(2, count($messages));
        $this->assertSame(90, array_sum(array_map(fn (string $m) => substr_count($m, '«Длинное название'), $messages)));
        $this->assertStringContainsString('(продолжение)', $messages[1]);

        foreach ($messages as $message) {
            $this->assertLessThanOrEqual(4096, mb_strlen($message));
            $this->assertBalancedHtml($message);
        }
    }

    public function test_scheduled_digest_goes_out_once_at_ten_on_working_days(): void
    {
        $this->task('Late', $this->ivan, '2026-11-03');

        foreach (['2026-11-11 09:59', '2026-11-11 19:00', '2026-11-14 10:00', '2026-11-15 10:00'] as $moment) {
            Carbon::setTestNow(Carbon::parse($moment, 'Europe/Kyiv'));
            $this->artisan('tasks:send-digest')->assertSuccessful();
        }
        Notification::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00', 'Europe/Kyiv'));
        $this->artisan('tasks:send-digest')->expectsOutputToContain('Sent the digest to 1 of 1 recipients (1 messages).')->assertSuccessful();
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 1);
        Notification::assertNotSentTo([$this->ivan, $this->anna], TaskDigestNotification::class);
        $this->assertDatabaseHas('digest_snapshots', ['department_id' => 0, 'open_count' => 1, 'overdue_count' => 1]);

        // Delivered: later ticks and a forced run do not send it again.
        event(new NotificationSent($this->natalia, Notification::sent($this->natalia, TaskDigestNotification::class)->first(), 'telegram', ['ok' => true]));
        $this->assertSame('sent', DB::table('digest_deliveries')->value('status'));

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:05', 'Europe/Kyiv'));
        $this->artisan('tasks:send-digest')->assertSuccessful();
        $this->artisan('tasks:send-digest', ['--force' => true])->assertSuccessful();
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 1);

        // The next working day: a new digest, compared with yesterday's snapshot.
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Europe/Kyiv'));
        $this->task('Another late', $this->ivan, '2026-11-10');
        $this->artisan('tasks:send-digest')->assertSuccessful();
        $second = Notification::sent($this->natalia, TaskDigestNotification::class)->last();
        $this->assertSame('2026-11-12', $second->date);
        $this->assertStringContainsString('открыто 2 (ср, 11.11 было 1, +1) · просрочено 2 (было 1, +1)', $second->text);
        $this->assertSame(2, DB::table('digest_snapshots')->count());
    }

    public function test_failed_digest_is_retried_the_same_day_and_held_back_outside_working_hours(): void
    {
        $this->task('Late', $this->ivan, '2026-11-03');
        $this->artisan('tasks:send-digest')->assertSuccessful();
        $first = Notification::sent($this->natalia, TaskDigestNotification::class)->first();

        event(new NotificationFailed($this->natalia, $first, 'telegram', ['exception' => new RuntimeException('500')]));
        $this->assertSame('failed', DB::table('digest_deliveries')->value('status'));

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:05', 'Europe/Kyiv'));
        $this->artisan('tasks:send-digest')->assertSuccessful();
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 2);
        $this->assertSame(2, (int) DB::table('digest_deliveries')->value('attempts'));
        $this->assertSame(1, DB::table('digest_snapshots')->count());

        // Telegram answering null (nobody to send to) is a failure, not a delivery.
        event(new NotificationSent($this->natalia, $first, 'telegram', null));
        $this->assertSame('failed', DB::table('digest_deliveries')->value('status'));

        Carbon::setTestNow(Carbon::parse('2026-11-11 19:30', 'Europe/Kyiv'));
        $this->assertFalse($first->shouldSend($this->natalia, 'telegram'));
        $onDemand = new TaskDigestNotification('2026-11-11', 0, 'text', 1, 1, scheduled: false);
        $this->assertTrue($onDemand->shouldSend($this->natalia, 'telegram'));
    }

    public function test_command_reports_missing_recipients_switch_and_dry_run(): void
    {
        $this->task('Late', $this->ivan, '2026-11-03');

        $this->artisan('tasks:send-digest', ['--dry-run' => true])
            ->expectsOutputToContain('Would send the digest to 1 of 1 recipients (1 messages).')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('digest_deliveries')->count());
        $this->assertSame(0, DB::table('digest_snapshots')->count());

        $this->natalia->update(['telegram_chat_id' => null]);
        $this->artisan('tasks:send-digest')->expectsOutputToContain('None of the digest recipients has Telegram linked')->assertSuccessful();

        app(SettingsService::class)->set('reminder_digest_recipient_ids', []);
        $this->artisan('tasks:send-digest')->expectsOutputToContain('No digest recipients are chosen')->assertSuccessful();

        app(SettingsService::class)->set('reminder_enabled', false);
        $this->artisan('tasks:send-digest')->expectsOutputToContain('switched off')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_svodka_answers_a_digest_recipient_in_a_private_chat_at_any_hour(): void
    {
        $this->webhookConfig();
        $this->task('Late', $this->ivan, '2026-11-03');
        Carbon::setTestNow(Carbon::parse('2026-11-14 23:00', 'Europe/Kyiv')); // Saturday night

        $this->svodka($this->natalia)->assertOk();

        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 1);
        $sent = Notification::sent($this->natalia, TaskDigestNotification::class)->first();
        $this->assertFalse($sent->scheduled);
        $this->assertStringContainsString('«Late»', $sent->text);
        // On-demand digests leave no trace in the daily bookkeeping.
        $this->assertSame(0, DB::table('digest_deliveries')->count());
        $this->assertSame(0, DB::table('digest_snapshots')->count());
    }

    public function test_svodka_is_refused_or_ignored_for_everyone_else(): void
    {
        $this->webhookConfig();
        $this->task('Late', $this->ivan, '2026-11-03');
        $admin = User::factory()->create(['email' => 'svodka-admin@tcsavant.com', 'system_type' => SystemType::Admin, 'telegram_chat_id' => '7001']);
        $fired = $this->person('Fired', $this->it);
        app(SettingsService::class)->set('reminder_digest_recipient_ids', [$this->natalia->id, $fired->id]);
        $fired->update(['is_active' => false]);

        // An ordinary employee gets a polite refusal, no tasks.
        $this->svodka($this->ivan)->assertOk();
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Сводка доступна получателям сводки'));

        $this->svodka($fired)->assertOk(); // deactivated recipient
        $this->svodka(null, chatId: '999999')->assertOk(); // stranger
        $this->svodka($this->natalia, chatType: 'group')->assertOk(); // typed in a group
        $this->svodka($this->natalia, fromId: '555')->assertOk(); // somebody else in her chat id
        $this->svodka($this->natalia, text: '/svodka@other_bot')->assertOk();
        $this->svodka($this->natalia, text: '/svodkaplease')->assertOk();
        $this->svodka($this->natalia, edited: true)->assertOk();
        $this->svodka($this->natalia, secret: 'wrong')->assertForbidden();
        Http::assertSentCount(1);
        Notification::assertNothingSent();

        // Without a webhook secret the sender cannot be trusted at all.
        config(['services.telegram.webhook_secret' => null]);
        $this->svodka($this->natalia, secret: null)->assertOk();
        Notification::assertNothingSent();

        $this->webhookConfig();
        $this->svodka($admin)->assertOk();
        $this->svodka($this->natalia, text: '/svodka@tasktracker_bot')->assertOk();
        Notification::assertSentToTimes($admin, TaskDigestNotification::class, 1);
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 1);
    }

    public function test_svodka_is_served_once_per_update_and_once_a_minute(): void
    {
        $this->webhookConfig();
        $this->task('Late', $this->ivan, '2026-11-03');

        $this->svodka($this->natalia, updateId: 100)->assertOk();
        $this->svodka($this->natalia, updateId: 100)->assertOk(); // Telegram repeats the update
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 1);
        Http::assertNothingSent();

        $this->svodka($this->natalia, updateId: 101)->assertOk(); // asked again right away
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 1);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'раз в минуту'));

        $this->travel(61)->seconds();
        $this->svodka($this->natalia, updateId: 102)->assertOk();
        Notification::assertSentToTimes($this->natalia, TaskDigestNotification::class, 2);
    }

    private function webhookConfig(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.bot_username' => 'tasktracker_bot',
            'services.telegram.webhook_secret' => 'secret-token',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function svodka(
        ?User $user,
        string $text = '/svodka',
        string $chatType = 'private',
        ?string $chatId = null,
        ?string $fromId = null,
        bool $edited = false,
        ?string $secret = 'secret-token',
        ?int $updateId = null,
    ) {
        static $nextUpdate = 5000;
        $chatId ??= (string) $user?->telegram_chat_id;

        return $this->postJson('/telegram/webhook', [
            'update_id' => $updateId ?? $nextUpdate++,
            $edited ? 'edited_message' : 'message' => [
                'chat' => ['id' => (int) $chatId, 'type' => $chatType],
                'from' => ['id' => (int) ($fromId ?? $chatId)],
                'text' => $text,
            ],
        ], $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    private function person(string $name, Department $department): User
    {
        $user = $this->createUserInDepartment($department, $name, role: $this->role);
        $user->update(['telegram_chat_id' => (string) (5000 + $user->id)]);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(string $title, User $assignee, ?string $deadlineDay, array $overrides = []): Task
    {
        return $this->createTask($this->natalia, $assignee, $this->category ??= $this->createCategory(), array_merge([
            'title' => $title,
            'status' => TaskStatus::InProgress,
            'deadline' => $deadlineDay === null ? null : $deadlineDay.' 23:59:00',
        ], $overrides));
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
}
