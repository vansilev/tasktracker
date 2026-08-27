<?php

use App\Enums\BillingKind;
use App\Enums\BillingState;
use App\Enums\Permission;
use App\Models\BillingItem;
use App\Services\BillingCycleService;
use App\Services\BillingItemService;
use App\Services\BillingPaymentService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.tasks-layout')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'active';

    #[Url]
    public string $search = '';

    public ?int $payItemId = null;

    public string $payAmount = '';

    public ?int $skipItemId = null;

    public string $skipReason = '';

    public function with(): array
    {
        $today = app(BillingCycleService::class)->today();
        $query = BillingItem::query()->with(['payer', 'owner', 'lastTask']);

        match ($this->tab) {
            'overdue' => $query->where('state', BillingState::Active)
                ->whereNotNull('next_due_on')
                ->whereDate('next_due_on', '<', $today->toDateString())
                ->where('kind', '!=', BillingKind::Lifetime),
            'soon' => $query->where('state', BillingState::Active)
                ->whereNotNull('next_due_on')
                ->whereDate('next_due_on', '>=', $today->toDateString())
                ->whereDate('next_due_on', '<=', $today->copy()->addDays(7)->toDateString()),
            'on_demand' => $query->where('state', BillingState::Active)->where('kind', BillingKind::OnDemand),
            'lifetime' => $query->where('state', BillingState::Active)->where('kind', BillingKind::Lifetime),
            'paused' => $query->where('state', BillingState::Paused),
            'archived' => $query->where('state', BillingState::Archived),
            default => $query->where('state', BillingState::Active)->where('kind', '!=', BillingKind::Lifetime),
        };

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('vendor', 'like', $term)->orWhere('product', 'like', $term);
            });
        }

        $items = $query
            ->orderByRaw('CASE WHEN next_due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_due_on')
            ->orderBy('vendor')
            ->paginate(50);

        $active = BillingItem::query()->where('state', BillingState::Active);
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        $monthItems = (clone $active)
            ->whereNotNull('next_due_on')
            ->whereBetween('next_due_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereNotNull('payer_user_id')
            ->get();

        $totals = [];
        $invoiceCount = 0;
        $adsCount = 0;
        foreach ($monthItems as $row) {
            if ($row->kind === BillingKind::AdBudget) {
                $adsCount++;

                continue;
            }
            if ($row->amount === null) {
                $invoiceCount++;

                continue;
            }
            $cur = $row->currency ?: 'UAH';
            $totals[$cur] = ($totals[$cur] ?? 0) + (float) $row->amount;
        }

        return [
            'items' => $items,
            'totals' => $totals,
            'invoiceCount' => $invoiceCount,
            'adsCount' => $adsCount,
            'unassignedCount' => (clone $active)->whereNull('payer_user_id')->where('kind', '!=', BillingKind::Lifetime)->count(),
            'canManage' => auth()->user()->hasPermission(Permission::ManageBilling),
            'meta' => app(BillingItemService::class),
            'payItem' => $this->payItemId ? BillingItem::query()->find($this->payItemId) : null,
        ];
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openPay(int $id): void
    {
        $item = BillingItem::query()->findOrFail($id);
        $this->authorize('markPaid', $item);
        $this->payItemId = $id;
        $this->payAmount = $item->amount !== null ? (string) $item->amount : '';
    }

    public function confirmPay(): void
    {
        $item = BillingItem::query()->findOrFail($this->payItemId);
        try {
            $updated = app(BillingPaymentService::class)->markPaid(auth()->user(), $item, $this->payAmount !== '' ? $this->payAmount : null);
            $this->payItemId = null;
            session()->flash('billing_status', $updated->next_due_on
                ? __('billing.paid_saved', ['date' => $updated->next_due_on->format('d.m.Y')])
                : __('billing.paid_saved_once'));
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    public function openSkip(int $id): void
    {
        $item = BillingItem::query()->findOrFail($id);
        $this->authorize('markPaid', $item);
        $this->skipItemId = $id;
        $this->skipReason = '';
    }

    public function confirmSkip(): void
    {
        $item = BillingItem::query()->findOrFail($this->skipItemId);
        try {
            app(BillingPaymentService::class)->skip(auth()->user(), $item, $this->skipReason);
            $this->skipItemId = null;
        } catch (ValidationException $e) {
            session()->flash('billing_error', collect($e->errors())->flatten()->first());
        }
    }

    #[Url(as: 'item')]
    public ?int $openItem = null;

    #[On('billing-item-created')]
    #[On('billing-item-updated')]
    public function refreshAfterCreate(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        if ($this->openItem) {
            $this->dispatch('open-billing-show', id: $this->openItem);
        }
    }

    public function openCreate(): void
    {
        $this->authorize('create', BillingItem::class);
        $this->dispatch('open-billing-create');
    }

    public function openShow(int $id): void
    {
        $this->authorize('view', BillingItem::query()->findOrFail($id));
        $this->openItem = $id;
        $this->dispatch('open-billing-show', id: $id);
    }

    #[On('billing-show-closed')]
    public function clearOpenItem(): void
    {
        $this->openItem = null;
    }
}; ?>

<div>
    <x-slot name="title">{{ __('billing.nav') }}</x-slot>
    @if ($canManage)
        <button type="button" id="billing-open-create" class="sr-only" tabindex="-1" wire:click="openCreate">
            {{ __('billing.create') }}
        </button>
        <x-slot name="headerActions">
            <x-action-button variant="primary" size="md" type="button" onclick="document.getElementById('billing-open-create')?.click()">
                {{ __('billing.create') }}
            </x-action-button>
        </x-slot>
    @endif

    <div class="space-y-4">
        @if (session('billing_status'))
            <p class="text-sm text-green-700 bg-green-50 rounded-lg px-3 py-2">{{ session('billing_status') }}</p>
        @endif
        @if (session('billing_error'))
            <p class="text-sm text-red-700 bg-red-50 rounded-lg px-3 py-2">{{ session('billing_error') }}</p>
        @endif

        <div class="bg-white rounded-xl border border-gray-100 px-4 py-3 text-sm text-gray-700 flex flex-wrap gap-3">
            @forelse ($totals as $currency => $sum)
                <span class="font-medium">{{ number_format($sum, 2, ',', ' ') }} {{ $currency }}</span>
            @empty
                <span class="text-gray-500">—</span>
            @endforelse
            @if ($invoiceCount)
                <span class="text-gray-500">{{ __('billing.summary_invoice', ['count' => $invoiceCount]) }}</span>
            @endif
            @if ($adsCount)
                <span class="text-gray-500">{{ __('billing.summary_ads', ['count' => $adsCount]) }}</span>
            @endif
            @if ($unassignedCount)
                <span class="text-amber-700">{{ __('billing.summary_unassigned', ['count' => $unassignedCount]) }}</span>
            @endif
        </div>

        <nav class="flex items-center gap-1 overflow-x-auto">
            @foreach (['overdue', 'soon', 'active', 'on_demand', 'lifetime', 'paused', 'archived'] as $key)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                        class="shrink-0 px-3 py-1.5 rounded-lg text-sm font-medium {{ $tab === $key ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50' }}">
                    {{ __('billing.tab.'.$key) }}
                </button>
            @endforeach
        </nav>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <x-text-input wire:model.live.debounce.300ms="search" class="w-full rounded-lg" placeholder="{{ __('billing.search') }}" />
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            @if ($items->isEmpty())
                <x-empty-state>
                    {{ __('billing.empty') }}
                    @if ($canManage)
                        <x-slot name="action">
                            <x-action-button variant="primary" type="button" wire:click="openCreate">
                                {{ __('billing.create') }}
                            </x-action-button>
                        </x-slot>
                    @endif
                </x-empty-state>
            @else
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500">{{ __('billing.vendor') }}</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500">{{ __('billing.amount') }}</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500">{{ __('billing.next_due') }}</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500">{{ __('billing.method_label') }}</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500">{{ __('billing.payer') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($items as $item)
                                @php
                                    $due = $meta->dueMeta($item);
                                    $issues = $item->issues();
                                @endphp
                                <tr class="hover:bg-gray-50 cursor-pointer" wire:click="openShow({{ $item->id }})" role="button">
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium text-gray-900">{{ $item->title() }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->category->label() }} · {{ __('billing.kind_short.'.$item->kind->value) }}</div>
                                        @if ($issues)
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($issues as $issue)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 ring-1 ring-amber-200">{{ $issue['label'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">{{ $item->formattedAmount() }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap {{ $due['class'] }}">{{ $due['text'] }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        {{ $item->payment_method->label() }}
                                        @if ($item->card_last4)
                                            <span class="text-gray-500">•••• {{ $item->card_last4 }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-gray-600">
                                        {{ $item->payer?->name ?? '—' }}
                                        @if ($item->owner && $item->owner_user_id !== $item->payer_user_id)
                                            <div>{{ $item->owner->name }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap" wire:click.stop>
                                        @if ($item->kind->canMarkPaid() && auth()->user()->can('markPaid', $item))
                                            <x-action-button variant="secondary" size="sm" wire:click.stop="openPay({{ $item->id }})">{{ __('billing.mark_paid') }}</x-action-button>
                                            @if ($item->kind->canSkip())
                                                <x-action-button variant="ghost" size="sm" wire:click.stop="openSkip({{ $item->id }})">{{ __('billing.skip') }}</x-action-button>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="md:hidden divide-y divide-gray-100">
                    @foreach ($items as $item)
                        @php $due = $meta->dueMeta($item); $issues = $item->issues(); @endphp
                        <button type="button" wire:click="openShow({{ $item->id }})" class="block w-full text-left p-4">
                            <div class="font-medium">{{ $item->title() }}</div>
                            <div class="text-sm {{ $due['class'] }}">{{ $item->formattedAmount() }} · {{ $due['text'] }}</div>
                            @foreach ($issues as $issue)
                                <div class="mt-1 text-xs text-amber-800 bg-amber-50 rounded px-2 py-1">{{ $issue['label'] }}</div>
                            @endforeach
                        </button>
                    @endforeach
                </div>
                <div class="px-4 py-3">{{ $items->links() }}</div>
            @endif
        </div>
    </div>

    @if ($payItemId)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <p class="text-sm">{{ __('billing.paid_confirm', ['title' => $payItem?->title(), 'amount' => $payItem?->formattedAmount()]) }}</p>
                <x-text-input wire:model="payAmount" class="w-full" placeholder="{{ __('billing.other_amount') }}" />
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('payItemId', null)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="primary" wire:click="confirmPay">{{ __('billing.yes') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    @if ($skipItemId)
        <div class="fixed inset-0 z-40 bg-black/30 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl p-5 w-full max-w-sm space-y-3">
                <label class="text-sm">{{ __('billing.skip_reason') }}</label>
                <textarea wire:model="skipReason" class="w-full border-gray-300 rounded-lg text-sm" rows="3"></textarea>
                <div class="flex gap-2 justify-end">
                    <x-action-button variant="ghost" wire:click="$set('skipItemId', null)">{{ __('Cancel') }}</x-action-button>
                    <x-action-button variant="danger" wire:click="confirmSkip">{{ __('billing.skip') }}</x-action-button>
                </div>
            </div>
        </div>
    @endif

    <livewire:pages.billing.show />
    @if ($canManage)
        <livewire:pages.billing.create />
    @endif
</div>
