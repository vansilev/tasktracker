<?php

namespace App\Enums;

enum BillingPaymentType: string
{
    case Paid = 'paid';
    case Skipped = 'skipped';
}
