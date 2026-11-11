<?php

namespace Tests\Feature;

use App\Enums\ContentSource;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\CommentNotice;
use App\Models\DeadlineChangeRequest;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\TaskDigestNotification;
use App\Notifications\TaskEscalationNotification;
use App\Notifications\TaskMorningReminderNotification;
use App\Services\CommentNoticeSender;
use App\Services\SettingsService;
use App\Services\TaskDigestService;
use App\Services\TaskReminderService;
use App\Services\TaskService;
use App\Services\TelegramBotClient;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * The pilot list (who may be written to in Telegram) and the webhook registration command.
 * "Today" is Wednesday 2026-11-11, 10:00.
 */
class PilotAndWebhookTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private User $pilot;

    private User $other;

    private User $head;

    private Task $task;

    private int $messageId = 100;

    private ?\Closure $telegram = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Europe/Kyiv'));
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.bot_username' => 'tasktracker_bot',
            'services.telegram.webhook_secret' => 'secret-token',
        ]);
        config(['app.url' => 'https://task.example.test']);
        // Http::fake keeps the first stub for a URL, so a test swaps the behaviour through this closure.
        Http::fake(['api.telegram.org/*' => fn (HttpRequest $request) => $this->telegram !== null
            ? ($this->telegram)($request)
            : Http::response([
                'ok' => true,
                'result' => str_ends_with($request->url(), '/sendMessage') ? ['message_id' => ++$this->messageId] : true,
            ], 200)]);

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::EditOwnTask->value]), [$dept->id]);
        [$this->pilot, $this->other, $this->head] = array_map(function (string $name) use ($dept, $role) {
            $user = $this->createUserInDepartment($dept, $name, role: $role);
            $user->update(['telegram_chat_id' => (string) (400000 + $user->id)]);

            return $user->fresh();
        }, ['Pilot', 'Other', 'Head']);
        $dept->update(['head_user_id' => $this->head->id]);

        // The pilot person set the task, the other one does it: both sides of every message.
        $this->task = $this->createTask($this->pilot, $this->other, $this->createCategory(), [
            'title' => 'Pilot task', 'status' => TaskStatus::InProgress, 'deadline' => '2026-11-03 23:59:00',
        ]);

        $settings = app(SettingsService::class);
        $settings->set('reminder_enabled', true);
        $settings->set('reminder_digest_recipient_ids', [$this->pilot->id, $this->other->id]);
        $settings->set('reminder_pilot_user_ids', [$this->pilot->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_morning_reminders_escalations_and_the_digest_reach_only_the_pilot_people(): void
    {
        Notification::fake();
        $own = $this->createTask($this->other, $this->pilot, $this->task->category, [
            'title' => 'Of the pilot', 'status' => TaskStatus::InProgress, 'deadline' => '2026-11-11 23:59:00',
        ]);

        $stats = app(TaskReminderService::class)->run();

        Notification::assertSentToTimes($this->pilot, TaskMorningReminderNotification::class, 1);
        Notification::assertNotSentTo($this->other, TaskMorningReminderNotification::class);
        // The overdue task hangs on a non-pilot person and its head is outside the pilot too, so the
        // escalation falls back to the digest recipients — of whom only the pilot person is written to.
        Notification::assertNotSentTo([$this->head, $this->other], TaskEscalationNotification::class);
        Notification::assertSentToTimes($this->pilot, TaskEscalationNotification::class, 1);
        $this->assertSame(1, $stats['no_telegram']);
        $this->assertSame([$this->pilot->id], TaskReminder::query()->distinct()->pluck('user_id')->all());
        $this->assertSame(1, TaskReminder::query()->where('task_id', $own->id)->where('kind', 'threshold')->distinct()->count('task_id'));

        app(TaskDigestService::class)->sendScheduled();
        Notification::assertSentToTimes($this->pilot, TaskDigestNotification::class, 1);
        Notification::assertNotSentTo($this->other, TaskDigestNotification::class);

        // Even a notification queued for a non-pilot person by mistake goes nowhere…
        $this->assertSame([], (new TaskMorningReminderNotification('2026-11-11', []))->via($this->other));
        $this->assertSame(['telegram'], (new TaskMorningReminderNotification('2026-11-11', []))->via($this->pilot));
        $this->assertSame([], (new TaskDigestNotification('2026-11-11', 0, 'x', 1, 1))->via($this->other));
        // …except the digest a person asked for with /svodka.
        $this->assertSame(['telegram'], (new TaskDigestNotification('2026-11-11', 0, 'x', 1, 1, scheduled: false))->via($this->other));
    }

    public function test_comments_and_mentions_are_tracked_for_everyone_but_sent_only_to_the_pilot(): void
    {
        $chip = fn (User $user) => '<span data-type="mention" data-id="'.$user->id.'" data-label="'.$user->name.'">@'.$user->name.'</span>';
        app(TaskService::class)->addComment($this->task, $this->pilot, '<p>'.$chip($this->other).' когда будет?</p>', ContentSource::Editor);
        app(TaskService::class)->addComment($this->task->fresh(), $this->other, '<p>Скоро</p>', ContentSource::Editor);
        app(TaskService::class)->addComment($this->task->fresh(), $this->head, '<p>'.$chip($this->pilot).' и '.$chip($this->other).', жду</p>', ContentSource::Editor);

        app(CommentNoticeSender::class)->run();

        $chats = array_column($this->botCalls('sendMessage'), 'chat_id');
        $this->assertSame([$this->pilot->telegram_chat_id], array_values(array_unique($chats)));
        $this->assertCount(2, $chats); // the plain comment and the mention

        // The other person's notices exist (the question is tracked) but were never queued for sending.
        $others = CommentNotice::query()->where('user_id', $this->other->id)->get();
        $this->assertSame(['skipped'], $others->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $others->where('kind', 'mention')->whereNull('acknowledged_at')->count());
        $this->assertSame(0, CommentNotice::query()->where('status', 'pending')->count());
    }

    public function test_bot_client_holds_back_any_new_message_to_a_chat_outside_the_pilot(): void
    {
        $bot = app(TelegramBotClient::class);

        $this->assertNull($bot->sendMessage($this->other->telegram_chat_id, 'x'));
        $this->assertNull($bot->sendPrompt($this->other->telegram_chat_id, 'x'));
        $this->assertNull($bot->sendMessage('999999', 'x'));
        Http::assertNothingSent();

        $this->assertNotNull($bot->sendMessage($this->pilot->telegram_chat_id, 'x'));

        app(SettingsService::class)->set('reminder_pilot_user_ids', []);
        $this->assertNotNull($bot->sendMessage($this->other->telegram_chat_id, 'x'));
    }

    public function test_deadline_request_to_an_approver_outside_the_pilot_goes_to_the_tracker_only(): void
    {
        // Now the pilot person does the work and the approver is outside the pilot.
        $task = $this->createTask($this->other, $this->pilot, $this->task->category, [
            'title' => 'Ask the other', 'status' => TaskStatus::InProgress, 'deadline' => '2026-11-13 23:59:00',
        ]);
        $say = fn (string $text) => $this->postJson('/telegram/webhook', [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => (int) $this->pilot->telegram_chat_id, 'type' => 'private'],
                'from' => ['id' => (int) $this->pilot->telegram_chat_id],
                'text' => $text,
                'reply_to_message' => ['message_id' => $this->messageId],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token'])->assertOk();

        $this->postJson('/telegram/webhook', [
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1', 'from' => ['id' => (int) $this->pilot->telegram_chat_id], 'data' => 'd:'.$task->id,
                'message' => ['message_id' => 5, 'chat' => ['id' => (int) $this->pilot->telegram_chat_id, 'type' => 'private']],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token'])->assertOk();
        $say('18.11');
        $say('Не успеваю');

        $this->assertSame('pending', DeadlineChangeRequest::query()->sole()->status);
        $this->assertStringContainsString('Прошу перенести срок', $task->comments()->sole()->body);
        // The approver was not written to; the asker is told where the answer will come from.
        $this->assertNotContains($this->other->telegram_chat_id, array_column($this->botCalls('sendMessage'), 'chat_id'));
        $this->assertStringContainsString('ответ придёт в трекере', collect($this->botCalls('sendMessage'))->last()['text']);
    }

    public function test_webhook_command_changes_nothing_without_apply_and_keeps_the_address_and_secret(): void
    {
        $calls = [];
        $registered = ['url' => 'https://task.example.test/telegram/webhook', 'allowed_updates' => ['message'], 'max_connections' => 40, 'pending_update_count' => 0];
        $this->telegram = function (HttpRequest $request) use (&$calls, &$registered) {
            $method = basename($request->url());
            $calls[] = [$method, $request->data()];

            if ($method === 'setWebhook') {
                $registered['allowed_updates'] = json_decode($request->data()['allowed_updates'], true);
                $registered['url'] = $request->data()['url'];
            }

            return Http::response(['ok' => true, 'result' => $method === 'getWebhookInfo' ? $registered : true], 200);
        };

        $this->artisan('telegram:set-webhook')
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();
        $this->assertSame(['getWebhookInfo'], array_column($calls, 0));

        $this->artisan('telegram:set-webhook', ['--apply' => true])
            ->expectsOutputToContain('Webhook registered.')
            ->assertSuccessful();

        $set = collect($calls)->firstWhere(0, 'setWebhook')[1];
        $this->assertSame('https://task.example.test/telegram/webhook', $set['url']);
        $this->assertSame('secret-token', $set['secret_token']);
        $this->assertSame(['message', 'callback_query'], json_decode($set['allowed_updates'], true));
        $this->assertSame(40, (int) $set['max_connections']);
        $this->assertArrayNotHasKey('drop_pending_updates', $set);
    }

    public function test_webhook_command_refuses_a_foreign_address_and_a_missing_secret(): void
    {
        $calls = 0;
        $this->telegram = function (HttpRequest $request) use (&$calls) {
            $calls += basename($request->url()) === 'setWebhook' ? 1 : 0;

            return Http::response(['ok' => true, 'result' => ['url' => 'https://other.example/telegram/webhook', 'allowed_updates' => ['message']]], 200);
        };

        $this->artisan('telegram:set-webhook', ['--apply' => true])
            ->expectsOutputToContain('differs from this application')
            ->assertFailed();

        config(['services.telegram.webhook_secret' => null]);
        $this->artisan('telegram:set-webhook', ['--apply' => true])->assertFailed();

        $this->assertSame(0, $calls);
    }

    /** @return list<array<string, mixed>> */
    private function botCalls(string $method): array
    {
        return Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/'.$method))
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }
}
