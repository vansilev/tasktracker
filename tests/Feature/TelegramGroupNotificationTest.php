<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Jobs\SendTelegramGroupMessage;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TelegramGroupNotificationTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private function enableGroup(): void
    {
        config([
            'services.telegram.group_enabled' => true,
            'services.telegram.token' => 'test-token',
            'services.telegram.group_chat_id' => '-1001970597297',
            'services.telegram.group_message_thread_id' => '11252',
            'services.telegram.dm_enabled' => false,
            'services.telegram.group_tag_assignee_on_comment' => true,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_create_task_posts_one_group_message_tagging_assignee_and_watchers(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $watcher = $this->createUserInDepartment($dept, 'Watcher', role: $role);
        $assignee->update(['telegram_chat_id' => '111']);
        $watcher->update(['telegram_chat_id' => '222']);
        $initiator->update(['telegram_chat_id' => '333']);

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $this->createCategory()->id,
                'title' => 'Pulse <test>',
                'description' => 'Проверить формат сообщения',
                'priority' => 5,
            ],
            [],
            [$watcher->id],
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return (string) $request['chat_id'] === '-1001970597297'
                && (int) $request['message_thread_id'] === 11252
                && $request['parse_mode'] === 'HTML'
                && str_contains($text, 'tg://user?id=111')
                && str_contains($text, 'tg://user?id=222')
                && ! str_contains($text, 'tg://user?id=333')
                && str_contains($text, 'Pulse &lt;test&gt;')
                && str_contains($text, 'на вас поставили эту задачу')
                && str_contains($text, '🆕')
                && str_contains($text, '📝 Проверить формат сообщения')
                && str_contains($text, '🟡 Приоритет: 5/10 — средний');
        });
    }

    public function test_urgent_priority_is_repeated_in_status_message(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::ChangeStatus->value],
        ));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator->update(['telegram_chat_id' => '333']);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::InProgress,
            'priority' => 10,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition($task, $assignee, TaskStatus::OnReview);

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, '🔍')
                && str_contains($text, '🔥 Приоритет: 10/10 — критический')
                && str_contains($text, 'нужно проверить результат');
        });
    }

    public function test_normal_priority_is_omitted_in_comment_message(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator->update(['telegram_chat_id' => '333']);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'priority' => 5,
        ]);

        app(TaskService::class)->addComment($task, $assignee, 'Готово');

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, '💬')
                && ! str_contains($text, 'Приоритет');
        });
    }

    public function test_group_disabled_does_not_post(): void
    {
        config([
            'services.telegram.group_enabled' => false,
            'services.telegram.token' => 'test-token',
            'services.telegram.group_chat_id' => '-1001970597297',
            'services.telegram.group_message_thread_id' => '11252',
        ]);
        Http::fake();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $this->createCategory()->id,
                'title' => 'Silent',
                'description' => 'desc',
                'priority' => 5,
            ],
        );

        Http::assertNothingSent();
    }

    public function test_missing_thread_id_does_not_post_to_general(): void
    {
        config([
            'services.telegram.group_enabled' => true,
            'services.telegram.token' => 'test-token',
            'services.telegram.group_chat_id' => '-1001970597297',
            'services.telegram.group_message_thread_id' => null,
        ]);
        Http::fake();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);

        app(TaskService::class)->create(
            $initiator,
            [
                'department_id' => $dept->id,
                'assignee_id' => $assignee->id,
                'category_id' => $this->createCategory()->id,
                'title' => 'No thread',
                'description' => 'desc',
                'priority' => 5,
            ],
        );

        Http::assertNothingSent();
    }

    public function test_comment_tags_initiator_and_assignee_not_actor(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator->update(['telegram_chat_id' => '333']);
        $assignee->update(['telegram_chat_id' => '111']);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        app(TaskService::class)->addComment($task, $assignee, 'Нужна ссылка на ТЗ');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'tg://user?id=333')
                && ! str_contains($text, 'tg://user?id=111')
                && str_contains($text, 'новый комментарий к вашей задаче')
                && str_contains($text, 'Нужна ссылка на ТЗ');
        });
    }

    public function test_mentioned_user_gets_mention_wording_not_watcher_wording(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $mentioned = $this->createUserInDepartment($dept, 'Third Person', role: $role);
        $mentioned->update(['telegram_chat_id' => '777']);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $token = strstr($mentioned->email, '@', true);

        app(TaskService::class)->addComment($task, $initiator, 'Глянь плиз @'.$token);

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'tg://user?id=777')
                && str_contains($text, 'вас упомянули в комментарии')
                && ! str_contains($text, 'вы в наблюдателях, в задаче новый комментарий');
        });
    }

    public function test_editing_comment_does_not_post_second_group_message(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory());

        $comment = app(TaskService::class)->addComment($task, $initiator, 'Первый');
        Http::assertSentCount(1);

        app(TaskService::class)->updateComment($comment, $initiator, 'Правка');
        Http::assertSentCount(1);
    }

    public function test_rework_tags_assignee_and_includes_reason(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::ChangeStatus->value],
        ));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $assignee->update(['telegram_chat_id' => '111']);
        $initiator->update(['telegram_chat_id' => '333']);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::OnReview,
            'result_url' => 'https://example.com/result',
        ]);

        app(TaskWorkflowService::class)->transition(
            $task,
            $initiator,
            TaskStatus::Rework,
            'Квизы не сохраняются',
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'На проверке → На доработку')
                && str_contains($text, 'tg://user?id=111')
                && ! str_contains($text, 'tg://user?id=333')
                && str_contains($text, 'задачу вернули на доработку')
                && str_contains($text, 'Квизы не сохраняются');
        });
    }

    public function test_awaiting_initiator_tags_initiator(): void
    {
        $this->enableGroup();

        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions(array_merge(
            $this->defaultPermissions(),
            [Permission::ChangeStatus->value],
        ));
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $initiator->update(['telegram_chat_id' => '333']);
        $assignee->update(['telegram_chat_id' => '111']);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'status' => TaskStatus::InProgress,
        ]);

        app(TaskWorkflowService::class)->transition(
            $task,
            $assignee,
            TaskStatus::AwaitingInitiator,
            'Нужен доступ',
        );

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'tg://user?id=333')
                && ! str_contains($text, 'tg://user?id=111')
                && str_contains($text, 'исполнитель ждёт данные');
        });
    }

    public function test_group_test_command_queues_message(): void
    {
        $this->enableGroup();
        Queue::fake();

        $this->artisan('telegram:group-test')->assertSuccessful();

        Queue::assertPushed(SendTelegramGroupMessage::class);
    }
}
