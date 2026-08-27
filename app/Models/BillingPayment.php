<?php

namespace App\Models;

use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    protected $fillable = [
        'billing_item_id',
        'type',
        'cycle_due_on',
        'recorded_on',
        'amount',
        'currency',
        'payment_method',
        'card_last4',
        'actor_user_id',
        'task_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => BillingPaymentType::class,
            'payment_method' => BillingPaymentMethod::class,
            'cycle_due_on' => 'date',
            'recorded_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class, 'billing_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
