<?php

namespace App\Enums;

enum BillingState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('billing.state.active'),
            self::Paused => __('billing.state.paused'),
            self::Archived => __('billing.state.archived'),
        };
    }
}
