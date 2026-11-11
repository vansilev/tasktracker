<?php

namespace App\Support;

use App\Models\CommentNotice;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\HtmlContentService;
use App\Services\TaskContentService;
use App\Support\TelegramReminderFormat as F;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Telegram messages about comments and mentions (layouts Д-1…Д-8 approved by the customer on
 * 2026-11-11). The comment text is always read at the moment of sending, never from a copy.
 */
class CommentNoticeFormat
{
    /** The customer's limit for the quoted comment. */
    private const TEXT_LIMIT = 500;

    private const TEXT_LIMIT_IN_LIST = 200;

    /** "You were mentioned" — $acknowledgedAt set once the person has pressed "Принял". */
    public function mention(CommentNotice $notice, string $locale, ?CarbonInterface $acknowledgedAt = null): string
    {
        $t = $this->t($locale);

        return F::join([
            [
                '📣 <b>'.e($t('mention_title')).'</b>'.($acknowledgedAt === null ? '' : ' · 👍 '.e($t('acknowledged_at', ['time' => $this->clock($acknowledgedAt)]))),
                $this->taskLink($notice->task, $locale),
            ],
            $this->quote($notice->comment, $locale),
            $acknowledgedAt === null ? '👉 <b>'.e(__('notification.reminder.what', [], $locale)).'</b> '.e($t('mention_action')) : null,
            F::link(route('tasks.show', $notice->task), __('notification.open_task', [], $locale)),
        ]);
    }

    /** The follow-up to a mention nobody reacted to. */
    public function mentionReminder(CommentNotice $notice, int $workDays, string $locale): string
    {
        $t = $this->t($locale);

        return F::join([
            [
                '🔔 <b>'.e($t('reminder_title', ['days' => $this->workDays($workDays, $locale)])).'</b>',
                $this->taskLink($notice->task, $locale),
            ],
            $this->quote($notice->comment, $locale, full: true),
            '👉 <b>'.e(__('notification.reminder.what', [], $locale)).'</b> '.e($t('mention_action')),
            F::link(route('tasks.show', $notice->task), __('notification.open_task', [], $locale)),
        ]);
    }

    /** To the author of a question that has gone unanswered. */
    public function unanswered(CommentNotice $notice, int $workDays, string $locale): string
    {
        $t = $this->t($locale);

        return F::join([
            [
                '⏳ <b>'.e($t('unanswered_title', ['days' => $this->workDays($workDays, $locale)])).'</b>',
                $this->taskLink($notice->task, $locale),
            ],
            [
                '📣 '.e($t('you_asked')).' <b>'.e((string) $notice->user?->name).'</b> · '.e($this->moment($notice->comment->created_at, $locale, full: true)),
                '<blockquote>'.e($this->text($notice->comment, self::TEXT_LIMIT)).'</blockquote>',
            ],
            e($t('unanswered_note')),
            F::link(route('tasks.show', $notice->task), __('notification.open_task', [], $locale)),
        ]);
    }

    /** A new comment for the assignee, the initiator or a watcher. */
    public function comment(CommentNotice $notice, string $role, string $locale): string
    {
        $t = $this->t($locale);

        return F::join([
            [
                '💬 <b>'.e($t('comment_title')).'</b>',
                $this->taskLink($notice->task, $locale),
            ],
            $this->roleLine($notice->task, $role, $locale, full: true),
            $this->quote($notice->comment, $locale),
            F::link(route('tasks.show', $notice->task), __('notification.open_task', [], $locale)),
        ]);
    }

    /**
     * Several comments for one person as one message, grouped by task.
     *
     * @param  Collection<int, array{notice: CommentNotice, role: string}>  $items
     */
    public function batch(Collection $items, bool $whileAway, string $locale): string
    {
        $t = $this->t($locale);
        $count = $items->count();

        $blocks = [
            ($whileAway ? '🌙' : '💬').' <b>'.e($t($whileAway ? 'batch_title_away' : 'batch_title', [
                'comments' => trans_choice('notification.comments.comments', $count, ['count' => $count], $locale),
            ])).'</b>',
        ];

        foreach ($items->groupBy(fn (array $item) => $item['notice']->task_id) as $ofTask) {
            $first = $ofTask->first();
            $paragraphs = [];

            foreach ($ofTask as $item) {
                $comment = $item['notice']->comment;
                $paragraphs[] = '💬 '.e(($comment->author?->name ?? '—').', '.$this->moment($comment->created_at, $locale)).":\n"
                    .e($this->text($comment, self::TEXT_LIMIT_IN_LIST));
            }

            $blocks[] = '<blockquote expandable>'.F::join([
                [
                    $this->taskLink($first['notice']->task, $locale),
                    $this->roleLine($first['notice']->task, $first['role'], $locale, full: false).' · '
                        .e(trans_choice('notification.comments.comments', $ofTask->count(), ['count' => $ofTask->count()], $locale)),
                ],
                ...$paragraphs,
            ]).'</blockquote>';
        }

        $blocks[] = e($t('batch_hint'));

        return F::join($blocks);
    }

