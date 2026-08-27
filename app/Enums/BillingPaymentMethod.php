<?php

namespace App\Enums;

enum BillingPaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('billing.method.cash'),
            self::Card => __('billing.method.card'),
            self::Bank => __('billing.method.bank'),
        };
    }
}
