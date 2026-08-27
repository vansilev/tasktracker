<?php

namespace App\Enums;

enum BillingCategory: string
{
    case InternetTelecom = 'internet_telecom';
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Plugin = 'plugin';
    case Saas = 'saas';
    case Ads = 'ads';
    case Ai = 'ai';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InternetTelecom => __('billing.category.internet_telecom'),
            self::Hosting => __('billing.category.hosting'),
            self::Domain => __('billing.category.domain'),
            self::Plugin => __('billing.category.plugin'),
            self::Saas => __('billing.category.saas'),
            self::Ads => __('billing.category.ads'),
            self::Ai => __('billing.category.ai'),
            self::Other => __('billing.category.other'),
        };
    }
}
