<?php

namespace App\Support;

use App\Enums\TaskStatus;
use Carbon\Carbon;
use Throwable;

class InAppNotification
{
    /** @param  array<string, mixed>  $data */
    public static function line(array $data): string
    {
        $params = [
            'number' => $data['task_number'] ?? '',
            'title' => $data['task_title'] ?? '',
            'actor' => $data['actor_name'] ?? '',
            'excerpt' => $data['comment_excerpt'] ?? '',
            'emoji' => $data['emoji'] ?? '',
        ];

        return match ($data['event'] ?? '') {
            'task.assigned' => __('notification.task_assigned', $params),
            'task.status_changed' => __('notification.task_status_changed', array_merge($params, [
                'old' => self::statusLabel($data['old_status'] ?? null),
                'new' => self::statusLabel($data['new_status'] ?? null),
            ])),
            'task.commented' => __('notification.task_commented', $params),
            'task.mentioned' => __('notification.task_mentioned', $params),
            'task.comment_replied' => __('notification.task_comment_replied', $params),
            'task.comment_reacted' => __('notification.task_comment_reacted', $params),
            'task.deadline_approaching' => __('notification.task_deadline_approaching', array_merge($params, [
                'deadline' => self::formatDateTime($data['deadline'] ?? null),
            ])),
            'task.overdue' => __('notification.task_overdue', array_merge($params, [
                'deadline' => self::formatDateTime($data['deadline'] ?? null),
            ])),
            'task.review_sla_expired' => __('notification.task_review_sla_expired', array_merge($params, [
                'review_due_at' => self::formatDateTime($data['review_due_at'] ?? null),
            ])),
            'billing.due_7' => __('billing.notify_due_7', self::billingParams($data)),
            'billing.due_3' => __('billing.notify_due_3', self::billingParams($data)),
            'billing.overdue' => __('billing.notify_overdue', self::billingParams($data)),
            default => trim((string) ($data['title'] ?? '')),
        };
    }

    /** @param  array<string, mixed>  $data */
    public static function heading(array $data): string
    {
        $number = self::taskNumber($data);
        $title = trim((string) ($data['task_title'] ?? $data['title'] ?? ''));

        if ($number !== null) {
            return $title !== '' ? '#'.$number.' · '.$title : '#'.$number;
        }

        return $title !== '' ? $title : self::line($data);
    }

    /** @param  array<string, mixed>  $data */
    public static function taskNumber(array $data): ?int
    {
        $number = (int) ($data['task_number'] ?? 0);

        return $number > 0 ? $number : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function billingItemId(array $data): ?int
    {
        $id = (int) ($data['billing_item_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    public static function formatDateTime(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Carbon::parse($value)->timezone(config('app.timezone'))->format('d.m.Y H:i');
    }

    private static function statusLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return TaskStatus::from($value)->label();
        } catch (Throwable) {
            return $value;
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function billingParams(array $data): array
    {
        return [
            'title' => $data['title'] ?? '',
            'amount' => $data['amount'] ?? '',
            'date' => isset($data['next_due_on'])
                ? Carbon::parse((string) $data['next_due_on'])->timezone(config('app.timezone'))->format('d.m.Y')
                : '',
        ];
    }
}
