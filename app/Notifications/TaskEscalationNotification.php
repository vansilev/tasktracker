<?php

namespace App\Notifications;

use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Services\ReminderSettings;
use App\Support\TelegramAppLinks;
use App\Support\TelegramReminderFormat as F;
use App\Support\TelegramTaskCard;
use App\Support\TelegramWidget as W;
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
        // The pilot list is checked again here, whatever queued this notification.
        return $notifiable instanceof User
            && filled($notifiable->telegram_chat_id)
            && app(ReminderSettings::class)->allowsPersonalMessages($notifiable->id)
                ? ['telegram']
                : [];
    }

    public function toTelegram(object $notifiable): TelegramMessage|TelegramWidgetMessage
    {
        /** @var User $notifiable */
        $locale = $notifiable->preferredLocale();
        $text = TelegramMessage::create()
            ->parseMode('HTML')
            ->options(['disable_web_page_preview' => true])
            ->content($this->text($locale));

        if (! app(ReminderSettings::class)->widgetsFor($notifiable->id)) {
            return $text;
        }

        [$html, $caption] = $this->widget($locale);
        // A head may comment on the task right from here; the comment goes in as theirs.
        $keyboard = count($this->items) === 1
            ? TelegramAppLinks::widgetKeyboard([[['text' => __('notification.bot.comment', [], $locale), 'callback_data' => 'c:'.$this->items[0]['task_id']]]])
            : TelegramTaskCard::listKeyboard($this->items);

        return TelegramWidgetMessage::make($html, W::caption($caption), $text, $keyboard);
    }

    /** @return array{0: string, 1: string} the HTML and the caption */
    public function widget(string $locale): array
    {
        $t = fn (string $key, array $replace = []) => __('notification.escalation.'.$key, $replace, $locale);
        $w = fn (string $key, array $replace = []) => __('notification.widget.'.$key, $replace, $locale);
        $role = $this->role === TaskReminder::ROLE_HEAD ? $w('you_head') : $w('you_digest');
        $comment = function (array $item) use ($t): string {
            $c = $item['comment'] ?? null;

            return $c === null ? W::meta(['💬 '.e($t('no_comments'))], 'margin-top:6px') : W::box('💬 '.$c['author'].' · '.$c['date'], $c['excerpt']);
        };

        if (count($this->items) === 1) {
            $item = $this->items[0];
            $days = F::days((int) $item['days_overdue'], $locale);
            $html = W::chips([['🔺 '.$w('escalation_chip', ['days' => $days])], [$role, 'gy']])
                .W::title($item['task_id'], $item['number'], $item['title'])
                .W::meta([
                    '🎯 '.e($t('responsible')).' <b>'.e($item['responsible']).'</b> · '.F::status($item['status'], $locale),
                    '🗓 '.e(Str::ucfirst(__('notification.reminder.was_due', ['date' => F::weekdayDate($item['deadline_date'], $locale)], $locale))),
                    F::priorityLine((int) $item['priority'], $locale),
                ])
                .$comment($item)
                .W::todo('👤 '.e($this->why($locale)))
                .W::linkButtons($item['task_id'], $item['number'], $locale);

            return [W::document($html, $locale), '🔺 '.$w('escalation_chip', ['days' => $days]).': '.$item['responsible'].', #'.$item['number'].' '.$item['title']];
        }

        $count = count($this->items);
        $rows = '';

        foreach ($this->items as $item) {
            $rows .= W::row($item['task_id'], $item['number'], $item['title'],
                '🎯 <b>'.e($item['responsible']).'</b> · '.e($w('overdue_by', ['days' => F::days((int) $item['days_overdue'], $locale)])));
        }

        $title = $t('head_list', ['tasks' => trans_choice('notification.escalation.tasks', $count, ['count' => $count], $locale)]);
        $html = W::chips([['🔺 '.$title], [$role, 'gy']]).'<div style="margin-top:6px">'.$rows.'</div>'.W::todo('👤 '.e($this->why($locale)));

        return [W::document($html, $locale), '🔺 '.$title];
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