    /** One line of the digest's "waiting on the person" block. */
    public function stuckParagraph(CommentNotice $notice, int $workDays, string $locale): string
    {
        $t = $this->t($locale);
        $comment = $notice->comment;

        return implode("\n", [
            '👤 '.e($t('stuck_person', ['name' => (string) $notice->user?->name, 'days' => $this->workDays($workDays, $locale)])),
            F::taskLink($notice->task->id, $notice->task->number, $this->title($notice->task), $locale, bold: false),
            e(($comment->author?->name ?? '—').', '.F::weekdayDate($comment->created_at->copy()->timezone(config('app.timezone'))->format('Y-m-d'), $locale)
                .': «'.$this->text($comment, self::TEXT_LIMIT_IN_LIST).'»'),
        ]);
    }

    /** Plain text of a comment as it is now, cut to the limit. */
    public function text(TaskComment $comment, int $limit): string
    {
        $html = app(TaskContentService::class)->render($comment->body, $comment->body_format);
        $plain = trim((string) preg_replace('/\s+/u', ' ', app(HtmlContentService::class)->toPlainText($html)));

        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
    }

    private function quote(TaskComment $comment, string $locale, bool $full = false): string
    {
        return '💬 '.e(($comment->author?->name ?? '—').', '.$this->moment($comment->created_at, $locale, $full)).":\n"
            .'<blockquote>'.e($this->text($comment, self::TEXT_LIMIT)).'</blockquote>';
    }

    private function roleLine(Task $task, string $role, string $locale, bool $full): string
    {
        $t = $this->t($locale);
        $status = F::status($task->status->value, $locale);

        return match ($role) {
            'assignee' => '🎯 '.e($t($full ? 'role_assignee' : 'role_assignee_short')).($full ? ' · '.$status : ''),
            'initiator' => '🙋 '.e($t($full ? 'role_initiator' : 'role_initiator_short')).($full ? ' · '.$status : ''),
            default => '👀 '.e($t($full ? 'role_watcher' : 'role_watcher_short')),
        };
    }

    /** "12:31" today, "вчера 19:40" yesterday, "вт 10.11 в 12:31" otherwise or when $full. */
    private function moment(CarbonInterface $at, string $locale, bool $full = false): string
    {
        $at = Carbon::instance($at)->timezone(config('app.timezone'));
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $days = (int) $at->copy()->startOfDay()->diffInDays($today, false);
        $t = $this->t($locale);

        return match (true) {
            ! $full && $days === 0 => $this->clock($at),
            ! $full && $days === 1 => $t('yesterday_at', ['time' => $this->clock($at)]),
            default => $t('date_at', [
                'date' => str_replace(',', '', F::weekdayDate($at->format('Y-m-d'), $locale)),
                'time' => $this->clock($at),
            ]),
        };
    }

    private function clock(CarbonInterface $at): string
    {
        return Carbon::instance($at)->timezone(config('app.timezone'))->format('H:i');
    }

    private function workDays(int $count, string $locale): string
    {
        return trans_choice('notification.digest.work_days', max(1, $count), ['count' => max(1, $count)], $locale);
    }

    private function taskLink(Task $task, string $locale): string
    {
        return F::taskLink($task->id, $task->number, $this->title($task), $locale);
    }

    private function title(Task $task): string
    {
        return (string) ($task->title ?: $task->plainDescription());
    }

    /** @return callable(string, array<string, mixed>=): string */
    private function t(string $locale): callable
    {
        return fn (string $key, array $replace = []) => (string) __('notification.comments.'.$key, $replace, $locale);
    }
}
