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
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $open = false;

    public ?BillingItem $item = null;

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

    #[On('open-billing-show')]
    public function openModal(int $id): void
    {
        $item = BillingItem::query()->with(['payer', 'owner', 'lastTask', 'payments.actor'])->findOrFail($id);
        $this->authorize('view', $item);
        $this->item = $item;
        $this->open = true;
        $this->editing = false;
        $this->payOpen = false;
        $this->skipOpen = false;
        $this->archiveOpen = false;
        $this->pauseOpen = false;
        $this->resetErrorBag();
        $this->fillFromItem();
    }

    public function mount(): void
    {
        $id = (int) request()->query('item');
        if ($id > 0) {
            $this->openModal($id);
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->editing = false;
        $this->item = null;
        $this->dispatch('billing-show-closed');
    }

    public function startEdit(): void
    {
        $this->authorize('update', $this->item);
        $this->fillFromItem();
        $this->editing = true;
    }

    public function fillFromItem(): void
    {
        if (! $this->item) {
            return;
        }

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
            $this->dispatch('billing-item-updated');
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
            $this->dispatch('billing-item-updated');
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
            $this->dispatch('billing-item-updated');
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    public function confirmArchive(BillingPaymentService $payments): void
    {
        $this->item = $payments->archive(auth()->user(), $this->item, $this->archiveReason);
        $this->archiveOpen = false;
        $this->archiveReason = '';
        $this->dispatch('billing-item-updated');
    }

    public function confirmPause(BillingPaymentService $payments): void
    {
        $this->item = $payments->pause(auth()->user(), $this->item, $this->pausedUntil !== '' ? $this->pausedUntil : null);
        $this->pauseOpen = false;
        $this->pausedUntil = '';
        $this->dispatch('billing-item-updated');
    }

    public function resume(BillingPaymentService $payments): void
    {
        $this->item = $payments->resume(auth()->user(), $this->item);
        $this->dispatch('billing-item-updated');
    }

    public function with(): array
    {
        if (! $this->item) {
            return [
                'people' => collect(),
                'due' => ['text' => '—', 'relative' => '—', 'date' => null, 'tone' => 'gray', 'class' => 'text-gray-500'],
                'issues' => [],
                'issueSummary' => '',
                'frequency' => '',
                'statusKey' => 'active',
                'statusColor' => 'gray',
                'canManage' => auth()->user()->hasPermission(Permission::ManageBilling),
                'canPay' => false,
                'kinds' => BillingKind::cases(),
                'categories' => BillingCategory::cases(),
                'methods' => BillingPaymentMethod::cases(),
            ];
        }

        $this->item->loadMissing(['payer', 'owner', 'lastTask', 'payments.actor']);
        $issues = $this->item->issues();
        $due = app(BillingItemService::class)->dueMeta($this->item);
        $statusKey = $this->item->derivedStatus();
        if ($statusKey === 'needs_payer') {
            $statusKey = match ($due['tone'] ?? 'gray') {
                'red' => 'overdue',
                'amber' => 'soon',
                default => 'active',
            };
        }

        return [
            'people' => app(BillingBot::class)->peopleQuery()->get(['id', 'name']),
            'due' => $due,
            'issues' => $issues,
            'issueSummary' => collect($issues)
                ->map(fn (array $issue) => __('billing.issue_short.'.$issue['key']))
                ->implode(', '),
            'frequency' => $this->item->frequencyLabel(),
            'statusKey' => $statusKey,
            'statusColor' => match ($statusKey) {
                'overdue' => 'red',
                'soon', 'needs_payer', 'paused' => 'amber',
                'active' => 'green',
                default => 'gray',
            },
            'canManage' => auth()->user()->hasPermission(Permission::ManageBilling),
            'canPay' => auth()->user()->can('markPaid', $this->item),
            'kinds' => BillingKind::cases(),
            'categories' => BillingCategory::cases(),
            'methods' => BillingPaymentMethod::cases(),
        ];
    }
}; ?>

<div>
    @if ($open && $item)
        <div
            class="fixed inset-0 z-50 overflow-y-auto"
            wire:keydown.escape.window="close"
            role="dialog"
            aria-modal="true"
            aria-labelledby="billing-show-title"
        >
            <div class="fixed inset-0 bg-gray-500/75" wire:click="close"></div>
            <div class="relative mx-auto my-6 w-full max-w-2xl px-4">
                <div class="relative bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden flex flex-col max-h-[calc(100vh-3rem)]">
                    <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-gray-100">
                        <div class="min-w-0">
                            <h2 id="billing-show-title" class="text-base font-semibold text-gray-900 truncate">{{ $item->vendor }}</h2>
                            <p class="text-sm text-gray-500 truncate">{{ $item->product }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <x-pill color="indigo">{{ $item->category->label() }}</x-pill>
                                <x-pill>{{ __('billing.kind_short.'.$item->kind->value) }}</x-pill>
                                <x-pill :color="$statusColor">{{ __('billing.status.'.$statusKey) }}</x-pill>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            @if ($canManage && ! $editing)
                                <x-action-button variant="secondary" size="sm" type="button" wire:click="startEdit">{{ __('Edit') }}</x-action-button>
                            @endif
                            <button type="button" wire:click="close" class="text-gray-400 hover:text-gray-700 text-lg leading-none" aria-label="{{ __('Cancel') }}">✕</button>
                        </div>
                    </div>

                    <div class="p-5 space-y-4 overflow-y-auto">
                        @if (session('billing_status'))
                            <p class="text-sm text-green-700 bg-green-50 rounded-lg px-3 py-2">{{ session('billing_status') }}</p>
                        @endif
                        @if (session('billing_error'))
                            <p class="text-sm text-red-700 bg-red-50 rounded-lg px-3 py-2">{{ session('billing_error') }}</p>
                        @endif

                        @if ($editing)
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
                        @else
                            @php
                                $dueTone = $due['tone'] ?? 'gray';
                                $dueBox = match ($dueTone) {
                                    'red' => 'bg-red-50',
                                    'amber' => 'bg-amber-50',
                                    default => 'bg-gray-50',
                                };
                                $dueMuted = match ($dueTone) {
                                    'red' => 'text-red-700/70',
                                    'amber' => 'text-amber-800/70',
                                    default => 'text-gray-500',
                                };
                                $dueStrong = match ($dueTone) {
                                    'red' => 'text-red-900',
                                    'amber' => 'text-amber-950',
                                    default => 'text-gray-900',
                                };
                            @endphp

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-xl bg-indigo-50 px-4 py-3">
                                    <div class="text-[11px] font-medium uppercase tracking-wide text-indigo-700/70">{{ __('billing.amount') }}</div>
                                    <div class="mt-1 text-xl font-semibold text-gray-900 leading-tight">{{ $item->formattedAmount() }}</div>
                                </div>
                                <div class="rounded-xl px-4 py-3 {{ $dueBox }}">
                                    <div class="text-[11px] font-medium uppercase tracking-wide {{ $dueMuted }}">{{ __('billing.next_due') }}</div>
                                    <div class="mt-1 text-xl font-semibold leading-tight {{ $dueStrong }}">{{ $due['relative'] ?? $due['text'] }}</div>
                                    @if (($due['date'] ?? null) && ($due['relative'] ?? '') !== ($due['date'] ?? ''))
                                        <div class="mt-0.5 text-sm {{ $dueStrong }} opacity-80">{{ $due['date'] }}</div>
                                    @endif
                                    @if ($item->state === \App\Enums\BillingState::Paused && $item->paused_until)
                                        <div class="mt-0.5 text-xs {{ $dueStrong }} opacity-80">{{ $item->paused_until->format('d.m.Y') }}</div>
                                    @endif
                                </div>
                            </div>

                            @if ($issues && $issueSummary)
                                <div class="flex items-start justify-between gap-3 rounded-xl bg-amber-50 px-3.5 py-3 ring-1 ring-amber-100">
                                    <p class="text-sm text-amber-900">{{ __('billing.missing_prefix', ['items' => $issueSummary]) }}</p>
                                    @if ($canManage)
                                        <x-action-button variant="secondary" size="sm" type="button" wire:click="startEdit" class="shrink-0">{{ __('billing.fill_missing') }}</x-action-button>
                                    @endif
                                </div>
                            @endif

                            <div class="grid sm:grid-cols-2 gap-2.5">
                                <div class="rounded-lg bg-gray-50 px-3.5 py-2.5">
                                    <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('billing.method_label') }}</div>
                                    <div class="mt-0.5 text-sm font-medium text-gray-900">
                                        {{ $item->payment_method->label() }}
                                        @if ($item->card_last4)
                                            <span class="text-gray-500 font-normal">•••• {{ $item->card_last4 }}</span>
                                        @elseif ($item->payment_method === \App\Enums\BillingPaymentMethod::Card && $canManage)
                                            <button type="button" wire:click="startEdit" class="text-amber-700 hover:underline font-normal">{{ __('billing.specify') }}</button>
                                        @endif
                                    </div>
                                    @if ($item->card_label)
                                        <div class="text-xs text-gray-500">{{ $item->card_label }}</div>
                                    @endif
                                    @if ($item->auto_renew)
                                        <div class="text-xs text-gray-500">{{ __('billing.auto_renew') }}</div>
                                    @endif
                                </div>
                                <div class="rounded-lg bg-gray-50 px-3.5 py-2.5">
                                    <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('billing.kind_label') }}</div>
                                    <div class="mt-0.5 text-sm font-medium text-gray-900">{{ $frequency }}</div>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-3.5 py-2.5">
                                    <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('billing.payer') }}</div>
                                    <div class="mt-0.5 text-sm font-medium text-gray-900">
                                        @if ($item->payer)
                                            {{ $item->payer->name }}
                                        @elseif ($canManage)
                                            <button type="button" wire:click="startEdit" class="text-amber-700 hover:underline font-normal">{{ __('billing.specify') }}</button>
                                        @else
                                            <span class="text-gray-400 font-normal">—</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-3.5 py-2.5">
                                    <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('billing.owner') }}</div>
                                    <div class="mt-0.5 text-sm font-medium text-gray-900">
                                        @if ($item->owner)
                                            {{ $item->owner->name }}
                                        @elseif ($canManage)
                                            <button type="button" wire:click="startEdit" class="text-amber-700 hover:underline font-normal">{{ __('billing.specify') }}</button>
                                        @else
                                            <span class="text-gray-400 font-normal">—</span>
                                        @endif
                                    </div>
                                    @if ($item->owner_label && ! $item->owner)
                                        <div class="text-xs text-gray-500">{{ $item->owner_label }}</div>
                                    @endif
                                </div>
                                @if ($item->account_ref)
                                    <div class="rounded-lg bg-gray-50 px-3.5 py-2.5">
                                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('billing.account_ref') }}</div>
                                        <div class="mt-0.5 text-sm font-medium text-gray-900 break-all">{{ $item->account_ref }}</div>
                                    </div>
                                @endif
                                @if ($item->vat_note)
                                    <div class="rounded-lg bg-gray-50 px-3.5 py-2.5">
                                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('billing.vat_note') }}</div>
                                        <div class="mt-0.5 text-sm font-medium text-gray-900">{{ $item->vat_note }}</div>
                                    </div>
                                @endif
                            </div>

                            @if ($item->description)
                                <p class="text-sm text-gray-600">{{ $item->description }}</p>
                            @endif

                            @if ($item->notes)
                                <div class="rounded-lg border border-gray-100 bg-white px-3.5 py-3">
                                    <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">{{ __('billing.notes') }}</div>
                                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $item->notes }}</p>
                                </div>
                            @endif

                            @if ($item->portal_url || $item->lastTask)
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                    @if ($item->portal_url)
                                        <a href="{{ $item->portal_url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-700 hover:underline">{{ __('billing.open_portal') }}</a>
                                    @endif
                                    @if ($item->lastTask)
                                        <a href="{{ route('tasks.show', $item->lastTask) }}" wire:navigate class="text-indigo-700 hover:underline">{{ __('billing.open_task') }} #{{ $item->lastTask->number }}</a>
                                    @endif
                                </div>
                            @endif
                        @endif

                        <div class="border-t border-gray-100 pt-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-2">{{ __('billing.history') }}</h3>
                            @forelse ($item->payments->sortByDesc('id') as $payment)
                                <div class="py-2 border-b border-gray-50 last:border-0 text-sm flex flex-wrap items-baseline gap-x-2">
                                    <span class="font-medium text-gray-900">{{ $payment->type->label() }}</span>
                                    <span class="text-gray-500">{{ $payment->recorded_on?->format('d.m.Y') }}</span>
                                    @if ($payment->actor)
                                        <span class="text-gray-500">{{ $payment->actor->name }}</span>
                                    @endif
                                    @if ($payment->amount !== null)
                                        <span class="text-gray-700">{{ number_format((float) $payment->amount, 2, ',', ' ') }} {{ $payment->currency }}</span>
                                    @endif
                                    @if ($payment->reason)
                                        <div class="w-full text-gray-500">{{ $payment->reason }}</div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-400">{{ __('billing.history_empty') }}</p>
                            @endforelse
                        </div>
                    </div>

                    @if (! $editing)
                        <div class="border-t border-gray-100 px-5 py-3 flex flex-wrap items-center gap-2 bg-white">
                            @if ($item->kind->canMarkPaid() && $canPay && $item->state === \App\Enums\BillingState::Active)
                                <x-action-button variant="primary" wire:click="openPay">{{ __('billing.mark_paid') }}</x-action-button>
                                @if ($item->kind->canSkip())
                                    <x-action-button variant="ghost" wire:click="$set('skipOpen', true)">{{ __('billing.skip') }}</x-action-button>
                                @endif
                            @endif
                            @if ($canManage && $item->state === \App\Enums\BillingState::Active)
                                <x-action-button variant="secondary" wire:click="$set('pauseOpen', true)">{{ __('billing.pause') }}</x-action-button>
                                <button type="button" wire:click="$set('archiveOpen', true)" class="ml-auto text-sm text-red-700 hover:text-red-900 px-2 py-1.5">{{ __('billing.archive') }}</button>
                            @endif
                            @if ($canManage && $item->state === \App\Enums\BillingState::Paused)
                                <x-action-button variant="primary" wire:click="resume">{{ __('billing.unpause') }}</x-action-button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($payOpen && $item)
        <div class="fixed inset-0 z-[60] bg-black/30 flex items-center justify-center p-4">
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

    @if ($skipOpen && $item)
        <div class="fixed inset-0 z-[60] bg-black/30 flex items-center justify-center p-4">
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

    @if ($archiveOpen && $item)
        <div class="fixed inset-0 z-[60] bg-black/30 flex items-center justify-center p-4">
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

    @if ($pauseOpen && $item)
        <div class="fixed inset-0 z-[60] bg-black/30 flex items-center justify-center p-4">
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
