<?php

use App\Enums\BillingCategory;
use App\Enums\BillingDueDayRule;
use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Models\BillingItem;
use App\Services\BillingBot;
use App\Services\BillingItemService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $open = false;

    public int $step = 1;

    public string $preset = '';

    public string $vendor = '';

    public string $product = '';

    public string $description = '';

    public string $category = 'saas';

    public string $amount = '';

    public string $currency = 'UAH';

    public bool $invoice = false;

    public string $kind = 'subscription';

    public int $periodMonths = 1;

    public string $nextDueOn = '';

    public string $dueDayOfMonth = '';

    public string $dueDayRule = '';

    public string $paymentMethod = 'card';

    public string $cardLast4 = '';

    public string $cardLabel = '';

    public string $payerUserId = '';

    public string $ownerUserId = '';

    public string $portalUrl = '';

    public string $accountRef = '';

    public bool $autoRenew = false;

    public string $vatNote = '';

    public string $notes = '';

    public bool $extraOpen = false;

    #[On('open-billing-create')]
    public function openModal(): void
    {
        $this->authorize('create', BillingItem::class);
        $this->resetForm();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetErrorBag();
    }

    public function applyPreset(string $preset): void
    {
        $this->preset = $preset;

        match ($preset) {
            'hosting' => $this->fillPreset(BillingCategory::Hosting, BillingKind::Subscription, 12, BillingPaymentMethod::Card, false),
            'domain' => $this->fillPreset(BillingCategory::Domain, BillingKind::Subscription, 12, BillingPaymentMethod::Card, false),
            'saas' => $this->fillPreset(BillingCategory::Saas, BillingKind::Subscription, 1, BillingPaymentMethod::Card, false),
            'telecom' => $this->fillPreset(BillingCategory::InternetTelecom, BillingKind::Subscription, 1, BillingPaymentMethod::Bank, true),
            default => null,
        };
    }

    private function fillPreset(
        BillingCategory $category,
        BillingKind $kind,
        int $period,
        BillingPaymentMethod $method,
        bool $invoice,
    ): void {
        $this->category = $category->value;
        $this->kind = $kind->value;
        $this->periodMonths = $period;
        $this->paymentMethod = $method->value;
        $this->invoice = $invoice;
        if ($invoice) {
            $this->amount = '';
        }
    }

    public function updatedKind(): void
    {
        $kind = BillingKind::tryFrom($this->kind);
        if ($kind === BillingKind::AdBudget) {
            $this->periodMonths = 1;
        }
        if ($kind && ! $kind->requiresDueDate()) {
            $this->nextDueOn = '';
        }
        if ($kind && ! $kind->requiresPeriodMonths()) {
            $this->periodMonths = 1;
        }
    }

    public function nextStep(): void
    {
        $this->validate($this->rulesForStep($this->step));
        $this->step = min(4, $this->step + 1);
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function save(BillingItemService $items): void
    {
        $this->authorize('create', BillingItem::class);
        $this->validate($this->rulesForStep(1) + $this->rulesForStep(2) + $this->rulesForStep(3) + $this->rulesForStep(4));

        try {
            $items->save(null, $this->payload());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->open = false;
        $this->resetForm();
        $this->dispatch('billing-item-created');
        session()->flash('billing_status', __('billing.created'));
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->step = 1;
        $this->preset = '';
        $this->vendor = '';
        $this->product = '';
        $this->description = '';
        $this->category = 'saas';
        $this->amount = '';
        $this->currency = 'UAH';
        $this->invoice = false;
        $this->kind = 'subscription';
        $this->periodMonths = 1;
        $this->nextDueOn = '';
        $this->dueDayOfMonth = '';
        $this->dueDayRule = '';
        $this->paymentMethod = 'card';
        $this->cardLast4 = '';
        $this->cardLabel = '';
        $this->payerUserId = '';
        $this->ownerUserId = '';
        $this->portalUrl = '';
        $this->accountRef = '';
        $this->autoRenew = false;
        $this->vatNote = '';
        $this->notes = '';
        $this->extraOpen = false;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'vendor' => $this->vendor,
            'product' => $this->product,
            'description' => $this->description,
            'category' => $this->category,
            'kind' => BillingKind::from($this->kind),
            'period_months' => $this->periodMonths,
            'amount' => $this->invoice ? null : $this->amount,
            'currency' => $this->currency,
            'next_due_on' => $this->nextDueOn,
            'due_day_of_month' => $this->dueDayOfMonth,
            'due_day_rule' => $this->dueDayRule,
            'payment_method' => $this->paymentMethod,
            'card_last4' => $this->cardLast4,
            'card_label' => $this->cardLabel,
            'payer_user_id' => $this->payerUserId !== '' ? (int) $this->payerUserId : null,
            'owner_user_id' => $this->ownerUserId !== '' ? (int) $this->ownerUserId : null,
            'portal_url' => $this->portalUrl,
            'account_ref' => $this->accountRef,
            'auto_renew' => $this->autoRenew,
            'vat_note' => $this->vatNote,
            'notes' => $this->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'vendor' => ['required', 'string', 'max:160'],
                'product' => ['required', 'string', 'max:160'],
                'category' => ['required', Rule::enum(BillingCategory::class)],
            ],
            2 => [
                'invoice' => ['boolean'],
                'amount' => [$this->invoice ? 'nullable' : 'required', 'string', 'max:32'],
                'currency' => ['required', Rule::in(['UAH', 'USD', 'EUR'])],
            ],
            3 => [
                'kind' => ['required', Rule::enum(BillingKind::class)],
                'periodMonths' => [BillingKind::from($this->kind)->requiresPeriodMonths() ? 'required' : 'nullable', Rule::in([1, 12])],
                'nextDueOn' => [BillingKind::from($this->kind)->requiresDueDate() ? 'required' : 'nullable', 'date'],
                'dueDayOfMonth' => ['nullable', 'integer', 'min:1', 'max:31'],
                'dueDayRule' => ['nullable', Rule::enum(BillingDueDayRule::class)],
            ],
            default => [
                'paymentMethod' => ['required', Rule::enum(BillingPaymentMethod::class)],
                'cardLast4' => ['nullable', 'regex:/^\d{4}$/'],
                'payerUserId' => ['nullable', 'exists:users,id'],
                'ownerUserId' => ['nullable', 'exists:users,id'],
            ],
        };
    }

    public function with(): array
    {
        $period = BillingKind::from($this->kind)->requiresPeriodMonths() ? $this->periodMonths : null;

        return [
            'people' => app(BillingBot::class)->peopleQuery()->get(['id', 'name']),
            'amountHint' => $this->invoice ? null : app(BillingItemService::class)->amountHint($this->amount, $this->currency, $period),
            'kinds' => BillingKind::cases(),
            'categories' => BillingCategory::cases(),
            'methods' => BillingPaymentMethod::cases(),
            'kindRequiresPeriod' => BillingKind::from($this->kind)->requiresPeriodMonths(),
            'kindRequiresDue' => BillingKind::from($this->kind)->requiresDueDate(),
        ];
    }
}; ?>

