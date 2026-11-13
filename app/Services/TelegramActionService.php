<?php

namespace App\Services;

use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\CommentNotice;
use App\Models\DeadlineChangeRequest;
use App\Models\Task;
use App\Models\User;
use App\Support\CommentNoticeFormat;
use App\Support\Deadline;
use App\Support\TelegramAppLinks;
use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramTaskCard;
use App\Support\TelegramWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * What a person can do to a task from Telegram: change its status, write a comment, ask to
 * move the deadline, and approve or decline such a request. Task 448, stage 4.
 *
 * The bot never has rights of its own. The caller (the webhook) has already established who
 * the person is; here every action goes through the same services, gates and history as the
 * web interface, as that person. A button only suggests an action — the task is loaded again
 * through the person's visibility and the action is re-checked each time.
 *
 * Text is accepted only as a reply to the bot's own question, so a stray message typed into
 * the chat can never end up in a task.
 */
class TelegramActionService
{
    /** How long the bot waits for the answer to its question. */
    private const DIALOG_MINUTES = 15;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramTaskCard $card,
        private TaskWorkflowService $workflow,
        private TaskService $tasks,
        private TaskVisibilityService $visibility,
        private AuditLogService $audit,
    ) {}

    /**
     * A pressed button. Always answers the press, whatever happens.
     */
    public function press(User $user, string $data, string $callbackId, string $chatId, int $messageId): void
    {
        $toast = '';
        $alert = false;

        try {
            [$toast, $alert] = $this->route($user, $data, $chatId, $messageId);
        } catch (Throwable $e) {
            report($e);
            $toast = $this->t($user, 'not_available');
            $alert = true;
        }

        $this->bot->answerCallback($callbackId, $toast, $alert);
    }

    /**
     * A text message. Returns true when it was the answer to one of the bot's questions.
     *
     * @param  int|null  $replyTo  message id this text replies to, if any
     */
    public function text(User $user, string $text, string $chatId, ?int $replyTo): bool
    {
        // One answer at a time per person: two messages sent at once must not both be taken.
        $lock = Cache::lock('telegram-dialog-lock:'.$user->id, 15);

        if (! $lock->get()) {
            return true;
        }

        try {
            $dialog = Cache::get($this->dialogKey($user));

            if (! is_array($dialog)) {
                if (Cache::pull('telegram-dialog-recent:'.$user->id) !== null) {
                    $this->bot->sendMessage($chatId, e($this->t($user, 'expired')));

                    return true;
                }

                return false;
            }

            if ($replyTo === null || $replyTo !== (int) ($dialog['prompt_id'] ?? 0)) {
                // Not a reply to the question: nothing is published, the person is told how to answer.
                $this->bot->sendMessage($chatId, e($this->t($user, 'reply_hint')));

                return true;
            }

            $text = trim($text);

            if ($text === '') {
                $this->ask($user, $chatId, $dialog, e($this->t($user, 'empty_text')));

                return true;
            }

            $this->answer($user, $chatId, $dialog, $text);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** /cancel — drops the question the bot is waiting on. */
    public function cancel(User $user, string $chatId): void
    {
        if (Cache::pull($this->dialogKey($user)) !== null) {
            Cache::forget('telegram-dialog-recent:'.$user->id);
            $this->bot->sendMessage($chatId, e($this->t($user, 'cancelled')));
        }
    }

    /**
     * @return array{0: string, 1: bool} toast text and whether it is an alert
     */
    private function route(User $user, string $data, string $chatId, int $messageId): array
    {
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $id = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 0;

        if (in_array($action, ['a', 'r'], true)) {
            return $this->decide($user, $id, $action === 'a', $chatId, $messageId);
        }

        if (in_array($action, ['p', 'y'], true)) {
            return $this->pressNotice($user, $id, $action === 'y', $chatId, $messageId);
        }

        if (! in_array($action, ['o', 'k', 'm', 's', 'c', 'd'], true)) {
            return [$this->t($user, 'unknown'), true];
        }

        $task = $this->task($user, $id);

        if ($task === null) {
            return [$this->t($user, 'not_available'), true];
        }

        return match ($action) {
            // From a list: the list stays as it is, the card comes as a new message.
            'o' => $this->done(fn () => $this->bot->sendMessage($chatId, $this->card->text($task, $user), $this->card->keyboard($task, $user))),
            'k' => $this->done(fn () => $this->showCard($user, $task, $chatId, $messageId)),
            'm' => $this->done(fn () => $this->bot->editMessage(
                $chatId,
                $messageId,
                F::join([[
                    F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $user->preferredLocale()),
                    str_replace('%s', F::status($task->status->value, $user->preferredLocale()), e($this->t($user, 'choose_status', ['status' => '%s']))),
                ]]),
                $this->card->statusMenu($task, $user),
            )),
            's' => $this->pressStatus($user, $task, TaskStatus::tryFrom($parts[2] ?? ''), $chatId, $messageId),
            'c' => $this->pressComment($user, $task, $chatId, $messageId),
            'd' => $this->pressDeadline($user, $task, $chatId, $messageId),
        };
    }

    /**
     * Buttons under a comment or a mention: "Ответить" opens the comment question for that
     * task, "Принял" closes the mention. Both only for the person the message was sent to.
     *
     * @return array{0: string, 1: bool}
     */
    private function pressNotice(User $user, int $noticeId, bool $acknowledge, string $chatId, int $messageId): array
    {
        $notice = CommentNotice::query()->with(['task', 'comment.author', 'user'])->whereKey($noticeId)->where('user_id', $user->id)->first();
        $task = $notice === null ? null : $this->task($user, $notice->task_id);

        if ($notice === null || $task === null || $notice->comment === null) {
            return [__('notification.comments.notice_gone', [], $user->preferredLocale()), true];
        }

        if (! $acknowledge) {
            return $this->pressComment($user, $task, $chatId, $messageId);
        }

        $done = app(CommentNoticeService::class)->acknowledgeByButton($notice, $user);
        $notice->refresh();

        if ($notice->kind === CommentNotice::KIND_MENTION && $notice->acknowledged_at !== null) {
            // The buttons go, the message keeps the question and shows when it was acknowledged.
            $format = app(CommentNoticeFormat::class);
            $locale = $user->preferredLocale();
            // On a widget the caption says so instead; built only for those who get widgets.
            $widgetCaption = app(ReminderSettings::class)->widgetsFor($user->id)
                ? TelegramWidget::caption($format->mentionWidget($notice, $locale)[1])."\n<b>👍 ".e(__('notification.widget.ack_caption', ['time' => $notice->acknowledged_at->copy()->timezone(config('app.timezone'))->format('H:i')], $locale)).'</b>'
                : null;
            $this->bot->editMessage($chatId, $messageId, $format->mention($notice, $locale, $notice->acknowledged_at), null, $widgetCaption);
        }

        if ($done) {
            $this->auditAction($user, $task, 'mention_acknowledged');
        }

        return [__('notification.comments.'.($done ? 'ack_done' : 'ack_already'), [], $user->preferredLocale()), false];
    }

    /** @return array{0: string, 1: bool} */
    private function pressStatus(User $user, Task $task, ?TaskStatus $to, string $chatId, int $messageId): array
    {
        if ($to === null) {
            return [$this->t($user, 'unknown'), true];
        }

        if (! in_array($to, $this->workflow->allowedTransitions($user, $task), true)) {
            return $this->alreadyChanged($user, $task, $chatId, $messageId);
        }

        if (TaskStatus::requiresComment($to, $task->status)) {
            $locale = $user->preferredLocale();

            $this->ask($user, $chatId, [
                'action' => 'status',
                'step' => 'reason',
                'task_id' => $task->id,
                'status' => $to->value,
                'card_id' => $messageId,
            ], F::join([
                [
                    '<b>'.e($this->t($user, 'ask_reason_title', ['status' => $this->card->transitionLabel($task->status, $to, $locale)])).'</b>',
                    F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $locale),
                ],
                e($this->t($user, 'ask_reason')),
            ]));

            return ['', false];
        }

        return $this->changeStatus($user, $task, $to, null, null, $chatId, $messageId);
    }

    /**
     * The status change itself. A per-task lock and a fresh read make a double tap one change.
     *
     * @return array{0: string, 1: bool}
     */
    private function changeStatus(User $user, Task $task, TaskStatus $to, ?string $reason, ?string $postponedUntil, string $chatId, ?int $cardId): array
    {
        $lock = Cache::lock('telegram-task-lock:'.$task->id, 15);

        if (! $lock->get()) {
            return ['', false];
        }

        try {
            $task = $this->task($user, $task->id);

            if ($task === null) {
                return [$this->t($user, 'not_available'), true];
            }

            $from = $task->status;

            try {
                $this->workflow->transition($task, $user, $to, $reason, ContentSource::PlainText, $postponedUntil);
            } catch (InvalidArgumentException|AuthorizationException) {
                return $this->alreadyChanged($user, $task, $chatId, $cardId);
            }

            $task->refresh();
            $this->auditAction($user, $task, 'status', ['from' => $from->value, 'to' => $to->value]);

            $locale = $user->preferredLocale();
            $line = str_replace(
                ['%1', '%2'],
                [e(__('task.status.'.$from->value, [], $locale)), e(__('task.status.'.$to->value, [], $locale))],
                e($this->t($user, 'status_changed', ['old' => '%1', 'new' => '%2'])),
            );

            $this->showCard($user, $task, $chatId, $cardId, '✅ <b>'.$line.'</b>');

            return [strip_tags(html_entity_decode($line)), false];
        } finally {
            $lock->release();
        }
    }

    /** @return array{0: string, 1: bool} */
    private function alreadyChanged(User $user, Task $task, string $chatId, ?int $cardId): array
    {
        $task->refresh();
        $this->showCard($user, $task, $chatId, $cardId);

        return [$this->t($user, 'already_changed', ['status' => '«'.__('task.status.'.$task->status->value, [], $user->preferredLocale()).'»']), true];
    }

    /** @return array{0: string, 1: bool} */
    private function pressComment(User $user, Task $task, string $chatId, int $messageId): array
    {
        if (! Gate::forUser($user)->allows('comment', $task)) {
            return [$this->t($user, 'not_available'), true];
        }

        $this->ask($user, $chatId, ['action' => 'comment', 'task_id' => $task->id, 'card_id' => $messageId], F::join([
            [
                '💬 <b>'.e($this->t($user, 'ask_comment_title')).'</b>',
                F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $user->preferredLocale()),
            ],
            e($this->t($user, 'ask_comment')),
        ]));

        return ['', false];
    }

    /** @return array{0: string, 1: bool} */
    private function pressDeadline(User $user, Task $task, string $chatId, int $messageId): array
    {
        if (! $this->card->canAskToMoveDeadline($task, $user)) {
            return [$this->t($user, 'not_available'), true];
        }

        if ($this->pendingRequest($task) !== null) {
            return [$this->t($user, 'request_exists'), true];
        }

        $locale = $user->preferredLocale();

        $this->ask($user, $chatId, ['action' => 'deadline', 'step' => 'date', 'task_id' => $task->id, 'card_id' => $messageId], F::join([
            [
                '🗓 <b>'.e($this->t($user, 'ask_deadline_title')).'</b>',
                F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $locale),
            ],
            e($this->t($user, 'ask_deadline', ['date' => F::weekdayDate($this->deadlineDate($task), $locale)])),
        ]));

        return ['', false];
    }

    /**
     * The person answered the bot's question.
     *
     * @param  array<string, mixed>  $dialog
     */
    private function answer(User $user, string $chatId, array $dialog, string $text): void
    {
        $task = $this->task($user, (int) $dialog['task_id']);

        if ($task === null) {
            $this->endDialog($user);
            $this->bot->sendMessage($chatId, e($this->t($user, 'not_available')));

            return;
        }

        $locale = $user->preferredLocale();
        $cardId = isset($dialog['card_id']) ? (int) $dialog['card_id'] : null;

        if ($dialog['action'] === 'comment') {
            $this->endDialog($user);

            try {
                $this->tasks->addComment($task, $user, $text, ContentSource::PlainText);
            } catch (AuthorizationException) {
                $this->bot->sendMessage($chatId, e($this->t($user, 'not_available')));

                return;
            }

            $this->auditAction($user, $task, 'comment');
            $this->bot->sendMessage($chatId, '💬 '.e($this->t($user, 'comment_added')).' '.F::taskLink($task->id, $task->number, (string) $task->title, $locale, bold: false));

            return;
        }

        if ($dialog['action'] === 'status') {
            $to = TaskStatus::from($dialog['status']);

            if ($dialog['step'] === 'reason' && $to === TaskStatus::Postponed) {
                $this->ask($user, $chatId, ['step' => 'date', 'reason' => $text] + $dialog, e($this->t($user, 'ask_return_date')));

                return;
            }

            $reason = $dialog['step'] === 'date' ? (string) $dialog['reason'] : $text;
            $date = null;

            if ($dialog['step'] === 'date') {
                $date = $this->futureDate($user, $chatId, $dialog, $text);

                if ($date === null) {
                    return;
                }
            }

            $this->endDialog($user);
            [$toast] = $this->changeStatus($user, $task, $to, $reason, $date, $chatId, $cardId);

            if ($toast !== '') {
                $this->bot->sendMessage($chatId, e($toast));
            }

            return;
        }

        if ($dialog['action'] === 'deadline') {
            if ($dialog['step'] === 'date') {
                $date = $this->futureDate($user, $chatId, $dialog, $text);

                if ($date === null) {
                    return;
                }

                if ($date === $this->deadlineDate($task)) {
                    $this->ask($user, $chatId, $dialog, e($this->t($user, 'date_same')));

                    return;
                }

                $this->ask($user, $chatId, ['step' => 'reason', 'date' => $date] + $dialog, e($this->t($user, 'ask_deadline_reason', [
                    'date' => F::weekdayDate($date, $locale),
                ])));

                return;
            }

            $this->endDialog($user);
            $this->requestDeadline($user, $task, (string) $dialog['date'], $text, $chatId);
        }
    }

    /**
     * Asking to move the deadline. The request is also written into the task as the person's
     * comment, so it is visible in the tracker whether or not the initiator uses Telegram.
     */
    private function requestDeadline(User $user, Task $task, string $date, string $reason, string $chatId): void
    {
        $locale = $user->preferredLocale();
        $old = $this->deadlineDate($task);

        // The initiator needs nobody's consent: the deadline moves at once, with their own rights.
        if ($task->initiator_id === $user->id || $user->isAdmin()) {
            try {
                $this->tasks->update($task, $user, ['deadline' => $date]);
            } catch (AuthorizationException) {
                $this->bot->sendMessage($chatId, e($this->t($user, 'no_right_to_move')));

                return;
            }

            $this->tasks->addComment($task, $user, $this->t($user, 'deadline_moved_comment', [
                'old' => F::shortDate($old), 'new' => F::shortDate($date), 'reason' => $reason,
            ]), ContentSource::PlainText);
            $this->auditAction($user, $task, 'deadline', ['from' => $old, 'to' => $date]);
            $this->bot->sendMessage($chatId, '✅ <b>'.e($this->t($user, 'deadline_moved', [
                'old' => F::weekdayDate($old, $locale), 'new' => F::weekdayDate($date, $locale),
            ])).'</b>'."\n".F::taskLink($task->id, $task->number, (string) $task->title, $locale));

            return;
        }

        $approver = $task->initiator;

        if ($approver === null || ! $approver->is_active) {
            $this->bot->sendMessage($chatId, e($this->t($user, 'not_available')));

            return;
        }

        $request = DB::transaction(function () use ($task, $user, $approver, $date, $reason) {
            // One open request per task: a second one would only confuse the initiator.
            if (DeadlineChangeRequest::query()->where('task_id', $task->id)->where('status', DeadlineChangeRequest::STATUS_PENDING)->lockForUpdate()->exists()) {
                return null;
            }

            return DeadlineChangeRequest::query()->create([
                'task_id' => $task->id,
                'requested_by' => $user->id,
                'approver_id' => $approver->id,
                'old_deadline' => $task->deadline,
                'new_deadline' => $date,
                'reason' => $reason,
                'status' => DeadlineChangeRequest::STATUS_PENDING,
            ]);
        });

        if ($request === null) {
            $this->bot->sendMessage($chatId, e($this->t($user, 'request_exists')));

            return;
        }

        $this->tasks->addComment($task, $user, __('notification.bot.deadline_request_comment', [
            'old' => F::shortDate($old), 'new' => F::shortDate($date), 'reason' => $reason,
        ], $approver->preferredLocale()), ContentSource::PlainText);
        $this->auditAction($user, $task, 'deadline_request', ['to' => $date]);

        if (! filled($approver->telegram_chat_id) || ! app(ReminderSettings::class)->allowsPersonalMessages($approver->id)) {
            $this->bot->sendMessage($chatId, e($this->t($user, 'deadline_request_no_telegram', ['name' => $approver->name])));

            return;
        }

        $approverLocale = $approver->preferredLocale();
        $b = fn (string $key, array $replace = []) => __('notification.bot.'.$key, $replace, $approverLocale);

        $requestText = F::join([
            [
                '🗓 <b>'.e($b('deadline_request_title')).'</b>',
                F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $approverLocale),
            ],
            [
                '🎯 '.str_replace(['%1', '%2'], ['<s>'.e(F::weekdayDate($old, $approverLocale)).'</s>', '<b>'.e(F::weekdayDate($date, $approverLocale)).'</b>'], e($b('deadline_request_line', [
                    'name' => $user->name, 'old' => '%1', 'new' => '%2',
                ]))),
                '💬 '.e($b('reason')),
                '<blockquote>'.e(mb_strimwidth($reason, 0, 600, '…')).'</blockquote>',
            ],
            e($b('deadline_request_note')),
            F::link(route('tasks.show', $task), __('notification.open_task', [], $approverLocale)),
        ]);
        $requestKeyboard = [
            [
                ['text' => $b('approve'), 'callback_data' => 'a:'.$request->id, 'style' => 'success'],
                ['text' => $b('reject'), 'callback_data' => 'r:'.$request->id, 'style' => 'danger'],
            ],
            TelegramTaskCard::linkRow($task, $approverLocale),
        ];

        if (app(ReminderSettings::class)->widgetsFor($approver->id)) {
            $line = str_replace(['%1', '%2'], ['<s>'.e(F::weekdayDate($old, $approverLocale)).'</s>', '<b>'.e(F::weekdayDate($date, $approverLocale)).'</b>'], e($b('deadline_request_line', [
                'name' => $user->name, 'old' => '%1', 'new' => '%2',
            ])));
            $html = TelegramWidget::chips([['🗓 '.$b('deadline_request_title'), 'bl']])
                .TelegramWidget::title($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()))
                .TelegramWidget::meta(['🎯 '.$line])
                .TelegramWidget::box($b('reason'), mb_strimwidth($reason, 0, 600, '…'))
                .TelegramWidget::todo('👉 '.e($b('deadline_request_note')))
                .TelegramWidget::linkButtons($task->id, $task->number, $approverLocale);

            $this->bot->sendWidget(
                (string) $approver->telegram_chat_id,
                TelegramWidget::document($html, $approverLocale),
                TelegramWidget::caption('🗓 '.$user->name.': #'.$task->number.' '.F::weekdayDate($old, $approverLocale).' → '.F::weekdayDate($date, $approverLocale)),
                TelegramAppLinks::widgetKeyboard($requestKeyboard),
                $requestText,
                $requestKeyboard,
            );
        } else {
            $this->bot->sendMessage((string) $approver->telegram_chat_id, $requestText, $requestKeyboard);
        }

        $this->bot->sendMessage($chatId, e($this->t($user, 'deadline_request_sent', [
            'name' => $approver->name, 'date' => F::weekdayDate($date, $locale),
        ])));
    }

    /**
     * The initiator approves or declines a request. Claiming it, moving the deadline and the
     * history all happen in one transaction: a request is never "approved" with the deadline
     * left where it was.
     *
     * @return array{0: string, 1: bool}
     */
    private function decide(User $user, int $requestId, bool $approve, string $chatId, int $messageId, ?string $reason = null): array
    {
        $outcome = DB::transaction(function () use ($user, $requestId, $approve): array {
            $request = DeadlineChangeRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null || $request->approver_id !== $user->id) {
                return ['not_your_request', null, null];
            }

            if ($request->status !== DeadlineChangeRequest::STATUS_PENDING) {
                return ['request_closed', $request, null];
            }

            $task = $this->task($user, $request->task_id);

            if ($task === null) {
                return ['not_available', $request, null];
            }

            // The request was about a particular deadline. If that has changed, or the task is no
            // longer the initiator's to decide, nothing is applied.
            $stale = $task->initiator_id !== $user->id
                || ! $task->status->isOpen()
                || $task->deadline === null
                || $request->old_deadline === null
                || ! $task->deadline->equalTo($request->old_deadline);

            if ($stale) {
                $request->update(['status' => DeadlineChangeRequest::STATUS_STALE, 'decided_at' => now()]);

                return ['request_stale', $request, $task];
            }

            if (! $approve) {
                $request->update(['status' => DeadlineChangeRequest::STATUS_REJECTED, 'decided_at' => now()]);

                return ['rejected', $request, $task];
            }

            if ($request->new_deadline->format('Y-m-d') <= now()->timezone(config('app.timezone'))->format('Y-m-d')) {
                $request->update(['status' => DeadlineChangeRequest::STATUS_STALE, 'decided_at' => now()]);

                return ['request_stale', $request, $task];
            }

            // Same right as in the web interface. Without it the request stays open, untouched.
            if (! Gate::forUser($user)->allows('update', $task)) {
                return ['no_right_to_move', $request, $task];
            }

            $this->tasks->update($task, $user, ['deadline' => $request->new_deadline->format('Y-m-d')]);
            $request->update(['status' => DeadlineChangeRequest::STATUS_APPROVED, 'decided_at' => now()]);

            return ['approved', $request, $task->fresh()];
        });

        [$result, $request, $task] = $outcome;
        $locale = $user->preferredLocale();

        if (! in_array($result, ['approved', 'rejected'], true)) {
            if ($messageId > 0 && in_array($result, ['request_closed', 'request_stale'], true)) {
                // The buttons are of no use any more.
                $this->bot->editMessage($chatId, $messageId, e($this->t($user, $result)));
            }

            return [$this->t($user, $result), true];
        }

        $old = $request->old_deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d');
        $new = $request->new_deadline->format('Y-m-d');
        $this->auditAction($user, $task, $approve ? 'deadline_approved' : 'deadline_rejected', ['from' => $old, 'to' => $new]);

        if (! $approve && $reason !== null) {
            // The reason stays in the task, so it is seen in the tracker too.
            $this->tasks->addComment($task, $user, __('notification.bot.deadline_rejected_comment', [
                'old' => F::shortDate($old), 'new' => F::shortDate($new), 'reason' => $reason,
            ], $request->requester?->preferredLocale() ?? $user->preferredLocale()), ContentSource::PlainText);
        }

        $message = fn (User $reader): string => $this->decisionMessage($reader, $user, $task, $old, $new, $approve, $reason);

        if ($messageId > 0) {
            $this->bot->editMessage($chatId, $messageId, $message($user));
        } else {
            $this->bot->sendMessage($chatId, $message($user));
        }

        $requester = $request->requester;

        if (
            $requester !== null && $requester->is_active && filled($requester->telegram_chat_id) && $requester->id !== $user->id
            && app(ReminderSettings::class)->allowsPersonalMessages($requester->id)
        ) {
            $this->bot->sendMessage((string) $requester->telegram_chat_id, $message($requester));
        }

        return [$this->t($user, $approve ? 'approved_toast' : 'rejected_toast'), false];
    }

    private function decisionMessage(User $reader, User $decider, Task $task, string $old, string $new, bool $approved, ?string $reason = null): string
    {
        $locale = $reader->preferredLocale();
        $b = fn (string $key, array $replace = []) => __('notification.bot.'.$key, $replace, $locale);

        return F::join([
            [
                $approved
                    ? '✅ <b>'.e($b('deadline_moved', ['old' => F::weekdayDate($old, $locale), 'new' => F::weekdayDate($new, $locale)])).'</b>'
                    : '❌ <b>'.e($b('rejected_title')).'</b>',
                F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $locale),
            ],
            e($approved
                ? $b('approved_by', ['name' => $decider->name])
                : $b('rejected_by', ['name' => $decider->name, 'date' => F::weekdayDate($old, $locale)])),
            $reason === null ? null : '💬 '.e($b('reason')).'<blockquote>'.e(mb_strimwidth($reason, 0, 600, '…')).'</blockquote>',
            F::link(route('tasks.show', $task), __('notification.open_task', [], $locale)),
        ]);
    }

    /**
     * Sends the bot's question and remembers what it is waiting for. The question opens the
     * reply box on the person's side (force reply); only a reply to it is taken as the answer.
     *
     * @param  array<string, mixed>  $dialog
     */
    private function ask(User $user, string $chatId, array $dialog, string $html): void
    {
        $promptId = $this->bot->sendPrompt($chatId, $html."\n\n".'<i>'.e($this->t($user, 'cancel_hint')).'</i>');

        if ($promptId === null) {
            $this->endDialog($user);

            return;
        }

        Cache::put($this->dialogKey($user), ['prompt_id' => $promptId] + $dialog, now()->addMinutes(self::DIALOG_MINUTES));
        // Outlives the dialog: lets the bot say "time is up" instead of staying silent.
        Cache::put('telegram-dialog-recent:'.$user->id, 1, now()->addHour());
    }

    /**
     * Reads a date typed by a person; on a bad one asks again and returns null.
     *
     * @param  array<string, mixed>  $dialog
     */
    private function futureDate(User $user, string $chatId, array $dialog, string $text): ?string
    {
        $date = self::parseDate($text);

        if ($date === null) {
            $this->ask($user, $chatId, $dialog, e($this->t($user, 'bad_date')));

            return null;
        }

        if ($date <= now()->timezone(config('app.timezone'))->format('Y-m-d')) {
            $this->ask($user, $chatId, $dialog, e($this->t($user, 'date_not_future')));

            return null;
        }

        return $date;
    }

    // ─── The window inside Telegram (Mini App) ────────────────────────────────────────────
    // The same actions as the bot's questions, with everything typed at once. Each returns
    // [done, what to tell the person]; every check of the button flow is made again here.

    /** @return array{0: bool, 1: string} */
    public function appComment(User $user, int $taskId, string $text): array
    {
        $task = $this->task($user, $taskId);
        $text = trim($text);

        if ($task === null || ! Gate::forUser($user)->allows('comment', $task)) {
            return [false, $this->t($user, 'not_available')];
        }

        if ($text === '') {
            return [false, $this->t($user, 'empty_text')];
        }

        try {
            $this->tasks->addComment($task, $user, $text, ContentSource::PlainText);
        } catch (AuthorizationException) {
            return [false, $this->t($user, 'not_available')];
        }

        $this->auditAction($user, $task, 'comment', ['via' => 'app']);

        return [true, $this->t($user, 'comment_added').' #'.$task->number];
    }

    /** @return array{0: bool, 1: string} */
    public function appStatus(User $user, int $taskId, ?TaskStatus $to, ?string $reason, ?string $date, string $chatId): array
    {
        $task = $this->task($user, $taskId);

        if ($task === null || $to === null) {
            return [false, $this->t($user, 'not_available')];
        }

        if (! in_array($to, $this->workflow->allowedTransitions($user, $task), true)) {
            return [false, $this->t($user, 'already_changed', ['status' => '«'.__('task.status.'.$task->status->value, [], $user->preferredLocale()).'»'])];
        }

        $reason = trim((string) $reason) ?: null;

        if (TaskStatus::requiresComment($to, $task->status) && $reason === null) {
            return [false, $this->t($user, 'app_reason_required')];
        }

        if ($to === TaskStatus::Postponed) {
            $date = $date === null ? null : self::parseDate($date);

            if ($date === null || $date <= now()->timezone(config('app.timezone'))->format('Y-m-d')) {
                return [false, $this->t($user, 'date_not_future')];
            }
        } else {
            $date = null;
        }

        [$toast, $failed] = $this->changeStatus($user, $task, $to, $reason, $date, $chatId, null);

        // An empty answer means somebody is changing this task right now.
        return [! $failed && $toast !== '', $toast ?: $this->t($user, 'app_already_sent')];
    }

    /** @return array{0: bool, 1: string} */
    public function appDeadline(User $user, int $taskId, string $date, string $reason, string $chatId): array
    {
        $task = $this->task($user, $taskId);
        $reason = trim($reason);

        if ($task === null || ! $this->card->canAskToMoveDeadline($task, $user)) {
            return [false, $this->t($user, 'not_available')];
        }

        if ($this->pendingRequest($task) !== null && $task->initiator_id !== $user->id && ! $user->isAdmin()) {
            return [false, $this->t($user, 'request_exists')];
        }

        $date = self::parseDate($date);

        if ($date === null || $date <= now()->timezone(config('app.timezone'))->format('Y-m-d')) {
            return [false, $this->t($user, 'date_not_future')];
        }

        if ($date === $this->deadlineDate($task)) {
            return [false, $this->t($user, 'date_same')];
        }

        if ($reason === '') {
            return [false, $this->t($user, 'app_reason_required')];
        }

        $this->requestDeadline($user, $task, $date, $reason, $chatId);

        return [true, ''];
    }

    /** @return array{0: bool, 1: string} */
    public function appReject(User $user, int $requestId, string $reason, string $chatId): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            return [false, $this->t($user, 'app_reason_required')];
        }

        [$toast, $alert] = $this->decide($user, $requestId, false, $chatId, 0, $reason);

        return [! $alert, $toast];
    }

    /**
     * What the window shows before the person types: the task, and the question, the request
     * or the statuses the chosen action is about. Null when it is not this person's to see.
     *
     * @return array<string, mixed>|null
     */
    public function appContext(User $user, string $mode, array $ids): ?array
    {
        $locale = $user->preferredLocale();
        $quote = null;
        $request = null;

        if ($mode === 'reply') {
            $notice = CommentNotice::query()->with(['comment.author'])->whereKey((int) ($ids['notice'] ?? 0))->where('user_id', $user->id)->first();

            if ($notice === null || $notice->comment === null) {
                return null;
            }

            $ids['task'] = $notice->task_id;
            $quote = [
                'author' => ($notice->comment->author?->name ?? '—').' · '.$notice->comment->created_at->copy()->timezone(config('app.timezone'))->format('d.m H:i'),
                'text' => app(CommentNoticeFormat::class)->text($notice->comment, 700),
            ];
        }

        if ($mode === 'reject') {
            $request = DeadlineChangeRequest::query()->with('requester')->whereKey((int) ($ids['req'] ?? 0))->where('approver_id', $user->id)->first();

            if ($request === null || $request->old_deadline === null || $request->new_deadline === null) {
                return null;
            }

            $ids['task'] = $request->task_id;
        }

        $task = $this->task($user, (int) ($ids['task'] ?? 0));

        if ($task === null) {
            return null;
        }

        $statuses = [];

        if ($mode === 'status') {
            foreach ($this->workflow->allowedTransitions($user, $task) as $to) {
                $statuses[] = [
                    'value' => $to->value,
                    'label' => $this->card->transitionLabel($task->status, $to, $locale),
                    'reason' => TaskStatus::requiresComment($to, $task->status),
                    'date' => $to === TaskStatus::Postponed,
                    'danger' => in_array($to, [TaskStatus::Rejected, TaskStatus::Cancelled], true),
                ];
            }
        }

        return [
            'task' => [
                'id' => $task->id,
                'number' => $task->number,
                'title' => (string) ($task->title ?: $task->plainDescription()),
                'status' => __('task.status.'.$task->status->value, [], $locale),
                'assignee' => $task->assignee?->name,
                'initiator' => $task->initiator?->name,
                'deadline' => $task->deadline === null ? null : F::weekdayDate($this->deadlineDate($task), $locale),
                'deadline_iso' => $task->deadline === null ? null : $this->deadlineDate($task),
                'url' => route('tasks.show', $task),
            ],
            'quote' => $quote,
            'request' => $request === null ? null : [
                'id' => $request->id,
                'requester' => $request->requester?->name,
                'old' => F::weekdayDate($request->old_deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d'), $locale),
                'new' => F::weekdayDate($request->new_deadline->format('Y-m-d'), $locale),
                'reason' => $request->reason,
                'open' => $request->status === DeadlineChangeRequest::STATUS_PENDING,
            ],
            'statuses' => $statuses,
            'can_comment' => Gate::forUser($user)->allows('comment', $task),
            'can_move_deadline' => $this->card->canAskToMoveDeadline($task, $user),
            'moves_itself' => $task->initiator_id === $user->id || $user->isAdmin(),
            'today' => now()->timezone(config('app.timezone'))->format('Y-m-d'),
        ];
    }

    /**
     * "18.11", "18.11.26", "18.11.2026" or "2026-11-18" → Y-m-d; null when it is not a real date.
     * Without a year it is the nearest such day that is not in the past.
     */
    public static function parseDate(string $text): ?string
    {
        $text = trim($text);
        $today = now()->timezone(config('app.timezone'));

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2}|\d{4}))?\.?$/', $text, $m) === 1) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
            $year = isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]) : (int) $today->format('Y');

            if (! isset($m[3]) && checkdate($month, $day, $year) && sprintf('%04d-%02d-%02d', $year, $month, $day) < $today->format('Y-m-d')) {
                $year++;
            }
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function showCard(User $user, Task $task, string $chatId, ?int $messageId, ?string $headline = null): void
    {
        $text = $this->card->text($task, $user, $headline);
        $keyboard = $this->card->keyboard($task, $user);

        // A message too old to edit (or already gone) is replaced by a new one.
        if ($messageId === null || ! $this->bot->editMessage($chatId, $messageId, $text, $keyboard)) {
            $this->bot->sendMessage($chatId, $text, $keyboard);
        }
    }

    /** The task as this person may see it — or nothing. */
    private function task(User $user, int $id): ?Task
    {
        if ($id < 1) {
            return null;
        }

        return $this->visibility->accessibleQuery($user)->with(['assignee', 'initiator'])->whereKey($id)->first();
    }

    private function pendingRequest(Task $task): ?DeadlineChangeRequest
    {
        return DeadlineChangeRequest::query()->where('task_id', $task->id)->where('status', DeadlineChangeRequest::STATUS_PENDING)->first();
    }

    /** Marks in the audit log that the action came through the bot, and from which chat. */
    private function auditAction(User $user, Task $task, string $action, array $details = []): void
    {
        $this->audit->log('telegram.action', $user, $task, null, [
            'action' => $action,
            'chat' => '…'.substr((string) $user->telegram_chat_id, -4),
        ] + $details);
    }

    private function deadlineDate(Task $task): string
    {
        return $task->deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d');
    }

    private function endDialog(User $user): void
    {
        Cache::forget($this->dialogKey($user));
        Cache::forget('telegram-dialog-recent:'.$user->id);
    }

    private function dialogKey(User $user): string
    {
        return 'telegram-dialog:'.$user->id;
    }

    /**
     * @param  callable(): mixed  $work
     * @return array{0: string, 1: bool}
     */
    private function done(callable $work): array
    {
        $work();

        return ['', false];
    }

    /** @param  array<string, mixed>  $replace */
    private function t(User $user, string $key, array $replace = []): string
    {
        return __('notification.bot.'.$key, $replace, $user->preferredLocale());
    }
}
