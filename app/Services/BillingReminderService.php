<?php

namespace App\Services;

use App\Enums\BillingDueDayRule;
use App\Enums\BillingKind;
use App\Enums\BillingState;
use App\Enums\ContentSource;
use App\Models\BillingItem;
use App\Models\Category;
use App\Models\User;
use App\Notifications\BillingDueNotification;

class BillingReminderService
{
    public function __construct(
        private BillingCycleService $cycle,
        private BillingBot $bot,
        private BillingPaymentService $payments,
        private TaskService $tasks,
    ) {}

    public function run(bool $dryRun = false): array
    {
        $resumed = $dryRun ? 0 : $this->payments->resumeExpiredPauses();

        $today = $this->cycle->today();
        $created = 0;
        $due7 = 0;
        $due3 = 0;
        $overdue = 0;

        $items = BillingItem::query()
            ->with(['payer', 'owner'])
            ->where('state', BillingState::Active)
            ->whereIn('kind', [BillingKind::Subscription, BillingKind::AdBudget, BillingKind::Once])
            ->whereNotNull('next_due_on')
            ->whereNotNull('payer_user_id')
            ->get();

        foreach ($items as $item) {
            if ($item->blocksReminders()) {
                continue;
            }

            $due = $item->next_due_on->timezone(config('app.timezone'))->startOfDay();
            $until = $item->due_day_rule === BillingDueDayRule::Until;
            $remind7 = $this->cycle->reminderDate($due, 7, $until);
            $remind3 = $this->cycle->reminderDate($due, 3, $until);

            if ($today->gte($due)) {
                if ($item->reminder_overdue_sent_for?->toDateString() !== $due->toDateString()) {
                    $overdue++;
                    if (! $dryRun) {
                        $this->notify($item, 'billing.overdue');
                        $item->update(['reminder_overdue_sent_for' => $due->toDateString()]);
                    }
                }

                continue;
            }

            if ($today->gte($remind7) && $item->reminder_7_sent_for?->toDateString() !== $due->toDateString()) {
                $due7++;
                if (! $dryRun) {
                    if ($this->ensureTask($item)) {
                        $created++;
                    }
                    $item->refresh();
                    $this->notify($item, 'billing.due_7');
                    $item->update(['reminder_7_sent_for' => $due->toDateString()]);
                }
            }

            if ($today->gte($remind3) && $item->reminder_3_sent_for?->toDateString() !== $due->toDateString()) {
                $due3++;
                if (! $dryRun) {
                    $this->notify($item, 'billing.due_3');
                    $item->update(['reminder_3_sent_for' => $due->toDateString()]);
                }
            }
        }

        return compact('resumed', 'due7', 'due3', 'overdue', 'created');
    }

    public function ensureTask(BillingItem $item): bool
    {
        $item->loadMissing(['payer', 'owner', 'lastTask']);

        $open = $item->lastTask && $item->lastTask->status->isOpen();
        if ($open) {
            return false;
        }

        $payer = $item->payer;
        if (! $payer || ! $payer->department_id) {
            return false;
        }

        $category = Category::query()->firstOrCreate(
            ['name' => 'Оплаты'],
            ['is_active' => true, 'sort_order' => 90],
        );

        $bot = $this->bot->user();
        $date = $item->next_due_on->timezone(config('app.timezone'))->format('d.m.Y');
        $watchers = [];
        if ($item->owner_user_id && $item->owner_user_id !== $payer->id) {
            $watchers[] = $item->owner_user_id;
        }

        $lines = [
            $item->title(),
            $item->formattedAmount(),
            $item->payment_method->label().($item->card_last4 ? ' •••• '.$item->card_last4 : ''),
        ];
        if ($item->portal_url) {
            $lines[] = $item->portal_url;
        }
        if ($item->account_ref) {
            $lines[] = $item->account_ref;
        }
        $lines[] = url('/billing/'.$item->id);

        $task = $this->tasks->create($bot, [
            'title' => __('billing.task_title', [
                'vendor' => $item->vendor,
                'product' => $item->product,
                'date' => $date,
                'amount' => $item->formattedAmount(),
            ]),
            'description' => implode("\n", $lines),
            'department_id' => $payer->department_id,
            'category_id' => $category->id,
            'assignee_id' => $payer->id,
            'priority' => 7,
            'deadline' => $item->next_due_on->toDateString(),
        ], [], $watchers, ContentSource::PlainText);

        $item->update(['last_task_id' => $task->id]);

        return true;
    }

    private function notify(BillingItem $item, string $event): void
    {
        $recipients = collect([$item->payer, $item->owner])
            ->filter()
            ->unique('id');

        $recipients->each(function (User $user) use ($item, $event): void {
            if ($this->bot->is($user) || ! $user->is_active) {
                return;
            }

            $user->notify(new BillingDueNotification($item, $event));
        });
    }
}
