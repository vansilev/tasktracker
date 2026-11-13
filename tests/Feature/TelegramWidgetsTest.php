<?php

namespace Tests\Feature;

use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\CommentNotice;
use App\Models\DeadlineChangeRequest;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskMorningReminderNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Services\CommentNoticeSender;
use App\Services\SettingsService;
use App\Services\TaskDigestService;
use App\Services\TaskService;
use App\Support\TaskDigestFormatter;
use App\Support\TelegramWidget;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Telegram\Telegram;
use NotificationChannels\Telegram\TelegramMessage;
use Psr\Http\Message\RequestInterface;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * Task 448, 2026-11-13: the bot's messages as HTML widgets (Bot API sendWidget) and the window
 * inside Telegram (Mini App) for everything that needs typing. 2026-11-10 is a Tuesday.
 */
class TelegramWidgetsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private User $initiator;

    private User $assignee;

    private User $watcher;

    private Task $task;

    /** What Telegram answers: method => [status, description]; anything else is a success. */
    private array $refuse = [];

    private int $messageId = 500;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:31', 'Europe/Kyiv'));
        // Telegram opens the window only from an https address.
        URL::forceRootUrl('https://tracker.test');
        URL::forceScheme('https');
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.bot_username' => 'tasktracker_bot',
            'services.telegram.webhook_secret' => 'secret-token',
            'services.telegram.dm_enabled' => false,
            'services.telegram.group_enabled' => false,
        ]);
        Http::fake(['api.telegram.org/*' => function (HttpRequest $request) {
            $method = substr($request->url(), strrpos($request->url(), '/') + 1);

            if (isset($this->refuse[$method])) {
                return Http::response(['ok' => false, 'description' => $this->refuse[$method]], 400);
            }

            return Http::response(['ok' => true, 'result' => in_array($method, ['sendMessage', 'sendWidget'], true) ? ['message_id' => ++$this->messageId] : true]);
        }]);

        $settings = app(SettingsService::class);
        $settings->set('reminder_enabled', true);
        $settings->set('telegram_widgets', true);

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        [$this->initiator, $this->assignee, $this->watcher] = array_map(function (string $name) use ($dept, $role) {
            $user = $this->createUserInDepartment($dept, $name, role: $role);
            $user->update(['telegram_chat_id' => (string) (900000 + $user->id)]);

            return $user->fresh();
        }, ['Natalia', 'Ivan', 'Olena']);

        $this->task = $this->createTask($this->initiator, $this->assignee, $this->createCategory(), [
            'title' => 'Calls report', 'description' => 'Собрать звонки', 'status' => TaskStatus::InProgress, 'deadline' => '2026-11-20 23:59:00',
        ]);
        $this->task->watchers()->attach($this->watcher->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    // ─── Switch ──────────────────────────────────────────────────────────────────────────

    public function test_widgets_are_off_by_default_and_can_be_limited_to_some_people(): void
    {
        $settings = app(SettingsService::class);
        $notification = new TaskStatusChangedNotification($this->task, $this->initiator, TaskStatus::New, TaskStatus::InProgress);

        $this->assertInstanceOf(TelegramWidgetMessage::class, $notification->toTelegram($this->assignee));

        $settings->set('telegram_widget_user_ids', [$this->initiator->id]);
        $this->assertInstanceOf(TelegramMessage::class, $notification->toTelegram($this->assignee));

        $settings->set('telegram_widget_user_ids', []);
        $settings->set('telegram_widgets', false);
        $this->assertInstanceOf(TelegramMessage::class, $notification->toTelegram($this->assignee));
    }

    // ─── Every widget fits and says what the text says ──────────────────────────────────

    public function test_morning_reminder_single_and_list_fit_and_typing_buttons_open_the_window(): void
    {
        $row = fn (int $days, string $role = 'assignee') => [
            'task_id' => $this->task->id, 'number' => $this->task->number, 'title' => 'Calls report <script>', 'status' => 'in_progress',
            'deadline' => '2026-11-20', 'deadline_date' => Carbon::parse('2026-11-10')->addDays($days)->format('Y-m-d'), 'days' => $days,
            'assignee' => 'Ivan', 'watcher' => $role === 'watcher', 'role' => $role, 'responsible' => 'Ivan', 'priority' => 9,
            'kind' => 'deadline', 'marks' => [1], 'ref_date' => '2026-11-10',
        ];

        $single = (new TaskMorningReminderNotification('2026-11-10', [$row(-2)]))->toTelegram($this->assignee);
        $this->assertInstanceOf(TelegramWidgetMessage::class, $single);
        $payload = $single->toArray();
        $this->assertTrue(TelegramWidget::fits($payload['html']));
        $this->assertStringContainsString('Calls report &lt;script&gt;', $payload['html']);
        $this->assertStringNotContainsString('<script>alert', $payload['html']);
        $this->assertStringContainsString('Просрочено на 2 дня', $payload['html']);

        $buttons = collect(json_decode($payload['reply_markup'], true)['inline_keyboard'])->flatten(1);
        $comment = $buttons->firstWhere('text', '💬 Комментарий');
        $this->assertSame('https://tracker.test/telegram/app?m=comment&task='.$this->task->id, $comment['web_app']['url']);
        $this->assertArrayNotHasKey('callback_data', $comment);
        // The main step stays an instant button; the link row moved inside the widget.
        $this->assertTrue($buttons->contains(fn (array $b) => str_starts_with($b['callback_data'] ?? '', 's:')));
        $this->assertFalse($buttons->contains(fn (array $b) => isset($b['url'])));

        $rows = array_map(fn (int $i) => ['task_id' => $this->task->id, 'number' => 100 + $i] + $row($i % 5 - 1, $i % 7 === 0 ? 'watcher' : 'assignee'), range(1, 30));
        $list = (new TaskMorningReminderNotification('2026-11-10', $rows))->toTelegram($this->assignee)->toArray();
        $this->assertTrue(TelegramWidget::fits($list['html']), 'a list of 30 tasks must fit');
        $this->assertStringContainsString('class=tb', $list['html']);
    }

    public function test_assigned_and_status_widgets_carry_the_facts_and_the_card_buttons(): void
    {
        $assigned = (new TaskAssignedNotification($this->task, $this->initiator))->toTelegram($this->assignee)->toArray();
        $this->assertStringContainsString('Вам назначена задача', $assigned['html']);
        $this->assertStringContainsString('Кто назначил: Natalia', $assigned['html']);
        $this->assertStringContainsString('Собрать звонки', $assigned['html']);
        $this->assertStringStartsWith('🆕 Вам назначена задача: #', html_entity_decode($assigned['caption']));

        $status = (new TaskStatusChangedNotification($this->task, $this->initiator, TaskStatus::OnReview, TaskStatus::Rework, 'Нет итогов'))
            ->toTelegram($this->assignee)->toArray();
        $this->assertStringContainsString('<span class=was>На проверке</span>', $status['html']);
        $this->assertStringContainsString('Нет итогов', $status['html']);
        $this->assertStringContainsString('m=status', $status['reply_markup']);
    }

    public function test_digest_is_one_widget_even_with_many_tasks(): void
    {
        foreach (range(1, 40) as $i) {
            $this->createTask($this->initiator, $this->assignee, $this->createCategory(), [
                'title' => 'Overdue task number '.$i.' with a fairly long title to fill the widget', 'status' => TaskStatus::InProgress, 'deadline' => '2026-11-0'.(1 + $i % 8).' 23:59:00',
            ]);
        }

        $digest = app(TaskDigestService::class)->build();
        [$html, $caption] = app(TaskDigestFormatter::class)->widget($digest, 'ru');

        $this->assertTrue(TelegramWidget::fits($html), 'compressed: '.strlen(gzcompress($html, 9)));
        $this->assertStringContainsString('Ещё 28 — в трекере', $html);
        $this->assertStringContainsString('просрочено 40', $caption);
    }

    // ─── Sending, and the text when the widget is refused ───────────────────────────────

    public function test_a_refused_widget_arrives_as_the_old_text(): void
    {
        $calls = new \ArrayObject;
        $this->app->bind(Telegram::class, fn () => new Telegram('test-token', new Client(['handler' => HandlerStack::create(function (RequestInterface $request) use ($calls) {
            $method = substr($request->getUri()->getPath(), strrpos($request->getUri()->getPath(), '/') + 1);
            $calls[] = $method;

            return Create::promiseFor($method === 'sendWidget'
                ? new Response(400, [], '{"ok":false,"description":"Bad Request: INPUT_MEDIA_WIDGET_HTML_INVALID"}')
                : new Response(200, [], '{"ok":true,"result":{"message_id":7}}'));
        })])));

        $this->assignee->notifyNow(new TaskStatusChangedNotification($this->task, $this->initiator, TaskStatus::New, TaskStatus::InProgress), ['telegram']);

        $this->assertSame(['sendWidget', 'sendMessage'], $calls->getArrayCopy());
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $this->assignee->id, 'event' => 'task.status_changed', 'channel' => 'telegram', 'status' => 'sent']);
    }

    public function test_a_mention_goes_as_a_widget_with_the_reply_window_and_accept_edits_the_caption(): void
    {
        $this->comment($this->initiator, 'Какие столбцы нужны?', mention: $this->assignee);
        app(CommentNoticeSender::class)->run();

        $widget = collect($this->calls('sendWidget'))->firstWhere('chat_id', $this->assignee->telegram_chat_id);
        $this->assertNotNull($widget);
        $this->assertStringContainsString('Какие столбцы нужны?', $widget['html']);
        $notice = CommentNotice::query()->where('user_id', $this->assignee->id)->sole();
        $keyboard = json_decode($widget['reply_markup'], true)['inline_keyboard'];
        $this->assertSame('https://tracker.test/telegram/app?m=reply&notice='.$notice->id, $keyboard[0][0]['web_app']['url']);
        $this->assertSame('y:'.$notice->id, $keyboard[0][1]['callback_data']);
        $this->assertSame($widget['caption'] !== '', true);

        // "Принял" on a widget: there is no text to edit, so the caption says it and the buttons go.
        $this->refuse['editMessageText'] = 'Bad Request: there is no text in the message to edit';
        $this->press($this->assignee, 'y:'.$notice->id, (int) $notice->fresh()->telegram_message_id)->assertOk();

        $caption = collect($this->calls('editMessageCaption'))->last();
        $this->assertStringContainsString('👍 Принято в 12:31', $caption['caption']);
        $this->assertSame([], json_decode($caption['reply_markup'], true)['inline_keyboard']);
        $this->assertNotNull($notice->fresh()->acknowledged_at);
    }

    public function test_people_without_widgets_get_the_text_as_before(): void
    {
        app(SettingsService::class)->set('telegram_widgets', false);
        $this->comment($this->initiator, 'Вопрос', mention: $this->assignee);
        app(CommentNoticeSender::class)->run();

        $this->assertSame([], $this->calls('sendWidget'));
        $this->assertNotEmpty(collect($this->calls('sendMessage'))->where('chat_id', $this->assignee->telegram_chat_id));
    }

    // ─── The window (Mini App) ───────────────────────────────────────────────────────────

    public function test_the_window_opens_only_for_a_valid_fresh_signature_of_a_linked_person(): void
    {
        $this->postJson('/telegram/app/context', ['initData' => $this->initData($this->assignee), 'm' => 'comment', 'task' => $this->task->id])
            ->assertOk()->assertJsonPath('task.number', $this->task->number)->assertJsonPath('user', 'Ivan');

        $forged = str_replace('hash=', 'hash=0', $this->initData($this->assignee));
        $this->postJson('/telegram/app/context', ['initData' => $forged, 'm' => 'comment', 'task' => $this->task->id])->assertStatus(401);

        $old = $this->initData($this->assignee, authDate: now()->subDays(2)->timestamp);
        $this->postJson('/telegram/app/context', ['initData' => $old, 'm' => 'comment', 'task' => $this->task->id])->assertStatus(401);

        // Junk instead of Telegram's data is a plain refusal, not a server error.
        $this->postJson('/telegram/app/context', ['initData' => 'a[]=1&hash=x', 'm' => ['x']])->assertStatus(401);
        $this->postJson('/telegram/app/context', ['initData' => ['x' => 1], 'm' => 'comment'])->assertStatus(401);

        $stranger = $this->initData(null, telegramId: 123456789);
        $this->postJson('/telegram/app/context', ['initData' => $stranger, 'm' => 'comment', 'task' => $this->task->id])->assertStatus(401);

        // A task the person may not see is not shown, whatever id the page sends.
        $hidden = $this->createTask($this->initiator, $this->initiator, $this->createCategory(), ['title' => 'Secret']);
        $outsider = $this->createUserInDepartment($this->createDepartment(), 'Outsider', role: $this->createRoleWithPermissions([]));
        $outsider->update(['telegram_chat_id' => '777001']);
        $this->postJson('/telegram/app/context', ['initData' => $this->initData($outsider->fresh()), 'm' => 'comment', 'task' => $hidden->id])
            ->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_reply_and_comment_from_the_window_land_in_the_task_as_the_person(): void
    {
        $this->comment($this->initiator, 'Какие столбцы?', mention: $this->assignee);
        $notice = CommentNotice::query()->where('user_id', $this->assignee->id)->sole();

        $this->postJson('/telegram/app/context', ['initData' => $this->initData($this->assignee), 'm' => 'reply', 'notice' => $notice->id])
            ->assertOk()->assertJsonPath('quote.text', '@Ivan Какие столбцы?');

        $this->postJson('/telegram/app/submit', ['initData' => $this->initData($this->assignee), 'm' => 'reply', 'notice' => $notice->id, 'text' => 'Номер, дата, менеджер'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('Номер, дата, менеджер', strip_tags((string) TaskComment::query()->where('author_id', $this->assignee->id)->latest('id')->value('body')));
        // Answering is a reaction: the mention is closed.
        $this->assertNotNull($notice->fresh()->acknowledged_at);

        // Somebody else's notice cannot be answered from another account.
        $this->postJson('/telegram/app/submit', ['initData' => $this->initData($this->watcher), 'm' => 'reply', 'notice' => $notice->id, 'text' => 'чужой ответ'])
            ->assertStatus(422);
        $this->assertSame(0, TaskComment::query()->where('author_id', $this->watcher->id)->count());

        // An empty comment is refused, not saved.
        $this->postJson('/telegram/app/submit', ['initData' => $this->initData($this->assignee), 'm' => 'comment', 'task' => $this->task->id, 'text' => '  '])
            ->assertStatus(422);
    }

    public function test_status_from_the_window_needs_the_reason_and_the_date_the_tracker_needs(): void
    {
        $data = ['initData' => $this->initData($this->assignee), 'm' => 'status', 'task' => $this->task->id];

        $context = $this->postJson('/telegram/app/context', $data)->assertOk()->json('statuses');
        $postponed = collect($context)->firstWhere('value', 'postponed');
        $this->assertTrue($postponed['reason']);
        $this->assertTrue($postponed['date']);

        $this->postJson('/telegram/app/submit', $data + ['status' => 'postponed', 'text' => 'Ждём макеты'])->assertStatus(422);
        $this->assertSame(TaskStatus::InProgress, $this->task->fresh()->status);

        $this->postJson('/telegram/app/submit', $data + ['status' => 'postponed', 'text' => 'Ждём макеты', 'date' => '2026-11-16'])->assertOk();
        $this->assertSame(TaskStatus::Postponed, $this->task->fresh()->status);
        $this->assertSame('2026-11-16', $this->task->fresh()->postponed_until->format('Y-m-d'));

        // The same press again is not a second change.
        $this->postJson('/telegram/app/submit', $data + ['status' => 'postponed', 'text' => 'Ждём макеты', 'date' => '2026-11-16'])->assertStatus(422);
    }

    public function test_deadline_request_and_a_refusal_with_its_reason(): void
    {
        $this->postJson('/telegram/app/submit', ['initData' => $this->initData($this->assignee), 'm' => 'deadline', 'task' => $this->task->id, 'date' => '2026-11-25', 'text' => 'Ждём данные'])
            ->assertOk();

        $request = DeadlineChangeRequest::query()->sole();
        $this->assertSame('2026-11-25', $request->new_deadline->format('Y-m-d'));
        // The initiator gets it as a widget; "Отказать" opens the window for the reason.
        $toInitiator = collect($this->calls('sendWidget'))->firstWhere('chat_id', $this->initiator->telegram_chat_id);
        $this->assertStringContainsString('m=reject&req='.$request->id, $toInitiator['reply_markup']);
        $this->assertStringContainsString('a:'.$request->id, $toInitiator['reply_markup']);

        // Only the approver may decline.
        $this->postJson('/telegram/app/submit', ['initData' => $this->initData($this->watcher), 'm' => 'reject', 'req' => $request->id, 'text' => 'нет'])
            ->assertStatus(422);
        $this->postJson('/telegram/app/submit', ['initData' => $this->initData($this->initiator), 'm' => 'reject', 'req' => $request->id, 'text' => 'Промо в понедельник'])
            ->assertOk();

        $this->assertSame(DeadlineChangeRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame('2026-11-20', $this->task->fresh()->deadline->format('Y-m-d'));
        $this->assertStringContainsString('Промо в понедельник', strip_tags((string) TaskComment::query()->where('author_id', $this->initiator->id)->latest('id')->value('body')));
        $toAsker = collect($this->calls('sendMessage'))->where('chat_id', $this->assignee->telegram_chat_id)->last();
        $this->assertStringContainsString('Промо в понедельник', $toAsker['text']);
    }

    public function test_the_page_itself_is_empty_without_telegram(): void
    {
        $this->get('/telegram/app?m=comment&task='.$this->task->id)
            ->assertOk()
            ->assertSee('telegram-web-app.js', false)
            ->assertDontSee('Calls report');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────────────

    private function initData(?User $user, ?int $telegramId = null, ?int $authDate = null): string
    {
        $fields = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAH'.uniqid(),
            'user' => json_encode(['id' => $telegramId ?? (int) $user->telegram_chat_id, 'first_name' => $user?->name ?? 'X']),
        ];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', 'test-token', 'WebAppData', true));

        return http_build_query($fields);
    }

    private function comment(User $author, string $html, ?User $mention = null): TaskComment
    {
        $chip = $mention ? '<span data-type="mention" data-id="'.$mention->id.'" data-label="'.$mention->name.'">@'.$mention->name.'</span> ' : '';

        return app(TaskService::class)->addComment($this->task->fresh(), $author, '<p>'.$chip.$html.'</p>', ContentSource::Editor);
    }

    private function press(User $user, string $data, int $messageId)
    {
        return $this->postJson('/telegram/webhook', [
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb-'.uniqid(),
                'from' => ['id' => (int) $user->telegram_chat_id],
                'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $user->telegram_chat_id, 'type' => 'private']],
                'data' => $data,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token']);
    }

    /** @return list<array<string, mixed>> */
    private function calls(string $method): array
    {
        return Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/'.$method))
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }
}
