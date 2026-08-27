<?php

namespace Database\Factories;

use App\Enums\BillingCategory;
use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingState;
use App\Models\BillingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingItem>
 */
class BillingItemFactory extends Factory
{
    protected $model = BillingItem::class;

    public function definition(): array
    {
        return [
            'vendor' => 'Hostinger',
            'product' => 'Cloud',
            'category' => BillingCategory::Hosting,
            'kind' => BillingKind::Subscription,
            'period_months' => 1,
            'amount' => 29.00,
            'currency' => 'USD',
            'next_due_on' => now()->addDays(10)->toDateString(),
            'payment_method' => BillingPaymentMethod::Card,
            'card_last4' => '1234',
            'state' => BillingState::Active,
        ];
    }
}
