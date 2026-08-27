<?php

namespace App\Enums;

enum BillingPaymentType: string
{
    case Paid = 'paid';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Paid => __('billing.history_paid'),
            self::Skipped => __('billing.history_skipped'),
        };
    }
}
