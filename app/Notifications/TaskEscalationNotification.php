<?php

namespace App\Notifications;

use App\Models\TaskReminder;
use App\Models\User;
use App\Support\TelegramReminderFormat as F;
use App\Support\WorkCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * Tells a department head (or the digest recipients) which tasks of their people are long
 * overdue: one message per recipient per morning, however many tasks there are.
 */
class TaskEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Re-sending is the morning command's job (it re-claims failed reminders), not the queue's. */
    public int $tries = 1;

    /**
     * @param  string  $date  Y-m-d, the morning the escalation was raised
     * @param  string  $role  TaskReminder::ROLE_HEAD or ROLE_DIGEST — why this person gets it
     * @param  list<array{task_id: int, number: int, title: string, status: string, deadline_date: string, days_overdue: int, priority: int, responsible: string, comment: ?array{author: string, date: string, excerpt: string}}>  $items
     */
    public function __construct(
        public string $date,
        public string $role,
        public array $items,
    ) {}

    /** Nothing leaves outside working hours: a held-back message is picked up by the next run. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(WorkCalendar::class)->isWithinWorkHours();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && filled($notifiable->telegram_chat_id) ? ['telegram'] : [];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        /** @var User $notifiable */
        return TelegramMessage::create()
            ->parseMode('HTML')
            ->options(['disable_web_page_preview' => true])
            ->content($this->text($notifiable->preferredLocale()));
    }

    public function text(string $locale): string
    {
        return count($this->items) === 1
            ? $this->singleTask($this->items[0], $locale)
            : $this->taskList($locale);
    }

    /** @param  array<string, mixed>  $item */
    private function singleTask(array $item, string $locale): string
    {
        $t = fn (string $key, array $replace = []) => __('notification.escalation.'.$key, $replace, $locale);

        return F::join([
            [
                '🔺 <b>'.e($t('head_single', ['days' => F::days((int) $item['days_overdue'], $locale)])).'</b>',
                F::taskLink($item['task_id'], $item['number'], $item['title'], $locale),
            ],
            [
                '🎯 '.e($t('responsible')).' <b>'.e($item['responsible']).'</b> · '.F::status($item['status'], $locale),
                '🗓 '.e(Str::ucfirst(__('notification.reminder.was_due', ['date' => F::weekdayDate($item['deadline_date'], $locale)], $locale))),
                F::priorityLine((int) $item['priority'], $locale),
            ],
            $this->commentBlock($item, $locale),
            '👤 '.e($this->why($locale)),
            F::link(route('tasks.show', $item['task_id']), __('notification.open_task', [], $locale)),
        ]);
    }

    private function taskList(string $locale): string
    {
        $t = fn (string $key, array $replace = []) => __('notification.escalation.'.$key, $replace, $locale);
        $count = count($this->items);

        $blocks = [
            '🔺 <b>'.e($t('head_list', ['tasks' => trans_choice('notification.escalation.tasks', $count, ['count' => $count], $locale)])).'</b>',
        ];

        foreach ($this->items as $item) {
            $facts = array_filter([
                '<b>'.e(F::days((int) $item['days_overdue'], $locale)).'</b>',
                e(__('notification.reminder.was_due', ['date' => F::weekdayDate($item['deadline_date'], $locale)], $locale)),
                F::priorityTag((int) $item['priority'], $locale),
            ]);
            $comment = $item['comment'] ?? null;

            $blocks[] = '<blockquote expandable>'.F::join([[
                F::taskLink($item['task_id'], $item['number'], $item['title'], $locale),
                '🔴 '.implode(' · ', $facts),
                '🎯 '.e($item['responsible']).' · '.F::status($item['status'], $locale),
                $comment === null
                    ? '💬 '.e($t('no_comments'))
                    : '💬 '.e($comment['author'].', '.$comment['date'].': '.$comment['excerpt']),
            ]]).'</blockquote>';
        }

        $blocks[] = '👤 '.e($this->why($locale));

        return F::join($blocks);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function commentBlock(array $item, string $locale): array
    {
        $comment = $item['comment'] ?? null;

        if ($comment === null) {
            return ['💬 '.e(__('notification.escalation.no_comments', [], $locale))];
        }

        return [
            '💬 '.e(__('notification.escalation.last_comment', ['author' => $comment['author'], 'date' => $comment['date']], $locale)),
            '<blockquote>'.e($comment['excerpt']).'</blockquote>',
        ];
    }

    private function why(string $locale): string
    {
        return __('notification.escalation.'.($this->role === TaskReminder::ROLE_HEAD ? 'why_head' : 'why_digest'), [], $locale);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'task.escalation',
            'date' => $this->date,
            'task_ids' => array_column($this->items, 'task_id'),
        ];
    }
}
