<?php

namespace App\Enums;

enum TaskStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case AwaitingInitiator = 'awaiting_initiator';
    case OnReview = 'on_review';
    case Rework = 'rework';
    case Completed = 'completed';
    case Postponed = 'postponed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => __('task.status.new'),
            self::InProgress => __('task.status.in_progress'),
            self::AwaitingInitiator => __('task.status.awaiting_initiator'),
            self::OnReview => __('task.status.on_review'),
            self::Rework => __('task.status.rework'),
            self::Completed => __('task.status.completed'),
            self::Postponed => __('task.status.postponed'),
            self::Rejected => __('task.status.rejected'),
            self::Cancelled => __('task.status.cancelled'),
        };
    }

    /**
     * Four tones (docs/design-audit.md): neutral, info, warning, success;
     * closed-without-result statuses are muted rather than red.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::New, self::Postponed, self::AwaitingInitiator => 'bg-gray-100 text-gray-700',
            self::InProgress, self::Rework => 'bg-info-50 text-info-700',
            self::OnReview => 'bg-warning-50 text-warning-800',
            self::Completed => 'bg-success-50 text-success-700',
            self::Rejected, self::Cancelled => 'bg-surface text-gray-600 ring-1 ring-inset ring-gray-200',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    public static function requiresComment(self $to, self $from): bool
    {
        if ($from === self::Completed && $to === self::InProgress) {
            return true;
        }

        return in_array($to, [
            self::Rejected,
            self::AwaitingInitiator,
            self::Postponed,
            self::Rework,
            self::Cancelled,
        ], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
