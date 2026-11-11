<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DeadlineChangeRequest;
use App\Models\Department;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskMorningReminderNotification;
use App\Services\TelegramActionService;
use App\Services\TelegramLinkService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

/**
 * Buttons and dialogs of the bot. "Today" is Wednesday 2026-11-11.
 */
class TelegramActionsTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private Department $dept;

    private Role $role;

    private User $initiator;

    private User $assignee;

    private ?Category $category = null;

    private int $update = 1000;

    private int $messageId = 500;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-11 11:00:00', 'Europe/Kyiv'));
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.bot_username' => 'tasktracker_bot',
            'services.telegram.webhook_secret' => 'secret-token',
        ]);

        // Every bot call succeeds; sent messages get increasing ids, like in Telegram.
        Http::fake(['api.telegram.org/*' => fn (HttpRequest $request) => Http::response([
            'ok' => true,
            'result' => str_ends_with($request->url(), '/sendMessage') ? ['message_id' => ++$this->messageId] : true,
        ], 200)]);

        $this->dept = $this->createDepartment();
        $this->role = $this->createRoleWithPermissions(array_merge($this->defaultPermissions(), [Permission::EditOwnTask->value]), [$this->dept->id]);
        $this->initiator = $this->person('Initiator');
        $this->assignee = $this->person('Assignee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_button_changes_the_status_as_the_person_and_redraws_the_card(): void
    {
        $task = $this->task(['status' => TaskStatus::New]);

        $this->press($this->assignee, 's:'.$task->id.':in_progress', messageId: 77)->assertOk();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $history = TaskHistory::query()->where('task_id', $task->id)->where('field', 'status')->latest('id')->first();
        $this->assertSame($this->assignee->id, $history->changed_by);
        $this->assertSame(['new', 'in_progress'], [$history->old_value, $history->new_value]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.status_changed', 'actor_id' => $this->assignee->id]);

        $audit = AuditLog::query()->where('action', 'telegram.action')->sole();
        $this->assertSame('status', $audit->new_values['action']);
        $this->assertSame('…'.substr($this->assignee->telegram_chat_id, -4), $audit->new_values['chat']);

        $edit = $this->botCalls('editMessageText')[0];
        $this->assertSame(77, $edit['message_id']);
        $this->assertStringStartsWith('✅ <b>Статус изменён: Новая → В работе</b>', $edit['text']);
        $this->assertStringContainsString('🎯 Исполнитель — вы', $edit['text']);
        $this->assertSame('HTML', $edit['parse_mode']);
        // The buttons now fit the new status.
        $this->assertSame(
            [['🔍 На проверку'], ['💬 Комментарий', '🗓 Перенести срок'], ['⋯ Другой статус']],
            $this->buttonTexts($edit),
        );
        $this->assertSame('Статус изменён: Новая → В работе', $this->botCalls('answerCallbackQuery')[0]['text']);
    }

    public function test_only_transitions_allowed_to_the_person_are_offered_and_accepted(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview]);

        // The assignee cannot accept their own work: the button does not exist and a forged press is refused.
        $this->press($this->assignee, 'o:'.$task->id)->assertOk();
        $card = $this->botCalls('sendMessage')[0];
        $this->assertSame([['💬 Комментарий', '🗓 Перенести срок']], $this->buttonTexts($card));

        $this->press($this->assignee, 's:'.$task->id.':completed')->assertOk();
        $this->assertSame(TaskStatus::OnReview, $task->fresh()->status);
        $answer = collect($this->botCalls('answerCallbackQuery'))->last();
        $this->assertSame('Статус уже изменён: сейчас «На проверке»', $answer['text']);
        $this->assertTrue($answer['show_alert']);

        // The initiator can.
        $this->press($this->initiator, 'o:'.$task->id)->assertOk();
        $this->assertSame([['✅ Принять'], ['💬 Комментарий', '🗓 Перенести срок'], ['⋯ Другой статус']], $this->buttonTexts(collect($this->botCalls('sendMessage'))->last()));

        $this->press($this->initiator, 'm:'.$task->id)->assertOk();
        $menu = collect($this->botCalls('editMessageText'))->last();
        $this->assertStringContainsString('Сейчас «На проверке». Выберите новый статус:', $menu['text']);
        $this->assertSame([['✅ Принять', '↩️ На доработку'], ['❌ Отменить'], ['← Назад']], $this->buttonTexts($menu));

        $this->press($this->initiator, 's:'.$task->id.':completed')->assertOk();
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertSame($this->initiator->id, $task->fresh()->closed_by);
    }

    public function test_a_stale_button_reports_the_current_status_instead_of_acting(): void
    {
        $task = $this->task(['status' => TaskStatus::New]);
        $task->update(['status' => TaskStatus::Cancelled]); // changed in the tracker meanwhile

        $this->press($this->assignee, 's:'.$task->id.':in_progress')->assertOk();

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertSame('Статус уже изменён: сейчас «Отменена»', $this->botCalls('answerCallbackQuery')[0]['text']);
        $this->assertStringContainsString('Сейчас «Отменена»', $this->botCalls('editMessageText')[0]['text']);
        $this->assertSame(0, AuditLog::query()->where('action', 'telegram.action')->count());
    }

    public function test_nobody_can_act_through_someone_elses_chat_a_group_or_a_forged_update(): void
    {
        $task = $this->task(['status' => TaskStatus::New]);
        $data = 's:'.$task->id.':in_progress';
        $stranger = $this->person('Stranger', department: $this->createDepartment('Other'), role: $this->createRoleWithPermissions($this->defaultPermissions()));
        $fired = $this->person('Fired');
        $fired->update(['is_active' => false]);
        $twin = $this->person('Twin');
        $twin->update(['telegram_chat_id' => $this->assignee->telegram_chat_id]);

        $this->press($this->assignee, $data, secret: 'wrong')->assertForbidden();
        $this->press($this->assignee, $data, chatType: 'group')->assertOk();
        $this->press($this->assignee, $data, fromId: '424242')->assertOk(); // someone else pressing in "their" chat
        $this->press(null, $data, chatId: '999999')->assertOk(); // unknown chat
        $this->press($fired, $data)->assertOk();
        $this->press($this->assignee, $data)->assertOk(); // the chat now belongs to two accounts
        $this->press($stranger, $data)->assertOk(); // a real user who cannot see the task
        $this->press($stranger, 'o:'.$task->id)->assertOk();
        $this->press($stranger, 'c:'.$task->id)->assertOk();

        $this->assertSame(TaskStatus::New, $task->fresh()->status);
        $this->assertSame(0, $task->comments()->count());
        // Only the real, visible-to-nobody-else stranger got answers, and they were refusals.
        $this->assertSame([], $this->botCalls('sendMessage'));
        $this->assertSame([], $this->botCalls('editMessageText'));
        $answers = $this->botCalls('answerCallbackQuery');
        $this->assertCount(3, $answers);
        $this->assertSame(['Задача недоступна или у вас нет прав на это действие.'], array_values(array_unique(array_column($answers, 'text'))));

        // Without a webhook secret nothing can be trusted.
        $twin->update(['telegram_chat_id' => '777000']);
        config(['services.telegram.webhook_secret' => null]);
        $this->press($this->assignee, $data, secret: null)->assertOk();
        $this->assertSame(TaskStatus::New, $task->fresh()->status);
    }

    public function test_a_repeated_update_or_a_double_tap_changes_the_status_once(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'rework_count' => 0]);
        $task->update(['status' => TaskStatus::OnReview]);
        $data = 's:'.$task->id.':rework';

        // "Send back" needs a reason, so it opens a question; answer it twice in a row.
        $this->press($this->initiator, $data)->assertOk();
        $prompt = $this->lastMessageId();
        $this->say($this->initiator, 'Нужны правки', replyTo: $prompt, updateId: 9001)->assertOk();
        $this->say($this->initiator, 'Нужны правки', replyTo: $prompt, updateId: 9001)->assertOk(); // Telegram repeats the update
        $this->say($this->initiator, 'Нужны правки', replyTo: $prompt, updateId: 9002)->assertOk(); // or the person sends it again

        $this->assertSame(TaskStatus::Rework, $task->fresh()->status);
        $this->assertSame(1, $task->fresh()->rework_count);
        $this->assertSame(1, $task->comments()->count());

        // A plain button pressed twice (same callback id, then a new one).
        $back = 's:'.$task->id.':in_progress';
        $this->press($this->assignee, $back, callbackId: 'cb-1')->assertOk();
        $this->press($this->assignee, $back, callbackId: 'cb-1')->assertOk();
        $this->press($this->assignee, $back, callbackId: 'cb-2')->assertOk();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame(1, TaskHistory::query()->where('task_id', $task->id)->where('new_value', 'in_progress')->count());
    }

    public function test_status_that_needs_a_reason_asks_for_it_and_takes_only_a_reply(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress]);

        $this->press($this->assignee, 's:'.$task->id.':awaiting_initiator', messageId: 80)->assertOk();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $question = $this->botCalls('sendMessage')[0];
        $prompt = $this->lastMessageId();
        $this->assertStringContainsString('<b>⏳ Нужны данные: напишите причину</b>', $question['text']);
        $this->assertStringContainsString('Ответьте на это сообщение. Передумали — отправьте /cancel.', $question['text']);
        $this->assertSame(['force_reply' => true], json_decode($question['reply_markup'], true));

        // Typed into the chat without replying: nothing happens to the task, the bot explains.
        $this->say($this->assignee, 'привет, это не туда')->assertOk();
        $this->say($this->assignee, 'и это тоже', replyTo: 12345)->assertOk();
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame(0, $task->comments()->count());
        $this->assertStringContainsString('нажмите «Ответить» на моём вопросе', collect($this->botCalls('sendMessage'))->last()['text']);

        // Commands, forwarded and edited messages are never an answer either.
        $this->say($this->assignee, '/whatever', replyTo: $prompt)->assertOk();
        $this->say($this->assignee, 'переслано', replyTo: $prompt, extra: ['forward_origin' => ['type' => 'user']])->assertOk();
        $this->say($this->assignee, 'правка старого', replyTo: $prompt, edited: true)->assertOk();
        $this->assertSame(0, $task->comments()->count());

        $this->say($this->assignee, 'Нет доступа к выгрузке <b>звонков</b>', replyTo: $prompt)->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::AwaitingInitiator, $task->status);
        $comment = $task->comments()->sole();
        $this->assertSame($this->assignee->id, $comment->author_id);
        $this->assertStringContainsString('Нет доступа к выгрузке &lt;b&gt;звонков&lt;/b&gt;', $comment->body);
        $this->assertSame(80, collect($this->botCalls('editMessageText'))->last()['message_id']);
        $this->assertStringStartsWith('✅ <b>Статус изменён: В работе → Ожидает данных</b>', collect($this->botCalls('editMessageText'))->last()['text']);

        // The dialog is over: more text does nothing.
        $this->say($this->assignee, 'ещё текст', replyTo: $prompt)->assertOk();
        $this->assertSame(1, $task->comments()->count());
    }

    public function test_postponing_asks_for_the_reason_and_then_for_a_real_future_date(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress]);

        $this->press($this->assignee, 's:'.$task->id.':postponed')->assertOk();
        $this->say($this->assignee, 'Ждём клиента', replyTo: $this->lastMessageId())->assertOk();
        $this->assertStringContainsString('Когда задача вернётся в работу?', collect($this->botCalls('sendMessage'))->last()['text']);

        foreach (['скоро' => 'Не понял дату', '31.02' => 'Не понял дату', '11.11.2026' => 'позже сегодняшнего дня', '05.11.2026' => 'позже сегодняшнего дня'] as $input => $complaint) {
            $this->say($this->assignee, $input, replyTo: $this->lastMessageId())->assertOk();
            $this->assertStringContainsString($complaint, collect($this->botCalls('sendMessage'))->last()['text']);
            $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        }

        $this->say($this->assignee, '20.11', replyTo: $this->lastMessageId())->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::Postponed, $task->status);
        $this->assertSame('2026-11-20', $task->postponed_until->format('Y-m-d'));
        $this->assertStringContainsString('Ждём клиента', $task->comments()->sole()->body);
    }

    public function test_dates_are_read_strictly(): void
    {
        $this->assertSame('2026-11-18', TelegramActionService::parseDate('18.11'));
        $this->assertSame('2026-11-18', TelegramActionService::parseDate(' 18.11.2026 '));
        $this->assertSame('2026-11-18', TelegramActionService::parseDate('18.11.26'));
        $this->assertSame('2026-11-18', TelegramActionService::parseDate('2026-11-18'));
        $this->assertSame('2026-11-18', TelegramActionService::parseDate('18/11'));
        // No year: the nearest such day that is not behind us.
        $this->assertSame('2027-01-15', TelegramActionService::parseDate('15.01'));
        $this->assertSame('2026-11-11', TelegramActionService::parseDate('11.11'));

        foreach (['31.02', '32.01', '00.11', '18.13', 'завтра', '18', '18.11.2', '2026-13-01', ''] as $bad) {
            $this->assertNull(TelegramActionService::parseDate($bad), $bad);
        }
    }

    public function test_comment_button_publishes_the_reply_and_cancel_drops_the_question(): void
    {
        $task = $this->task();

        $this->press($this->assignee, 'c:'.$task->id)->assertOk();
        $this->say($this->assignee, '/cancel')->assertOk();
        $this->assertSame('Отменено.', collect($this->botCalls('sendMessage'))->last()['text']);
        $this->say($this->assignee, 'уже поздно', replyTo: $this->lastMessageId() - 1)->assertOk();
        $this->assertSame(0, $task->comments()->count());

        $this->press($this->assignee, 'c:'.$task->id)->assertOk();
        $this->say($this->assignee, "Сделал половину.\nОстальное завтра.", replyTo: $this->lastMessageId())->assertOk();

        $comment = $task->comments()->sole();
        $this->assertSame($this->assignee->id, $comment->author_id);
        $this->assertStringContainsString('Сделал половину.', $comment->body);
        $this->assertStringContainsString('💬 Комментарий добавлен в задачу', collect($this->botCalls('sendMessage'))->last()['text']);
        $this->assertSame('comment', AuditLog::query()->where('action', 'telegram.action')->sole()->new_values['action']);
    }

    public function test_an_expired_question_is_explained_and_never_publishes(): void
    {
        $task = $this->task();
        $this->press($this->assignee, 'c:'.$task->id)->assertOk();
        $prompt = $this->lastMessageId();

        $this->travel(16)->minutes();
        $this->say($this->assignee, 'Опоздал', replyTo: $prompt)->assertOk();

        $this->assertSame(0, $task->comments()->count());
        $this->assertSame('Время на ответ вышло. Нажмите кнопку ещё раз.', collect($this->botCalls('sendMessage'))->last()['text']);

        // And only once: after that plain messages are simply not the bot's business.
        $before = count($this->botCalls('sendMessage'));
        $this->say($this->assignee, 'Ещё раз')->assertOk();
        $this->assertCount($before, $this->botCalls('sendMessage'));
    }

    public function test_deadline_request_goes_to_the_initiator_and_moves_the_deadline_only_on_approval(): void
    {
        $task = $this->task(['deadline' => '2026-11-13 23:59:00']);

        $this->press($this->assignee, 'd:'.$task->id)->assertOk();
        $this->assertStringContainsString('Сейчас срок пт, 13.11. На какую дату перенести?', $this->botCalls('sendMessage')[0]['text']);
        $this->say($this->assignee, '13.11', replyTo: $this->lastMessageId())->assertOk();
        $this->assertStringContainsString('Это и есть текущий срок', collect($this->botCalls('sendMessage'))->last()['text']);
        $this->say($this->assignee, '18.11', replyTo: $this->lastMessageId())->assertOk();
        $this->say($this->assignee, 'Выгрузка придёт только в понедельник', replyTo: $this->lastMessageId())->assertOk();

        $request = DeadlineChangeRequest::query()->sole();
        $this->assertSame([$this->assignee->id, $this->initiator->id, 'pending'], [$request->requested_by, $request->approver_id, $request->status]);
        $this->assertSame('2026-11-13 23:59:00', $task->fresh()->deadline->toDateTimeString());
        // The request is visible in the tracker as the asker's comment.
        $this->assertStringContainsString('Прошу перенести срок с 13.11 на 18.11. Причина: Выгрузка придёт только в понедельник', $task->comments()->sole()->body);

        $toInitiator = collect($this->botCalls('sendMessage'))->firstWhere('chat_id', $this->initiator->telegram_chat_id);
        $this->assertStringStartsWith('🗓 <b>Просят перенести срок</b>', $toInitiator['text']);
        $this->assertStringContainsString('🎯 Assignee просит: <b>пт, 13.11 → ср, 18.11</b>', $toInitiator['text']);
        $this->assertStringContainsString('<blockquote>Выгрузка придёт только в понедельник</blockquote>', $toInitiator['text']);
        $this->assertSame([['✅ Согласовать', '❌ Отказать']], $this->buttonTexts($toInitiator));
        $this->assertStringContainsString('Initiator решит, переносить ли срок на ср, 18.11', collect($this->botCalls('sendMessage'))->last()['text']);

        // A second request while the first is open is refused.
        $this->press($this->assignee, 'd:'.$task->id)->assertOk();
        $this->assertSame('По этой задаче уже есть запрос на перенос срока — дождитесь ответа.', collect($this->botCalls('answerCallbackQuery'))->last()['text']);

        // Only the initiator decides.
        $this->press($this->assignee, 'a:'.$request->id)->assertOk();
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('Этот запрос адресован не вам.', collect($this->botCalls('answerCallbackQuery'))->last()['text']);

        $this->press($this->initiator, 'a:'.$request->id, messageId: 90)->assertOk();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('2026-11-18 23:59:00', $task->fresh()->deadline->toDateTimeString());
        $history = TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->sole();
        $this->assertSame($this->initiator->id, $history->changed_by);

        $edited = collect($this->botCalls('editMessageText'))->last();
        $this->assertSame(90, $edited['message_id']);
        $this->assertStringStartsWith('✅ <b>Срок перенесён: пт, 13.11 → ср, 18.11</b>', $edited['text']);
        // An empty keyboard is how the buttons are taken off a message.
        $this->assertSame(['inline_keyboard' => []], json_decode($edited['reply_markup'], true));
        $toAsker = collect($this->botCalls('sendMessage'))->last();
        $this->assertSame($this->assignee->telegram_chat_id, $toAsker['chat_id']);
        $this->assertStringContainsString('Согласовал(а) Initiator.', $toAsker['text']);

        // Pressing again changes nothing.
        $this->press($this->initiator, 'a:'.$request->id)->assertOk();
        $this->press($this->initiator, 'r:'.$request->id)->assertOk();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, TaskHistory::query()->where('task_id', $task->id)->where('field', 'deadline')->count());
    }

    public function test_declined_stale_and_unauthorised_requests_leave_the_deadline_alone(): void
    {
        $task = $this->task(['deadline' => '2026-11-13 23:59:00']);
        $ask = function () use ($task): DeadlineChangeRequest {
            $this->press($this->assignee, 'd:'.$task->id)->assertOk();
            $this->say($this->assignee, '18.11', replyTo: $this->lastMessageId())->assertOk();
            $this->say($this->assignee, 'Причина', replyTo: $this->lastMessageId())->assertOk();

            return DeadlineChangeRequest::query()->latest('id')->first();
        };

        // Declined.
        $declined = $ask();
        $this->press($this->initiator, 'r:'.$declined->id)->assertOk();
        $this->assertSame('rejected', $declined->fresh()->status);
        $this->assertSame('2026-11-13 23:59:00', $task->fresh()->deadline->toDateTimeString());
        $this->assertStringContainsString('Отказал(а) Initiator. Срок остаётся пт, 13.11.', collect($this->botCalls('sendMessage'))->last()['text']);

        // The initiator may not edit tasks: the request stays open, nothing is half-done.
        $noRight = $ask();
        $withoutEditing = array_values(array_diff($this->defaultPermissions(), [Permission::EditOwnTask->value, Permission::EditAnyTask->value]));
        $this->initiator->roles()->sync([$this->createRoleWithPermissions($withoutEditing, [$this->dept->id])->id]);
        $this->press($this->initiator, 'a:'.$noRight->id)->assertOk();
        $this->assertSame('pending', $noRight->fresh()->status);
        $this->assertSame('2026-11-13 23:59:00', $task->fresh()->deadline->toDateTimeString());
        $this->assertStringContainsString('нет права менять срок', collect($this->botCalls('answerCallbackQuery'))->last()['text']);
        $this->initiator->roles()->sync([$this->role->id]);

        // The deadline was changed in the tracker meanwhile: the request no longer applies.
        $task->update(['deadline' => '2026-11-16 23:59:00']);
        $this->press($this->initiator, 'a:'.$noRight->id)->assertOk();
        $this->assertSame('stale', $noRight->fresh()->status);
        $this->assertSame('2026-11-16 23:59:00', $task->fresh()->deadline->toDateTimeString());

        // A request that waited until its date had passed.
        $task->update(['deadline' => '2026-11-13 23:59:00']);
        $late = $ask();
        Carbon::setTestNow(Carbon::parse('2026-11-18 11:00:00', 'Europe/Kyiv'));
        $this->press($this->initiator, 'a:'.$late->id)->assertOk();
        $this->assertSame('stale', $late->fresh()->status);
        $this->assertSame('2026-11-13 23:59:00', $task->fresh()->deadline->toDateTimeString());
    }

    public function test_initiator_moves_their_own_deadline_at_once_and_an_initiator_without_telegram_is_asked_in_the_tracker(): void
    {
        $task = $this->task(['deadline' => '2026-11-13 23:59:00']);

        $this->press($this->initiator, 'd:'.$task->id)->assertOk();
        $this->say($this->initiator, '20.11', replyTo: $this->lastMessageId())->assertOk();
        $this->say($this->initiator, 'Перепланировали', replyTo: $this->lastMessageId())->assertOk();

        $this->assertSame('2026-11-20 23:59:00', $task->fresh()->deadline->toDateTimeString());
        $this->assertSame(0, DeadlineChangeRequest::query()->count());
        $this->assertStringContainsString('Срок перенесён с 13.11 на 20.11. Причина: Перепланировали', $task->comments()->sole()->body);

        $this->initiator->update(['telegram_chat_id' => null]);
        $this->press($this->assignee, 'd:'.$task->id)->assertOk();
        $this->say($this->assignee, '25.11', replyTo: $this->lastMessageId())->assertOk();
        $this->say($this->assignee, 'Не успеваю', replyTo: $this->lastMessageId())->assertOk();

        $this->assertSame('pending', DeadlineChangeRequest::query()->sole()->status);
        $this->assertStringContainsString('у Initiator не подключён Telegram', collect($this->botCalls('sendMessage'))->last()['text']);
        $this->assertSame(2, $task->comments()->count());
    }

    public function test_reminders_carry_buttons_that_match_the_persons_rights(): void
    {
        $task = $this->task(['status' => TaskStatus::New, 'title' => 'Single']);
        $row = fn (Task $t) => [
            'task_id' => $t->id, 'number' => $t->number, 'title' => $t->title, 'status' => $t->status->value, 'deadline' => '',
            'deadline_date' => '2026-11-12', 'days' => 1, 'watcher' => false, 'role' => 'assignee', 'responsible' => 'Assignee',
            'assignee' => 'Assignee', 'priority' => 5, 'kind' => 'threshold', 'marks' => [1], 'ref_date' => '2026-11-12',
        ];

        $single = (new TaskMorningReminderNotification('2026-11-11', [$row($task)]))->toTelegram($this->assignee)->toArray();
        $this->assertSame(
            [['▶️ Взять в работу'], ['💬 Комментарий', '🗓 Перенести срок'], ['⋯ Другой статус']],
            $this->buttonTexts($single),
        );
        $this->assertSame('s:'.$task->id.':in_progress', json_decode($single['reply_markup'], true)['inline_keyboard'][0][0]['callback_data']);

        // Somebody who cannot see the task gets the text without any buttons.
        $outsider = $this->person('Outsider', department: $this->createDepartment('Other'), role: $this->createRoleWithPermissions($this->defaultPermissions()));
        $this->assertArrayNotHasKey('reply_markup', (new TaskMorningReminderNotification('2026-11-11', [$row($task)]))->toTelegram($outsider)->toArray());

        $second = $this->task(['title' => 'Second']);
        $list = (new TaskMorningReminderNotification('2026-11-11', [$row($task), $row($second)]))->toTelegram($this->assignee)->toArray();
        $this->assertSame([['#'.$task->number, '#'.$second->number]], $this->buttonTexts($list));
        $this->assertStringContainsString('👇 Действия по задаче — нажмите её номер', $list['text']);
    }

    public function test_linking_is_private_short_lived_audited_and_warns_the_previous_chat(): void
    {
        $this->assertSame(15, TelegramLinkService::CODE_TTL_MINUTES);
        $links = app(TelegramLinkService::class);
        $oldChat = $this->assignee->telegram_chat_id;

        // A code typed in a group binds nothing.
        $code = $links->createLinkCode($this->assignee)->code;
        $this->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => -100555, 'type' => 'group'], 'from' => ['id' => 31337], 'text' => '/start '.$code]], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token'])->assertOk();
        $this->assertSame($oldChat, $this->assignee->fresh()->telegram_chat_id);

        // The same code from a private chat re-links the account…
        $this->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => 31337, 'type' => 'private'], 'from' => ['id' => 31337], 'text' => '/start '.$code]], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token'])->assertOk();
        $this->assertSame('31337', $this->assignee->fresh()->telegram_chat_id);

        // …which is written to the audit log, and the chat that held the account before is told.
        $audit = AuditLog::query()->where('action', 'telegram.linked')->sole();
        $this->assertSame(['chat' => '…1337', 'relinked' => true], $audit->new_values);
        $warning = collect($this->botCalls('sendMessage'))->firstWhere('chat_id', $oldChat);
        $this->assertStringContainsString('к нему привязали другой чат', $warning['text']);

        // The old chat can no longer act.
        $task = $this->task(['status' => TaskStatus::New]);
        $this->press(null, 's:'.$task->id.':in_progress', chatId: $oldChat)->assertOk();
        $this->assertSame(TaskStatus::New, $task->fresh()->status);
    }

    private function press(
        ?User $user,
        string $data,
        int $messageId = 50,
        string $chatType = 'private',
        ?string $chatId = null,
        ?string $fromId = null,
        ?string $secret = 'secret-token',
        ?string $callbackId = null,
    ) {
        $chatId ??= (string) $user?->telegram_chat_id;

        return $this->postJson('/telegram/webhook', [
            'update_id' => ++$this->update,
            'callback_query' => [
                'id' => $callbackId ?? 'cb-auto-'.$this->update,
                'from' => ['id' => (int) ($fromId ?? $chatId)],
                'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $chatId, 'type' => $chatType]],
                'data' => $data,
            ],
        ], $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    /** @param  array<string, mixed>  $extra */
    private function say(User $user, string $text, ?int $replyTo = null, bool $edited = false, ?int $updateId = null, array $extra = [])
    {
        $chatId = (int) $user->telegram_chat_id;

        return $this->postJson('/telegram/webhook', [
            'update_id' => $updateId ?? ++$this->update,
            $edited ? 'edited_message' : 'message' => array_filter([
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId],
                'text' => $text,
                'reply_to_message' => $replyTo === null ? null : ['message_id' => $replyTo],
            ]) + $extra,
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token']);
    }

    /**
     * Payloads of the bot calls of one method, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function botCalls(string $method): array
    {
        return Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/'.$method))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<list<string>>
     */
    private function buttonTexts(array $payload): array
    {
        $markup = json_decode($payload['reply_markup'] ?? '{}', true);

        return array_map(fn (array $row) => array_column($row, 'text'), $markup['inline_keyboard'] ?? []);
    }

    private function lastMessageId(): int
    {
        return $this->messageId;
    }

    private function person(string $name, ?Department $department = null, ?Role $role = null): User
    {
        $user = $this->createUserInDepartment($department ?? $this->dept, $name, role: $role ?? $this->role);
        $user->update(['telegram_chat_id' => (string) (700000 + $user->id)]);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(array $overrides = []): Task
    {
        return $this->createTask($this->initiator, $this->assignee, $this->category ??= $this->createCategory(), array_merge([
            'title' => 'Bot task',
            'status' => TaskStatus::InProgress,
            'deadline' => '2026-11-13 23:59:00',
        ], $overrides));
    }
}
