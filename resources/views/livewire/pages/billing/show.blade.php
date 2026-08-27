<?php

use App\Enums\BillingCategory;
use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingState;
use App\Enums\Permission;
use App\Models\BillingItem;
use App\Services\BillingBot;
use App\Services\BillingItemService;
use App\Services\BillingPaymentService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.tasks-layout')] class extends Component
{
    public BillingItem $item;

    public bool $editing = false;

    public string $vendor = '';

    public string $product = '';

    public string $description = '';

    public string $category = '';

    public string $amount = '';

    public string $currency = 'UAH';

    public bool $invoice = false;

    public string $kind = '';

    public int $periodMonths = 1;

    public string $nextDueOn = '';

    public string $dueDayOfMonth = '';

    public string $dueDayRule = '';

    public string $paymentMethod = '';

    public string $cardLast4 = '';

    public string $cardLabel = '';

    public string $payerUserId = '';

    public string $ownerUserId = '';

    public string $portalUrl = '';

    public string $accountRef = '';

    public bool $autoRenew = false;

    public string $vatNote = '';

    public string $notes = '';

    public bool $payOpen = false;

    public string $payAmount = '';

    public bool $skipOpen = false;

    public string $skipReason = '';

    public bool $archiveOpen = false;

    public string $archiveReason = '';

    public bool $pauseOpen = false;

    public string $pausedUntil = '';

    public function mount(BillingItem $item): void
    {
        $this->authorize('view', $item);
        $this->item = $item->load(['payer', 'owner', 'lastTask', 'payments.actor']);
        $this->fillFromItem();
    }

    public function fillFromItem(): void
    {
        $this->vendor = $this->item->vendor;
        $this->product = $this->item->product;
        $this->description = (string) $this->item->description;
        $this->category = $this->item->category->value;
        $this->amount = $this->item->amount !== null ? (string) $this->item->amount : '';
        $this->currency = $this->item->currency ?: 'UAH';
        $this->invoice = $this->item->amount === null;
        $this->kind = $this->item->kind->value;
        $this->periodMonths = (int) ($this->item->period_months ?: 1);
        $this->nextDueOn = $this->item->next_due_on?->toDateString() ?? '';
        $this->dueDayOfMonth = $this->item->due_day_of_month ? (string) $this->item->due_day_of_month : '';
        $this->dueDayRule = $this->item->due_day_rule?->value ?? '';
        $this->paymentMethod = $this->item->payment_method->value;
        $this->cardLast4 = (string) $this->item->card_last4;
        $this->cardLabel = (string) $this->item->card_label;
        $this->payerUserId = $this->item->payer_user_id ? (string) $this->item->payer_user_id : '';
        $this->ownerUserId = $this->item->owner_user_id ? (string) $this->item->owner_user_id : '';
        $this->portalUrl = (string) $this->item->portal_url;
        $this->accountRef = (string) $this->item->account_ref;
        $this->autoRenew = (bool) $this->item->auto_renew;
        $this->vatNote = (string) $this->item->vat_note;
        $this->notes = (string) $this->item->notes;
    }

    public function save(BillingItemService $items): void
    {
        $this->authorize('update', $this->item);

        try {
            $this->item = $items->save($this->item, [
                'vendor' => $this->vendor,
                'product' => $this->product,
                'description' => $this->description,
                'category' => $this->category,
                'kind' => $this->kind,
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
            ]);
            $this->editing = false;
            $this->fillFromItem();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function openPay(): void
    {
        $this->authorize('markPaid', $this->item);
        $this->payOpen = true;
        $this->payAmount = $this->item->amount !== null ? (string) $this->item->amount : '';
    }

    public function confirmPay(BillingPaymentService $payments): void
    {
        try {
            $this->item = $payments->markPaid(auth()->user(), $this->item, $this->payAmount !== '' ? $this->payAmount : null);
            $this->payOpen = false;
            $this->fillFromItem();
            session()->flash('billing_status', $this->item->next_due_on
                ? __('billing.paid_saved', ['date' => $this->item->next_due_on->format('d.m.Y')])
                : __('billing.paid_saved_once'));
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    public function confirmSkip(BillingPaymentService $payments): void
    {
        try {
            $this->item = $payments->skip(auth()->user(), $this->item, $this->skipReason);
            $this->skipOpen = false;
            $this->skipReason = '';
            $this->fillFromItem();
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    public function confirmArchive(BillingPaymentService $payments): void
    {
        $this->item = $payments->archive(auth()->user(), $this->item, $this->archiveReason);
        $this->archiveOpen = false;
        $this->archiveReason = '';
    }

    public function confirmPause(BillingPaymentService $payments): void
    {
        $this->item = $payments->pause(auth()->user(), $this->item, $this->pausedUntil !== '' ? $this->pausedUntil : null);
        $this->pauseOpen = false;
        $this->pausedUntil = '';
    }

    public function resume(BillingPaymentService $payments): void
    {
        $this->item = $payments->resume(auth()->user(), $this->item);
    }

    public function with(): array
    {
        return [
            'people' => app(BillingBot::class)->peopleQuery()->get(['id', 'name']),
            'due' => app(BillingItemService::class)->dueMeta($this->item),
            'issues' => $this->item->issues(),
            'canManage' => auth()->user()->hasPermission(Permission::ManageBilling),
            'canPay' => auth()->user()->can('markPaid', $this->item),
            'kinds' => BillingKind::cases(),
            'categories' => BillingCategory::cases(),
            'methods' => BillingPaymentMethod::cases(),
        ];
    }
}; ?>

<div>
    <x-slot name="title">{{ $item->title() }}</x-slot>
    @if ($canManage)
        <x-slot name="headerActions">
            @if (! $editing)
                <x-action-button variant="secondary" wire:click="$set('editing', true)">{{ __('Edit') }}</x-action-button>
            @endif
        </x-slot>
    @endif

    <div class="space-y-4 max-w-3xl">
        @if (session('billing_status'))
            <p class="text-sm text-green-700 bg-green-50 rounded-lg px-3 py-2">{{ session('billing_status') }}</p>
        @endif
        @if (session('billing_error'))
            <p class="text-sm text-red-700 bg-red-50 rounded-lg px-3 py-2">{{ session('billing_error') }}</p>
        @endif

        @if ($issues)
            <div class="flex flex-wrap gap-1">
                @foreach ($issues as $issue)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 ring-1 ring-amber-200">{{ $issue['label'] }}</span>
                @endforeach
            </div>
        @endif

        @if ($editing)
            <x-card>
                <form wire:submit="save" class="space-y-4">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label :value="__('billing.vendor')" />
                            <x-text-input wire:model="vendor" class="mt-1 w-full" />
                        </div>
                        <div>
                            <x-input-label :value="__('billing.product')" />
                            <x-text-input wire:model="product" class="mt-1 w-full" />
                        </div>
                    </div>
                    <div>
                        <x-input-label :value="__('billing.category_label')" />
                        <select wire:model="category" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="invoice" class="rounded border-gray-300 text-indigo-600" />
                        {{ __('billing.invoice_toggle') }}
                    </label>
                    @unless ($invoice)
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <x-input-label :value="__('billing.amount')" />
                                <x-text-input wire:model="amount" class="mt-1 w-full" />
                            </div>
                            <div>
                                <x-input-label :value="__('billing.currency')" />
                                <select wire:model="currency" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                                    <option value="UAH">UAH</option>
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                </select>
                            </div>
                        </div>
                    @endunless
                    <div>
                        <x-input-label :value="__('billing.kind_label')" />
                        <select wire:model="kind" class="mt-1 w-full border-gray-300 rounded-lg text-sm">
                            @foreach ($kinds as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label :value="__('billing.next_due')" />
                        <x-text-input type="date" wire:model="nextDueOn" class="mt-1 w-full" />
                    </div>
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
                        </div>
                    @endif
                    <div class="grid sm:grid-cols-2 gap-4">
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
                    </div>
                    <div>
                        <x-input-label :value="__('billing.notes')" />
                        <textarea wire:model="notes" class="mt-1 w-full border-gray-300 rounded-lg text-sm" rows="3"></textarea>
                    </div>
                    <div class="flex gap-2">
                        <x-action-button variant="primary" type="submit">{{ __('billing.save') }}</x-action-button>
                        <x-action-button variant="ghost" type="button" wire:click="$set('editing', false)">{{ __('Cancel') }}</x-action-button>
                    </div>
                </form>
            </x-card>
        @else
            <x-card>
                <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('billing.amount') }}</dt>
                        <dd class="font-medium">{{ $item->formattedAmount() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('billing.next_due') }}</dt>
                        <dd class="{{ $due['class'] }}">{{ $due['text'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('billing.kind_label') }}</dt>
                        <dd>{{ $item->kind->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('billing.method_label') }}</dt>
                        <dd>{{ $item->payment_method->label() }} @if ($item->card_last4) •••• {{ $item->card_last4 }} @endif</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('billing.payer') }}</dt>
                        <dd>{{ $item->payer?->name ?? __('billing.not_specified') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('billing.owner') }}</dt>
                        <dd>{{ $item->owner?->name ?? __('billing.not_specified') }}</dd>
                    </div>
                    @if ($item->owner_label)
                        <div>
                            <dt class="text-gray-500">{{ __('billing.owner_label') }}</dt>
                            <dd>{{ $item->owner_label }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-gray-500">{{ __('billing.state_label') }}</dt>
                        <dd>{{ $item->state->label() }}</dd>
                    </div>
                </dl>
                @if ($item->notes)
                    <p class="mt-4 text-sm text-gray-600 whitespace-pre-line">{{ $item->notes }}</p>
                @endif
                @if ($item->lastTask)
                    <p class="mt-4 text-sm"><a href="{{ route('tasks.show', $item->lastTask) }}" wire:navigate class="text-indigo-700">{{ __('billing.open_task') }} #{{ $item->lastTask->number }}</a></p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($item->kind->canMarkPaid() && $canPay && $item->state === \App\Enums\BillingState::Active)
                        <x-action-button variant="primary" wire:click="openPay">{{ __('billing.mark_paid') }}</x-action-button>
                        @if ($item->kind->canSkip())
                            <x-action-button variant="ghost" wire:click="$set('skipOpen', true)">{{ __('billing.skip') }}</x-action-button>
                        @endif
                    @endif
                    @if ($canManage && $item->state === \App\Enums\BillingState::Active)
                        <x-action-button variant="secondary" wire:click="$set('pauseOpen', true)">{{ __('billing.pause') }}</x-action-button>
                        <x-action-button variant="danger" wire:click="$set('archiveOpen', true)">{{ __('billing.archive') }}</x-action-button>
                    @endif
                    @if ($canManage && $item->state === \App\Enums\BillingState::Paused)
                        <x-action-button variant="primary" wire:click="resume">{{ __('billing.unpause') }}</x-action-button>
                    @endif
                </div>
            </x-card>
        @endif

        <x-card>
            <x-slot name="title">{{ __('billing.history') }}</x-slot>
            @forelse ($item->payments as $payment)
                <div class="py-2 border-b border-gray-50 last:border-0 text-sm">
                    <span class="font-medium">{{ $payment->type->value === 'paid' ? __('billing.mark_paid') : __('billing.skip') }}</span>
                    · {{ $payment->recorded_on?->format('d.m.Y') }}
                    · {{ $payment->actor?->name }}
                    @if ($payment->amount !== null)
                        · {{ number_format((float) $payment->amount, 2, ',', ' ') }} {{ $payment->currency }}
                    @endif
                    @if ($payment->reason)
                        <div class="text-gray-500">{{ $payment->reason }}</div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">—</p>
            @endforelse
        </x-card>
    </div>

    @if ($payOpen)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <p class="text-sm">{{ __('billing.paid_confirm', ['title' => $item->title(), 'amount' => $item->formattedAmount()]) }}</p>
                <x-text-input wire:model="payAmount" class="w-full" placeholder="{{ __('billing.other_amount') }}" />
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('payOpen', false)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="primary" wire:click="confirmPay">{{ __('billing.yes') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    @if ($skipOpen)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <label class="text-sm">{{ __('billing.skip_reason') }}</label>
                <textarea wire:model="skipReason" class="w-full border-gray-300 rounded-lg text-sm" rows="3"></textarea>
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('skipOpen', false)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="danger" wire:click="confirmSkip">{{ __('billing.skip') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    @if ($archiveOpen)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <label class="text-sm">{{ __('billing.archive_reason') }}</label>
                <textarea wire:model="archiveReason" class="w-full border-gray-300 rounded-lg text-sm" rows="3"></textarea>
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('archiveOpen', false)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="danger" wire:click="confirmArchive">{{ __('billing.archive') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    @if ($pauseOpen)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <x-input-label :value="__('billing.pause')" />
                <x-text-input type="date" wire:model="pausedUntil" class="mt-1 w-full" />
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('pauseOpen', false)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="primary" wire:click="confirmPause">{{ __('billing.pause') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif
</div>
