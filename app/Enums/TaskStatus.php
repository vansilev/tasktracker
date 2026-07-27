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

    public function badgeClasses(): string
    {
        return match ($this) {
            self::New => 'bg-gray-100 text-gray-700 ring-1 ring-gray-200',
            self::InProgress => 'bg-blue-100 text-blue-800 ring-1 ring-blue-200',
            self::OnReview => 'bg-amber-100 text-amber-800 ring-1 ring-amber-200',
            self::Rework => 'bg-orange-100 text-orange-800 ring-1 ring-orange-200',
            self::Completed => 'bg-green-100 text-green-800 ring-1 ring-green-200',
            self::Postponed => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
            self::AwaitingInitiator => 'bg-purple-100 text-purple-800 ring-1 ring-purple-200',
            self::Rejected, self::Cancelled => 'bg-red-100 text-red-800 ring-1 ring-red-200',
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