<div>
    @if ($open)
        <div
            class="fixed inset-0 z-50 overflow-y-auto"
            wire:keydown.escape.window="close"
            role="dialog"
            aria-modal="true"
            aria-labelledby="billing-create-title"
        >
            <div class="fixed inset-0 bg-gray-500/75" wire:click="close"></div>
            <div class="relative mx-auto my-6 w-full max-w-2xl px-4">
                <div class="relative bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden">
                    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                        <h2 id="billing-create-title" class="text-sm font-semibold text-gray-900">{{ __('billing.create') }}</h2>
                        <button type="button" wire:click="close" class="text-gray-400 hover:text-gray-700 text-lg leading-none" aria-label="{{ __('Cancel') }}">✕</button>
                    </div>

                    <div class="p-5 space-y-4 max-h-[calc(100vh-10rem)] overflow-y-auto">
                        <div class="flex flex-wrap gap-2">
                            @foreach (['hosting', 'domain', 'saas', 'telecom'] as $key)
                                <button type="button" wire:click="applyPreset('{{ $key }}')"
                                        class="px-3 py-1.5 rounded-lg text-sm border {{ $preset === $key ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50' }}">
                                    {{ __('billing.preset.'.$key) }}
                                </button>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap gap-2 text-xs font-medium">
                            @for ($i = 1; $i <= 4; $i++)
                                <span class="px-2 py-1 rounded {{ $step === $i ? 'bg-indigo-50 text-indigo-700' : 'text-gray-400' }}">{{ $i }}. {{ __('billing.step.'.$i) }}</span>
                            @endfor
                        </div>

                        @if ($step === 1)
                            <div class="space-y-4">
                                <div>
                                    <x-input-label :value="__('billing.vendor')" />
                                    <x-text-input wire:model="vendor" class="mt-1 w-full" />
                                    <x-input-error :messages="$errors->get('vendor')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label :value="__('billing.product')" />
                                    <x-text-input wire:model="product" class="mt-1 w-full" />
                                    <x-input-error :messages="$errors->get('product')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label :value="__('billing.category_label')" />
                                    <select wire:model="category" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label :value="__('billing.description')" />
                                    <x-text-input wire:model="description" class="mt-1 w-full" />
                                </div>
                            </div>
                        @elseif ($step === 2)
                            <div class="space-y-4">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live="invoice" class="rounded border-gray-300 text-indigo-600" />
                                    {{ __('billing.invoice_toggle') }}
                                </label>
                                @unless ($invoice)
                                    <div>
                                        <x-input-label :value="__('billing.amount')" />
                                        <x-text-input wire:model.live="amount" class="mt-1 w-full" />
                                        <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                                        @if ($amountHint)
                                            <p class="mt-1 text-xs text-gray-500">{{ $amountHint }}</p>
                                        @endif
                                    </div>
                                @endunless
                                <div>
                                    <x-input-label :value="__('billing.currency')" />
                                    <select wire:model.live="currency" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                        <option value="UAH">UAH</option>
                                        <option value="USD">USD</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                </div>
                            </div>
                        @elseif ($step === 3)
                            <div class="space-y-4">
                                <div>
                                    <x-input-label :value="__('billing.kind_label')" />
                                    <select wire:model.live="kind" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                        @foreach ($kinds as $option)
                                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @if ($kindRequiresPeriod)
                                    <div>
                                        <x-input-label :value="__('billing.period')" />
                                        <select wire:model.live="periodMonths" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                            <option value="1">{{ __('billing.every_month') }}</option>
                                            <option value="12">{{ __('billing.every_year') }}</option>
                                        </select>
                                    </div>
                                @endif
                                @if ($kindRequiresDue)
                                    <div>
                                        <x-input-label :value="__('billing.next_due')" />
                                        <x-text-input type="date" wire:model="nextDueOn" class="mt-1 w-full" />
                                        <x-input-error :messages="$errors->get('nextDueOn')" class="mt-1" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <x-input-label :value="__('billing.due_day')" />
                                            <x-text-input wire:model="dueDayOfMonth" class="mt-1 w-full" placeholder="10" />
                                        </div>
                                        <div>
                                            <x-input-label :value="__('billing.due_rule')" />
                                            <select wire:model="dueDayRule" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                                <option value="">{{ __('billing.not_specified') }}</option>
                                                <option value="on">{{ __('billing.due_on') }}</option>
                                                <option value="until">{{ __('billing.due_until') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="space-y-4">
                                <div>
                                    <x-input-label :value="__('billing.method_label')" />
                                    <select wire:model.live="paymentMethod" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                        @foreach ($methods as $method)
                                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @if ($paymentMethod === 'card')
                                    <div>
                                        <x-input-label :value="__('billing.card_digits')" />
                                        <x-text-input wire:model="cardLast4" class="mt-1 w-full" maxlength="4" />
                                        <p class="mt-1 text-xs text-amber-700">{{ __('billing.issue.card_last4') }}</p>
                                        <x-input-error :messages="$errors->get('cardLast4')" class="mt-1" />
                                    </div>
                                @endif
                                <div>
                                    <x-input-label :value="__('billing.payer')" />
                                    <select wire:model="payerUserId" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                        <option value="">{{ __('billing.not_specified') }}</option>
                                        @foreach ($people as $person)
                                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">{{ __('billing.payer_help') }}</p>
                                </div>
                                <div>
                                    <x-input-label :value="__('billing.owner')" />
                                    <select wire:model="ownerUserId" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                        <option value="">{{ __('billing.not_specified') }}</option>
                                        @foreach ($people as $person)
                                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">{{ __('billing.owner_help') }}</p>
                                </div>
                                <button type="button" class="text-sm text-indigo-700" wire:click="$toggle('extraOpen')">{{ __('billing.extra') }}</button>
                                @if ($extraOpen)
                                    <div class="space-y-3 border-t border-gray-100 pt-3">
                                        <div>
                                            <x-input-label :value="__('billing.portal_url')" />
                                            <x-text-input wire:model="portalUrl" class="mt-1 w-full" />
                                        </div>
                                        <div>
                                            <x-input-label :value="__('billing.account_ref')" />
                                            <x-text-input wire:model="accountRef" class="mt-1 w-full" />
                                        </div>
                                        @if ($paymentMethod === 'card')
                                            <div>
                                                <x-input-label :value="__('billing.card_label')" />
                                                <x-text-input wire:model="cardLabel" class="mt-1 w-full" />
                                            </div>
                                        @endif
                                        <div>
                                            <x-input-label :value="__('billing.vat_note')" />
                                            <x-text-input wire:model="vatNote" class="mt-1 w-full" />
                                        </div>
                                        <div>
                                            <x-input-label :value="__('billing.notes')" />
                                            <textarea wire:model="notes" class="mt-1 w-full border-gray-300 rounded-lg text-sm" rows="3"></textarea>
                                        </div>
                                        <label class="flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" wire:model="autoRenew" class="rounded border-gray-300 text-indigo-600" />
                                            {{ __('billing.auto_renew') }}
                                        </label>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between gap-3 px-5 py-4 border-t border-gray-100 bg-gray-50">
                        <x-action-button variant="ghost" wire:click="prevStep" :disabled="$step === 1">{{ __('billing.back') }}</x-action-button>
                        <div class="flex gap-2">
                            <x-action-button variant="ghost" wire:click="close">{{ __('Cancel') }}</x-action-button>
                            @if ($step < 4)
                                <x-action-button variant="primary" wire:click="nextStep">{{ __('billing.next') }}</x-action-button>
                            @else
                                <x-action-button variant="primary" wire:click="save">{{ __('billing.save') }}</x-action-button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
