<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflowService;
use App\Support\TelegramReminderFormat as F;
use Illuminate\Support\Facades\Gate;

/**
 * A task as a Telegram message with action buttons: what the bot shows after a press and
 * under a single-task reminder. Buttons are only an invitation — every press is checked
 * again on the server against the person's real rights.
 *
 * callback_data (64 bytes at most): o:{task} open the card · k:{task} back to the card ·
 * m:{task} status menu · s:{task}:{status} change status · c:{task} comment ·
 * d:{task} ask to move the deadline · a:{request} / r:{request} approve / decline it.
 *
 * Colours (Telegram's button "style"): blue for the step the person is expected to take, green
 * for agreeing, red for what cancels or refuses. An app that does not know them shows plain buttons.
 */
class TelegramTaskCard
{
    /** The step people take most often from a status; the rest sits behind "Other status". */
    private const MAIN_STEPS = [TaskStatus::InProgress, TaskStatus::OnReview, TaskStatus::Completed];

    public function __construct(private TaskWorkflowService $workflow) {}

    public function text(Task $task, User $user, ?string $headline = null, array $extra = []): string
    {
        $locale = $user->preferredLocale();
        $t = fn (string $key, array $replace = []) => __('notification.bot.'.$key, $replace, $locale);

        $who = match (true) {
            $task->assignee_id === $user->id => '🎯 '.e($t('you_assignee')),
            $task->initiator_id === $user->id => '🙋 '.e($t('you_initiator')),
            default => '👀 '.e($t('you_watcher')),
        };

        $text = F::join([
            [
                $headline,
                F::taskLink($task->id, $task->number, (string) ($task->title ?: $task->plainDescription()), $locale),
            ],
            [
                $headline === null ? e($t('status_now', ['status' => '%s'])) : null,
                $who,
                $task->assignee_id !== $user->id && $task->assignee !== null
                    ? '🎯 '.e($t('assignee_is', ['name' => $task->assignee->name]))
                    : null,
                '🗓 '.e($task->deadline === null
                    ? $t('no_deadline')
                    : $t('deadline', ['date' => F::weekdayDate($this->deadlineDate($task), $locale)])),
                F::priorityLine(max(1, min(10, (int) $task->priority)), $locale),
            ],
            $extra,
            F::link(route('tasks.show', $task), __('notification.open_task', [], $locale)),
        ]);

        // The status label carries quotes of its own, so it goes in after escaping.
        return str_replace('%s', F::status($task->status->value, $locale), $text);
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    public function keyboard(Task $task, User $user): array
    {
        $locale = $user->preferredLocale();
        $allowed = $this->workflow->allowedTransitions($user, $task);
        $rows = [];

        $main = collect(self::MAIN_STEPS)->first(fn (TaskStatus $status) => in_array($status, $allowed, true));

        if ($main !== null) {
            $rows[] = [$this->statusButton($task, $main, $locale, 'primary')];
        }

        $second = array_values(array_filter([
            Gate::forUser($user)->allows('comment', $task)
                ? ['text' => __('notification.bot.comment', [], $locale), 'callback_data' => 'c:'.$task->id]
                : null,
            $this->canAskToMoveDeadline($task, $user)
                ? ['text' => __('notification.bot.move_deadline', [], $locale), 'callback_data' => 'd:'.$task->id]
                : null,
        ]));

        if ($second !== []) {
            $rows[] = $second;
        }

        if (count($allowed) > ($main === null ? 0 : 1)) {
            $rows[] = [['text' => __('notification.bot.other_status', [], $locale), 'callback_data' => 'm:'.$task->id]];
        }

        $rows[] = self::linkRow($task, $locale);

        return $rows;
    }

    /**
     * Every transition the person may make, two per row, and a way back.
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function statusMenu(Task $task, User $user): array
    {
        $locale = $user->preferredLocale();
        $allowed = $this->workflow->allowedTransitions($user, $task);
        $main = collect(self::MAIN_STEPS)->first(fn (TaskStatus $status) => in_array($status, $allowed, true));
        $buttons = array_map(
            fn (TaskStatus $status) => $this->statusButton($task, $status, $locale, match (true) {
                in_array($status, [TaskStatus::Cancelled, TaskStatus::Rejected], true) => 'danger',
                $status === $main => 'primary',
                default => null,
            }),
            $allowed,
        );

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => __('notification.bot.back', [], $locale), 'callback_data' => 'k:'.$task->id]];

        return $rows;
    }

    /**
     * Number buttons under a list of tasks: a press sends that task's card.
     *
     * @param  list<array{task_id: int, number: int}>  $rows
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public static function listKeyboard(array $rows): array
    {
        $buttons = array_map(
            fn (array $row) => ['text' => '#'.$row['number'], 'callback_data' => 'o:'.$row['task_id']],
            array_slice($rows, 0, 16),
        );

        return array_chunk($buttons, 4);
    }

    /**
     * The row that leads out of Telegram: the task in the tracker and its number to the clipboard.
     * Telegram refuses a link button that is not a public https address, so a local copy gets none.
     *
     * @return list<array<string, mixed>>
     */
    public static function linkRow(Task $task, string $locale): array
    {
        $url = route('tasks.show', $task);

        return array_values(array_filter([
            str_starts_with($url, 'https://')
                ? ['text' => __('notification.bot.open_in_tracker', [], $locale), 'url' => $url]
                : null,
            ['text' => __('notification.bot.copy_number', [], $locale), 'copy_text' => ['text' => '#'.$task->number]],
        ]));
    }

    /** Asking is for whoever does the work; the initiator simply changes the deadline. */
    public function canAskToMoveDeadline(Task $task, User $user): bool
    {
        return $task->deadline !== null
            && $task->status->isOpen()
            && ($task->assignee_id === $user->id || $task->initiator_id === $user->id || $user->isAdmin())
            && Gate::forUser($user)->allows('view', $task);
    }

    public function transitionLabel(TaskStatus $from, TaskStatus $to, string $locale): string
    {
        $key = match (true) {
            $to === TaskStatus::InProgress && $from === TaskStatus::New => 'to_in_progress_new',
            $to === TaskStatus::InProgress && $from === TaskStatus::Completed => 'to_in_progress_reopen',
            default => 'to_'.$to->value,
        };

        return __('notification.bot.'.$key, [], $locale);
    }

    /** @return array{text: string, callback_data: string, style?: string} */
    private function statusButton(Task $task, TaskStatus $to, string $locale, ?string $style = null): array
    {
        return array_filter([
            'text' => $this->transitionLabel($task->status, $to, $locale),
            'callback_data' => 's:'.$task->id.':'.$to->value,
            'style' => $style,
        ]);
    }

    private function deadlineDate(Task $task): string
    {
        return $task->deadline->copy()->timezone(config('app.timezone'))->format('Y-m-d');
    }
}
