<?php

namespace App\Models;

use App\Enums\BillingCategory;
use App\Enums\BillingDueDayRule;
use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor',
        'product',
        'description',
        'category',
        'kind',
        'period_months',
        'amount',
        'currency',
        'next_due_on',
        'due_day_of_month',
        'due_day_rule',
        'payment_method',
        'card_last4',
        'card_label',
        'payer_user_id',
        'owner_user_id',
        'owner_label',
        'portal_url',
        'account_ref',
        'auto_renew',
        'state',
        'paused_until',
        'archived_at',
        'archive_reason',
        'vat_note',
        'notes',
        'last_task_id',
        'reminder_7_sent_for',
        'reminder_3_sent_for',
        'reminder_overdue_sent_for',
    ];

    protected function casts(): array
    {
        return [
            'category' => BillingCategory::class,
            'kind' => BillingKind::class,
            'payment_method' => BillingPaymentMethod::class,
            'state' => BillingState::class,
            'due_day_rule' => BillingDueDayRule::class,
            'next_due_on' => 'date',
            'paused_until' => 'date',
            'archived_at' => 'datetime',
            'reminder_7_sent_for' => 'date',
            'reminder_3_sent_for' => 'date',
            'reminder_overdue_sent_for' => 'date',
            'auto_renew' => 'boolean',
            'amount' => 'decimal:2',
            'period_months' => 'integer',
            'due_day_of_month' => 'integer',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function lastTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'last_task_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function title(): string
    {
        return trim($this->vendor.' · '.$this->product);
    }

    public function formattedAmount(): string
    {
        if ($this->amount === null) {
            return __('billing.invoice_amount');
        }

        return number_format((float) $this->amount, 2, ',', ' ').' '.($this->currency ?? '');
    }

    /** @return list<array{key: string, label: string}> */
    public function issues(): array
    {
        $issues = [];

        if ($this->state !== BillingState::Active) {
            return $issues;
        }

        if ($this->payer_user_id === null && $this->kind !== BillingKind::Lifetime) {
            $issues[] = ['key' => 'payer', 'label' => __('billing.issue.payer')];
        }

        if ($this->owner_user_id === null) {
            $issues[] = ['key' => 'owner', 'label' => __('billing.issue.owner')];
        }

        if ($this->payment_method === BillingPaymentMethod::Card && blank($this->card_last4)) {
            $issues[] = ['key' => 'card_last4', 'label' => __('billing.issue.card_last4')];
        }

        if ($this->payer && $this->payer->department_id === null && $this->kind->canMarkPaid()) {
            $issues[] = ['key' => 'payer_department', 'label' => __('billing.issue.payer_department')];
        }

        if ($this->kind->requiresDueDate() && $this->next_due_on === null) {
            $issues[] = ['key' => 'due_date', 'label' => __('billing.issue.due_date')];
        }

        return $issues;
    }

    public function blocksReminders(): bool
    {
        if ($this->state !== BillingState::Active) {
            return true;
        }

        if ($this->kind === BillingKind::Lifetime || $this->kind === BillingKind::OnDemand) {
            return true;
        }

        if ($this->payer_user_id === null || $this->next_due_on === null) {
            return true;
        }

        if ($this->payer && $this->payer->department_id === null) {
            return true;
        }

        return false;
    }

    public function derivedStatus(): string
    {
        if ($this->state === BillingState::Paused) {
            return 'paused';
        }

        if ($this->kind === BillingKind::Lifetime) {
            return 'lifetime';
        }

        if ($this->payer_user_id === null && $this->kind !== BillingKind::Lifetime) {
            return 'needs_payer';
        }

        if ($this->kind === BillingKind::OnDemand) {
            return 'on_demand';
        }

        if ($this->next_due_on !== null) {
            $today = now()->timezone(config('app.timezone'))->startOfDay();
            $due = $this->next_due_on->timezone(config('app.timezone'))->startOfDay();

            if ($due->lt($today)) {
                return 'overdue';
            }

            if ($today->diffInDays($due) <= 7) {
                return 'soon';
            }
        }

        return 'active';
    }
}
