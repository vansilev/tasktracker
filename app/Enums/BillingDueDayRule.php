<?php

namespace App\Enums;

enum BillingDueDayRule: string
{
    case On = 'on';
    case Until = 'until';

    public function label(): string
    {
        return match ($this) {
            self::On => __('billing.due_on'),
            self::Until => __('billing.due_until'),
        };
    }
}
