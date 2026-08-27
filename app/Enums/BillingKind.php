<?php

namespace App\Enums;

enum BillingKind: string
{
    case Subscription = 'subscription';
    case Once = 'once';
    case OnDemand = 'on_demand';
    case AdBudget = 'ad_budget';
    case Lifetime = 'lifetime';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => __('billing.kind.subscription'),
            self::Once => __('billing.kind.once'),
            self::OnDemand => __('billing.kind.on_demand'),
            self::AdBudget => __('billing.kind.ad_budget'),
            self::Lifetime => __('billing.kind.lifetime'),
        };
    }

    public function requiresPeriodMonths(): bool
    {
        return in_array($this, [self::Subscription, self::AdBudget], true);
    }

    public function requiresDueDate(): bool
    {
        return in_array($this, [self::Subscription, self::AdBudget, self::Once], true);
    }

    public function canMarkPaid(): bool
    {
        return $this !== self::Lifetime;
    }

    public function canSkip(): bool
    {
        return in_array($this, [self::Subscription, self::AdBudget, self::Once], true);
    }
}
