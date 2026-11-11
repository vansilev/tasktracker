<?php

namespace Tests\Feature;

use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\CommentNotice;
use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\CommentNoticeSender;
use App\Services\SettingsService;
use App\Services\TaskDigestService;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use App\Support\TaskDigestFormatter;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * Calendar: 2026-11-09 Mon · 10 Tue · 11 Wed · 12 Thu · 13 Fri · 14 Sat · 15 Sun · 16 Mon · 17 Tue.
 */
class CommentNoticeTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private Department $dept;

    private Role $role;

    private User $initiator;

    private User $assignee;

    private User $watcher;

    private User $max;

    private Task $task;

    private int $messageId = 800;

    private int $update = 3000;

    private bool $telegramRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-11-10 12:31');
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.bot_username' => 'tasktracker_bot',
            'services.telegram.webhook_secret' => 'secret-token',
        ]);
        Http::fake(['api.telegram.org/*' => fn (HttpRequest $request) => $this->telegramRefuses
            ? Http::response(['ok' => false, 'description' => 'Bad Request'], 400)
            : Http::response([
                'ok' => true,
                'result' => str_ends_with($request->url(), '/sendMessage') ? ['message_id' => ++$this->messageId] : true,
            ], 200)]);
        app(SettingsService::class)->set('reminder_enabled', true);

        $this->dept = $this->createDepartment();
        $this->role = $this->createRoleWithPermissions($this->defaultPermissions(), [$this->dept->id]);
        $this->initiator = $this->person('Natalia');
        $this->assignee = $this->person('Ivan');
        $this->watcher = $this->person('Olena');
        $this->max = $this->person('Max');

        $this->task = $this->createTask($this->initiator, $this->assignee, $this->createCategory(), [
            'title' => 'Calls report', 'status' => TaskStatus::InProgress, 'deadline' => '2026-11-20 23:59:00',
        ]);
        $this->task->watchers()->attach($this->watcher->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mention_arrives_at_once_with_buttons_and_the_others_get_the_comment_marked_by_role(): void
    {
        $this->comment($this->initiator, 'Какие <b>столбцы</b> нужны & зачем?', mention: $this->max);
        app(CommentNoticeSender::class)->run();

        $toMax = $this->sentTo($this->max)[0];
        $this->assertStringStartsWith("📣 <b>Вас упомянули</b>\n<a href=", $toMax['text']);
        $this->assertStringContainsString("💬 Natalia, 12:31:\n<blockquote>@Max Какие столбцы нужны &amp; зачем?</blockquote>", $toMax['text']);
        $this->assertStringContainsString('👉 <b>Что нужно:</b> ответить или нажать «Принял»', $toMax['text']);
        $this->assertSame([['💬 Ответить', '👍 Принял'], ['📋 Скопировать номер']], $this->buttonTexts($toMax));

        $toIvan = $this->sentTo($this->assignee)[0];
        $this->assertStringStartsWith("💬 <b>Новый комментарий</b>\n<a href=", $toIvan['text']);
        $this->assertStringContainsString('🎯 Задача на вас · «В работе»', $toIvan['text']);
        $this->assertSame([['💬 Ответить'], ['📋 Скопировать номер']], $this->buttonTexts($toIvan));

        $this->assertStringContainsString('👀 Вы наблюдатель — делать ничего не нужно', $this->sentTo($this->watcher)[0]['text']);
        // The author gets nothing; the mentioned person gets the mention only, not the comment as well.
        $this->assertSame([], $this->sentTo($this->initiator));
        $this->assertCount(1, $this->sentTo($this->max));

        $this->assertSame(4, CommentNotice::query()->count() + 1); // three notices: Max, Ivan, Olena
        $this->assertSame(['sent'], CommentNotice::query()->distinct()->pluck('status')->all());
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $this->max->id, 'event' => 'task.mentioned', 'channel' => 'telegram', 'status' => 'sent']);

        // A second run sends nothing again.
        app(CommentNoticeSender::class)->run();
        $this->assertCount(1, $this->sentTo($this->max));
    }

    public function test_initiator_gets_the_comment_as_initiator_and_self_mention_is_ignored(): void
    {
        $this->comment($this->assignee, 'Готово, посмотрите', mention: $this->assignee);
        app(CommentNoticeSender::class)->run();

        $this->assertStringContainsString('🙋 Вы постановщик · «В работе»', $this->sentTo($this->initiator)[0]['text']);
        $this->assertSame([], $this->sentTo($this->assignee));
        $this->assertSame(0, CommentNotice::query()->where('kind', 'mention')->count());
    }

    public function test_nothing_is_sent_outside_working_hours_and_the_night_comes_as_one_morning_message(): void
    {
        $this->at('2026-11-10 19:40');
        $this->comment($this->initiator, 'Добавьте разбивку по менеджерам');
        $this->at('2026-11-10 21:15');
        $this->comment($this->watcher, 'И период — весь октябрь');
        $this->at('2026-11-11 08:10');
        $this->comment($this->initiator, 'Жду к обеду');
        $this->comment($this->initiator, 'И ещё: формат xlsx', mention: $this->max);
        $other = $this->createTask($this->initiator, $this->assignee, Category::query()->first(), ['title' => 'Other task', 'status' => TaskStatus::InProgress]);
        app(TaskService::class)->addComment($other, $this->initiator, 'По второй задаче', ContentSource::PlainText);

        foreach (['2026-11-10 23:00', '2026-11-11 03:00', '2026-11-11 09:29'] as $moment) {
            $this->at($moment);
            app(CommentNoticeSender::class)->run();
        }
        Http::assertNothingSent();

        $this->at('2026-11-11 09:30');
        app(CommentNoticeSender::class)->run();

        // Ivan: five plain comments in one message, grouped by task.
        $toIvan = $this->sentTo($this->assignee);
        $this->assertCount(1, $toIvan);
        $text = $toIvan[0]['text'];
        $this->assertStringStartsWith('🌙 <b>Пока вас не было · 5 комментариев</b>', $text);
        $this->assertSame(2, substr_count($text, '<blockquote expandable>'));
        $this->assertStringContainsString('🎯 задача на вас · 4 комментария', $text);
        $this->assertStringContainsString("💬 Natalia, вчера 19:40:\nДобавьте разбивку по менеджерам", $text);
        $this->assertStringContainsString("💬 Olena, вчера 21:15:\nИ период — весь октябрь", $text);
        $this->assertStringContainsString("💬 Natalia, 08:10:\nЖду к обеду", $text);
        $this->assertStringContainsString('🎯 задача на вас · 1 комментарий', $text);
        $this->assertSame([['#'.$this->task->number, '#'.$other->number]], $this->buttonTexts($toIvan[0]));

        // The mention is always its own message, with buttons.
        $this->assertSame([['💬 Ответить', '👍 Принял'], ['📋 Скопировать номер']], $this->buttonTexts($this->sentTo($this->max)[0]));
        // Olena watches one task and wrote one of the comments herself: three others, sent one by one.
        $this->assertCount(3, $this->sentTo($this->watcher));
    }

    public function test_a_comment_written_just_before_the_day_ends_still_goes_out(): void
    {
        $this->at('2026-11-10 18:59:30');
        $this->comment($this->initiator, 'Успею до семи');

        $this->at('2026-11-10 19:00:10');
        app(CommentNoticeSender::class)->run();
        $this->assertCount(1, $this->sentTo($this->assignee));

        // But one that missed its moment waits for the morning.
        $this->at('2026-11-10 18:58');
        $this->comment($this->initiator, 'Планировщик лежал');
        $this->at('2026-11-10 20:00');
        app(CommentNoticeSender::class)->run();
        $this->assertCount(1, $this->sentTo($this->assignee));
        $this->at('2026-11-11 09:30');
        app(CommentNoticeSender::class)->run();
        $this->assertCount(2, $this->sentTo($this->assignee));
    }

    public function test_what_is_sent_is_checked_at_the_moment_of_sending(): void
    {
        $this->at('2026-11-10 20:00');
        $deleted = $this->comment($this->initiator, 'Ошибочно написанное', mention: $this->max);
        $edited = $this->comment($this->initiator, 'Старый текст', mention: $this->max);
        $unmentioned = $this->comment($this->initiator, 'Упоминание уберут', mention: $this->max);
        $answered = $this->comment($this->initiator, 'На это ответят до утра', mention: $this->max);

        app(TaskService::class)->deleteComment($deleted, $this->initiator);
        app(TaskService::class)->updateComment($edited, $this->initiator, $this->chip($this->max).' Новый текст');
        app(TaskService::class)->updateComment($unmentioned, $this->initiator, 'Без упоминания');
        $this->comment($this->max, 'Отвечаю заранее');
        $this->watcher->update(['is_active' => false]);
        UserNotificationPreference::query()->create(['user_id' => $this->assignee->id, 'event' => 'task.commented', 'channel' => 'telegram', 'enabled' => false]);

        $this->at('2026-11-11 09:30');
        app(CommentNoticeSender::class)->run();

        $toMax = implode("\n---\n", array_column($this->sentTo($this->max), 'text'));
        $this->assertStringNotContainsString('Ошибочно написанное', $toMax);
        $this->assertStringNotContainsString('Старый текст', $toMax);
        $this->assertStringNotContainsString('Упоминание уберут', $toMax);
        $this->assertStringNotContainsString('На это ответят', $toMax);
        $this->assertStringNotContainsString('Вас упомянули', $toMax);
        // His own answer closed every question put to him before it, the edited one included.
        $this->assertSame(0, CommentNotice::query()->where('user_id', $this->max->id)->where('kind', 'mention')->whereNull('acknowledged_at')->count());

        // A deactivated watcher and a person who switched comments off get nothing.
        $this->assertSame([], $this->sentTo($this->watcher));
        $this->assertSame([], $this->sentTo($this->assignee));
        $this->assertDatabaseMissing('comment_notices', ['comment_id' => $deleted->id]);
    }

    public function test_a_mention_is_closed_by_a_comment_a_status_change_or_the_button(): void
    {
        $open = fn (User $user) => CommentNotice::query()->where('user_id', $user->id)->where('kind', 'mention')->whereNull('acknowledged_at')->count();

        // By a comment in the task.
        $this->comment($this->initiator, 'Вопрос первый', mention: $this->max);
        $this->assertSame(1, $open($this->max));
        $this->comment($this->max, 'Отвечаю');
        $this->assertSame(0, $open($this->max));
        $this->assertSame('comment', CommentNotice::query()->where('user_id', $this->max->id)->value('ack_via'));

        // By a status change (the assignee is asked, then moves the task).
        $this->comment($this->initiator, 'Вопрос исполнителю', mention: $this->assignee);
        $this->assertSame(1, $open($this->assignee));
        app(TaskWorkflowService::class)->transition($this->task, $this->assignee, TaskStatus::OnReview);
        $this->assertSame(0, $open($this->assignee));

        // By the button, and only by the person the message was sent to.
        $this->comment($this->initiator, 'Вопрос третий', mention: $this->max);
        app(CommentNoticeSender::class)->run();
        $notice = CommentNotice::query()->where('user_id', $this->max->id)->where('kind', 'mention')->whereNull('acknowledged_at')->sole();

        $this->press($this->assignee, 'y:'.$notice->id)->assertOk();
        $this->assertNull($notice->fresh()->acknowledged_at);
        $this->assertSame('Это уведомление больше не действует.', collect($this->botCalls('answerCallbackQuery'))->last()['text']);

        $this->at('2026-11-10 12:40');
        $this->press($this->max, 'y:'.$notice->id, messageId: 77)->assertOk();
        $this->assertSame('button', $notice->fresh()->ack_via);
        $edited = collect($this->botCalls('editMessageText'))->last();
        $this->assertSame(77, $edited['message_id']);
        $this->assertStringStartsWith('📣 <b>Вас упомянули</b> · 👍 принято в 12:40', $edited['text']);
        $this->assertStringNotContainsString('Что нужно', $edited['text']);
        $this->assertSame(['inline_keyboard' => []], json_decode($edited['reply_markup'], true));
        $this->assertSame('Принято', collect($this->botCalls('answerCallbackQuery'))->last()['text']);

        $this->press($this->max, 'y:'.$notice->id)->assertOk();
        $this->assertSame('Уже принято', collect($this->botCalls('answerCallbackQuery'))->last()['text']);
    }

    public function test_reply_button_publishes_the_answer_and_closes_the_mention(): void
    {
        $this->comment($this->initiator, 'Какой период?', mention: $this->max);
        app(CommentNoticeSender::class)->run();
        $notice = CommentNotice::query()->where('user_id', $this->max->id)->sole();

        $this->press($this->max, 'p:'.$notice->id)->assertOk();
        $prompt = $this->messageId;
        $this->assertStringContainsString('Комментарий к задаче', collect($this->botCalls('sendMessage'))->last()['text']);

        $this->postJson('/telegram/webhook', [
            'update_id' => ++$this->update,
            'message' => [
                'chat' => ['id' => (int) $this->max->telegram_chat_id, 'type' => 'private'],
                'from' => ['id' => (int) $this->max->telegram_chat_id],
                'text' => 'Весь октябрь',
                'reply_to_message' => ['message_id' => $prompt],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token'])->assertOk();

        $answer = TaskComment::query()->where('author_id', $this->max->id)->sole();
        $this->assertStringContainsString('Весь октябрь', $answer->body);
        $this->assertSame('comment', $notice->fresh()->ack_via);
    }

    public function test_unanswered_mention_is_repeated_after_a_working_day_and_reported_after_two(): void
    {
        // Friday noon: the working days that follow are Monday and Tuesday.
        $this->at('2026-11-13 12:31');
        $this->comment($this->initiator, 'Когда будет отчёт?', mention: $this->max);
        app(CommentNoticeSender::class)->run();
        $this->assertCount(1, $this->sentTo($this->max));

        foreach (['2026-11-13 18:00', '2026-11-14 12:31', '2026-11-15 12:31', '2026-11-16 09:30', '2026-11-16 12:30'] as $moment) {
            $this->at($moment);
            app(CommentNoticeSender::class)->run();
        }
        $this->assertCount(1, $this->sentTo($this->max));

        $this->at('2026-11-16 12:31');
        $stats = app(CommentNoticeSender::class)->run();
        $this->assertSame(1, $stats['reminders']);
        $reminder = $this->sentTo($this->max)[1];
        $this->assertStringStartsWith('🔔 <b>Ждут вашего ответа уже 1 рабочий день</b>', $reminder['text']);
        $this->assertStringContainsString("💬 Natalia, пт 13.11 в 12:31:\n<blockquote>@Max Когда будет отчёт?</blockquote>", $reminder['text']);
        $this->assertSame([['💬 Ответить', '👍 Принял'], ['📋 Скопировать номер']], $this->buttonTexts($reminder));

        // Reminded once only; the author hears about it after the second working day.
        $this->at('2026-11-16 17:00');
        app(CommentNoticeSender::class)->run();
        $this->assertCount(2, $this->sentTo($this->max));
        $this->assertSame([], $this->sentTo($this->initiator));

        $this->at('2026-11-17 12:31');
        $stats = app(CommentNoticeSender::class)->run();
        $this->assertSame(1, $stats['author_notes']);
        $note = $this->sentTo($this->initiator)[0];
        $this->assertStringStartsWith('⏳ <b>На ваш вопрос нет ответа 2 рабочих дня</b>', $note['text']);
        $this->assertStringContainsString('📣 Вы спрашивали у: <b>Max</b> · пт 13.11 в 12:31', $note['text']);
        $this->assertStringContainsString('Вопрос попал в ежедневную сводку как «зависший».', $note['text']);

        $this->at('2026-11-18 12:31');
        app(CommentNoticeSender::class)->run();
        $this->assertCount(1, $this->sentTo($this->initiator));
        $this->assertCount(2, $this->sentTo($this->max));
    }

    public function test_answered_mentions_and_closed_tasks_get_no_follow_ups(): void
    {
        $this->at('2026-11-13 12:31');
        $this->comment($this->initiator, 'Ответят вовремя', mention: $this->max);
        $closed = $this->createTask($this->initiator, $this->assignee, Category::query()->first(), ['title' => 'Closed', 'status' => TaskStatus::InProgress]);
        app(TaskService::class)->addComment($closed, $this->initiator, $this->chip($this->max).' по закрытой', ContentSource::Editor);
        app(CommentNoticeSender::class)->run();

        $this->comment($this->max, 'Отвечаю');
        $closed->update(['status' => TaskStatus::Completed]);

        $this->at('2026-11-17 13:00');
        $stats = app(CommentNoticeSender::class)->run();

        $this->assertSame(0, $stats['reminders']);
        $this->assertSame(0, $stats['author_notes']);
        $this->assertSame([], app(TaskDigestService::class)->build()['stuck']);
    }

    public function test_digest_shows_who_the_work_is_stuck_on_including_people_without_telegram(): void
    {
        $this->max->update(['telegram_chat_id' => null]);
        $this->at('2026-11-13 12:31');
        $this->comment($this->initiator, 'Какие столбцы нужны в выгрузке?', mention: $this->max);
        $this->at('2026-11-16 12:31');
        $this->comment($this->initiator, 'Свежий вопрос', mention: $this->assignee);

        // No Telegram: no message, no reminder — but the question is tracked.
        app(CommentNoticeSender::class)->run();
        $this->assertSame('skipped', CommentNotice::query()->where('user_id', $this->max->id)->value('status'));

        $this->at('2026-11-17 10:00');
        $digest = app(TaskDigestService::class)->build();

        $this->assertCount(1, $digest['stuck']);
        $this->assertSame(2, $digest['stuck'][0]['work_days']);
        $text = implode("\n", (new TaskDigestFormatter)->messages($digest, 'ru'));
        $this->assertStringContainsString("<blockquote expandable>🧍 <b>ЗАВИСЛО НА ЧЕЛОВЕКЕ · 1</b>\n\n👤 Max — не отвечает 2 рабочих дня\n<a href=", $text);
        $this->assertStringContainsString('Natalia, пт, 13.11: «@Max Какие столбцы нужны в выгрузке?»</blockquote>', $text);

        $this->assertSame([], app(TaskDigestService::class)->build(null, $this->createDepartment('Empty'))['stuck']);
    }

    public function test_switches_people_can_turn_comments_off_and_admin_can_turn_them_off_for_everyone(): void
    {
        app(SettingsService::class)->set('reminder_comment_notices', false);
        $this->comment($this->initiator, 'Только упоминание', mention: $this->max);
        app(CommentNoticeSender::class)->run();

        $this->assertCount(1, $this->sentTo($this->max));
        $this->assertSame([], $this->sentTo($this->assignee));
        $this->assertSame(1, CommentNotice::query()->count());

        app(SettingsService::class)->set('reminder_enabled', false);
        $this->comment($this->initiator, 'Всё выключено', mention: $this->max);
        $this->assertSame(1, CommentNotice::query()->count());
    }

    public function test_two_runs_at_once_send_each_message_once_and_a_stuck_one_is_not_repeated(): void
    {
        $this->comment($this->initiator, 'Один раз', mention: $this->max);
        $notice = CommentNotice::query()->where('user_id', $this->max->id)->sole();

        // Another run has already claimed it.
        $notice->update(['status' => CommentNotice::STATUS_SENDING, 'attempts' => 1]);
        app(CommentNoticeSender::class)->run();
        $this->assertSame([], $this->sentTo($this->max));

        // It never finished: after a while it is closed, not sent again.
        $this->travel(6)->minutes();
        app(CommentNoticeSender::class)->run();
        $this->assertSame([], $this->sentTo($this->max));
        $this->assertSame(['failed', 3], [$notice->fresh()->status, $notice->fresh()->attempts]);

        // A message Telegram refused is tried again, three times at most.
        $this->telegramRefuses = true;
        $this->comment($this->initiator, 'Откажет', mention: $this->max);
        foreach (range(1, 5) as $run) {
            app(CommentNoticeSender::class)->run();
        }
        $refused = CommentNotice::query()->where('user_id', $this->max->id)->latest('id')->first();
        $this->assertSame(['failed', 3], [$refused->status, $refused->attempts]);
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $this->max->id, 'event' => 'task.mentioned', 'status' => 'failed']);
    }

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'Europe/Kyiv'));
    }

    private function chip(User $user): string
    {
        return '<span data-type="mention" data-id="'.$user->id.'" data-label="'.$user->name.'">@'.$user->name.'</span>';
    }

    private function comment(User $author, string $html, ?User $mention = null): TaskComment
    {
        return app(TaskService::class)->addComment(
            $this->task->fresh(),
            $author,
            '<p>'.($mention ? $this->chip($mention).' ' : '').$html.'</p>',
            ContentSource::Editor,
        );
    }

    private function press(User $user, string $data, int $messageId = 50)
    {
        return $this->postJson('/telegram/webhook', [
            'update_id' => ++$this->update,
            'callback_query' => [
                'id' => 'cb-'.$this->update,
                'from' => ['id' => (int) $user->telegram_chat_id],
                'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $user->telegram_chat_id, 'type' => 'private']],
                'data' => $data,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token']);
    }

    /** @return list<array<string, mixed>> */
    private function botCalls(string $method): array
    {
        return Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/'.$method))
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function sentTo(User $user): array
    {
        return array_values(array_filter($this->botCalls('sendMessage'), fn (array $call) => (string) $call['chat_id'] === (string) $user->getOriginal('telegram_chat_id')));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<list<string>>
     */
    private function buttonTexts(array $payload): array
    {
        return array_map(fn (array $row) => array_column($row, 'text'), json_decode($payload['reply_markup'] ?? '{}', true)['inline_keyboard'] ?? []);
    }

    private function person(string $name): User
    {
        $user = $this->createUserInDepartment($this->dept, $name, role: $this->role);
        $user->update(['telegram_chat_id' => (string) (900000 + $user->id)]);

        return $user->fresh();
    }
}
