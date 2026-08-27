<?php

namespace App\Services;

use App\Enums\BillingCategory;
use App\Enums\BillingDueDayRule;
use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingState;
use App\Models\BillingItem;
use Illuminate\Validation\ValidationException;

class BillingItemService
{
    public function __construct(private BillingCycleService $cycle) {}

    /** @param  array<string, mixed>  $data */
    public function save(?BillingItem $item, array $data): BillingItem
    {
        $kind = $data['kind'] instanceof BillingKind
            ? $data['kind']
            : BillingKind::from((string) $data['kind']);

        $payload = [
            'vendor' => trim((string) $data['vendor']),
            'product' => trim((string) $data['product']),
            'description' => ($data['description'] ?? null) ?: null,
            'category' => $data['category'] instanceof BillingCategory
                ? $data['category']
                : BillingCategory::from((string) $data['category']),
            'kind' => $kind,
            'period_months' => $kind->requiresPeriodMonths()
                ? (int) ($kind === BillingKind::AdBudget ? 1 : ($data['period_months'] ?? 1))
                : null,
            'amount' => $this->nullableAmount($data['amount'] ?? null),
            'currency' => null,
            'next_due_on' => $kind->requiresDueDate() ? ($data['next_due_on'] ?: null) : null,
            'due_day_of_month' => ($data['due_day_of_month'] ?? null) !== null && ($data['due_day_of_month'] ?? '') !== ''
                ? (int) $data['due_day_of_month']
                : null,
            'due_day_rule' => $this->nullableDueDayRule($data['due_day_rule'] ?? null),
            'payment_method' => $data['payment_method'] instanceof BillingPaymentMethod
                ? $data['payment_method']
                : BillingPaymentMethod::from((string) $data['payment_method']),
            'card_last4' => ($data['card_last4'] ?? null) ?: null,
            'card_label' => ($data['card_label'] ?? null) ?: null,
            'payer_user_id' => ($data['payer_user_id'] ?? null) ?: null,
            'owner_user_id' => ($data['owner_user_id'] ?? null) ?: null,
            'owner_label' => ($data['owner_label'] ?? null) ?: null,
            'portal_url' => ($data['portal_url'] ?? null) ?: null,
            'account_ref' => ($data['account_ref'] ?? null) ?: null,
            'auto_renew' => (bool) ($data['auto_renew'] ?? false),
            'vat_note' => ($data['vat_note'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
            'state' => $item?->state ?? BillingState::Active,
        ];

        $payload['currency'] = $payload['amount'] !== null
            ? (string) ($data['currency'] ?? 'UAH')
            : ($data['currency'] ?: null);

        if ($payload['payment_method'] !== BillingPaymentMethod::Card) {
            $payload['card_last4'] = null;
            $payload['card_label'] = null;
        }

        if ($payload['card_last4'] !== null && $payload['card_last4'] !== '') {
            if (! preg_match('/^\d{4}$/', (string) $payload['card_last4'])) {
                throw ValidationException::withMessages(['card_last4' => [__('billing.issue.card_last4')]]);
            }
        }

        if ($kind->requiresPeriodMonths() && ! in_array((int) $payload['period_months'], [1, 12], true)) {
            throw ValidationException::withMessages(['period_months' => [__('billing.kind_label')]]);
        }

        if ($kind->requiresDueDate() && blank($payload['next_due_on']) && $item === null) {
            throw ValidationException::withMessages(['next_due_on' => [__('billing.issue.due_date')]]);
        }

        if ($item) {
            $item->update($payload);

            return $item->fresh(['payer', 'owner', 'payments', 'lastTask']);
        }

        return BillingItem::query()->create($payload);
    }

    public function dueMeta(BillingItem $item): array
    {
        if ($item->next_due_on === null) {
            return ['text' => '—', 'class' => 'text-gray-500'];
        }

        $today = $this->cycle->today();
        $due = $item->next_due_on->timezone(config('app.timezone'))->startOfDay();
        $formatted = $due->format('d.m.Y');
        $days = (int) $today->diffInDays($due, false);

        if ($days < 0) {
            return [
                'text' => __('billing.overdue_days', ['count' => abs($days)]),
                'class' => 'text-red-600 font-medium',
            ];
        }

        if ($days === 0) {
            return ['text' => __('billing.due_today').' · '.$formatted, 'class' => 'text-amber-600 font-medium'];
        }

        if ($days === 1) {
            return ['text' => __('billing.due_tomorrow').' · '.$formatted, 'class' => 'text-amber-600 font-medium'];
        }

        if ($days <= 7) {
            return [
                'text' => __('billing.due_in_days', ['count' => $days]).' · '.$formatted,
                'class' => 'text-amber-600 font-medium',
            ];
        }

        return ['text' => $formatted, 'class' => 'text-gray-700'];
    }

    public function amountHint(?string $raw, ?string $currency, ?int $periodMonths): ?string
    {
        try {
            $amount = $this->nullableAmount($raw);
        } catch (ValidationException) {
            return null;
        }

        if ($amount === null || ! $currency) {
            return null;
        }

        $formatted = number_format($amount, 2, ',', ' ');

        if ($periodMonths === 12) {
            return __('billing.amount_hint', [
                'amount' => $formatted,
                'currency' => $currency,
                'monthly' => number_format($amount / 12, 2, ',', ' '),
            ]);
        }

        if ($periodMonths === 1) {
            return __('billing.amount_monthly_hint', [
                'amount' => $formatted,
                'currency' => $currency,
                'yearly' => number_format($amount * 12, 2, ',', ' '),
            ]);
        }

        return null;
    }

    public function nullableAmount(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            return round((float) $raw, 2);
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', (string) $raw);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $normalized)) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! is_numeric($normalized)) {
            throw ValidationException::withMessages(['amount' => [__('billing.amount')]]);
        }

        return round((float) $normalized, 2);
    }

    private function nullableDueDayRule(mixed $raw): ?BillingDueDayRule
    {
        if ($raw instanceof BillingDueDayRule) {
            return $raw;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        return BillingDueDayRule::from((string) $raw);
    }
}
