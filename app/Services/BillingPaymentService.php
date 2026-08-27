<?php

namespace App\Services;

use App\Enums\BillingKind;
use App\Enums\BillingPaymentType;
use App\Enums\BillingState;
use App\Enums\ContentSource;
use App\Enums\TaskStatus;
use App\Models\BillingItem;
use App\Models\BillingPayment;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BillingPaymentService
{
    public function __construct(
        private BillingCycleService $cycle,
        private BillingBot $bot,
        private TaskService $tasks,
        private TaskWorkflowService $workflow,
        private AuditLogService $audit,
        private TaskContentService $content,
    ) {}

    public function markPaid(User $actor, BillingItem $item, ?string $amountOverride = null): BillingItem
    {
        Gate::forUser($actor)->authorize('markPaid', $item);

        if (! $item->kind->canMarkPaid()) {
            throw ValidationException::withMessages(['kind' => [__('billing.already_paid')]]);
        }

        return DB::transaction(function () use ($actor, $item, $amountOverride) {
            /** @var BillingItem $item */
            $item = BillingItem::query()->lockForUpdate()->findOrFail($item->id);

            $cycleDue = $item->next_due_on?->toDateString() ?? $this->cycle->today()->toDateString();

            if ($this->cycleExists($item, $cycleDue)) {
                throw ValidationException::withMessages(['paid' => [__('billing.already_paid')]]);
            }

            $amount = $amountOverride !== null && $amountOverride !== ''
                ? $this->parseAmount($amountOverride)
                : $item->amount;

            $payment = $item->payments()->create([
                'type' => BillingPaymentType::Paid,
                'cycle_due_on' => $cycleDue,
                'recorded_on' => $this->cycle->today()->toDateString(),
                'amount' => $amount,
                'currency' => $item->currency,
                'payment_method' => $item->payment_method,
                'card_last4' => $item->card_last4,
                'actor_user_id' => $actor->id,
                'task_id' => $item->last_task_id,
            ]);

            $this->closeLinkedTask($item, $actor, TaskStatus::Completed, __('billing.task_paid_comment'));

            if ($item->kind === BillingKind::Once) {
                $item->next_due_on = null;
            } elseif (in_array($item->kind, [BillingKind::Subscription, BillingKind::AdBudget], true)) {
                $item->next_due_on = $this->advance($item);
            }

            $item->reminder_7_sent_for = null;
            $item->reminder_3_sent_for = null;
            $item->reminder_overdue_sent_for = null;
            $item->save();

            $this->audit->log('billing.paid', $actor, $item, null, [
                'payment_id' => $payment->id,
                'cycle_due_on' => $cycleDue,
            ]);

            return $item->fresh(['payer', 'owner', 'lastTask', 'payments']);
        });
    }

    public function skip(User $actor, BillingItem $item, string $reason): BillingItem
    {
        Gate::forUser($actor)->authorize('markPaid', $item);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [__('billing.skip_reason')]]);
        }

        if (! $item->kind->canSkip()) {
            throw ValidationException::withMessages(['kind' => [__('billing.skip_reason')]]);
        }

        return DB::transaction(function () use ($actor, $item, $reason) {
            /** @var BillingItem $item */
            $item = BillingItem::query()->lockForUpdate()->findOrFail($item->id);

            $cycleDue = $item->next_due_on?->toDateString()
                ?? $this->cycle->today()->toDateString();

            if ($this->cycleExists($item, $cycleDue)) {
                throw ValidationException::withMessages(['paid' => [__('billing.already_paid')]]);
            }

            $item->payments()->create([
                'type' => BillingPaymentType::Skipped,
                'cycle_due_on' => $cycleDue,
                'recorded_on' => $this->cycle->today()->toDateString(),
                'amount' => $item->amount,
                'currency' => $item->currency,
                'payment_method' => $item->payment_method,
                'card_last4' => $item->card_last4,
                'actor_user_id' => $actor->id,
                'task_id' => $item->last_task_id,
                'reason' => $reason,
            ]);

            $this->closeLinkedTask(
                $item,
                $actor,
                TaskStatus::Cancelled,
                __('billing.task_skipped_comment', ['reason' => $reason]),
            );

            $item->next_due_on = $this->advance($item);
            $item->reminder_7_sent_for = null;
            $item->reminder_3_sent_for = null;
            $item->reminder_overdue_sent_for = null;
            $item->save();

            $this->audit->log('billing.skipped', $actor, $item, null, [
                'cycle_due_on' => $cycleDue,
                'reason' => $reason,
            ]);

            return $item->fresh(['payer', 'owner', 'lastTask', 'payments']);
        });
    }

    public function archive(User $actor, BillingItem $item, string $reason): BillingItem
    {
        Gate::forUser($actor)->authorize('update', $item);

        $item->update([
            'state' => BillingState::Archived,
            'archived_at' => now(),
            'archive_reason' => trim($reason) ?: null,
        ]);

        return $item->fresh();
    }

    public function pause(User $actor, BillingItem $item, ?string $until): BillingItem
    {
        Gate::forUser($actor)->authorize('update', $item);

        $item->update([
            'state' => BillingState::Paused,
            'paused_until' => $until ?: null,
        ]);

        return $item->fresh();
    }

    public function resume(User $actor, BillingItem $item): BillingItem
    {
        Gate::forUser($actor)->authorize('update', $item);

        $item->update([
            'state' => BillingState::Active,
            'paused_until' => null,
        ]);

        return $item->fresh();
    }

    public function resumeExpiredPauses(): int
    {
        $today = $this->cycle->today()->toDateString();

        return BillingItem::query()
            ->where('state', BillingState::Paused)
            ->whereNotNull('paused_until')
            ->whereDate('paused_until', '<', $today)
            ->update([
                'state' => BillingState::Active->value,
                'paused_until' => null,
            ]);
    }

    private function cycleExists(BillingItem $item, string $cycleDue): bool
    {
        return BillingPayment::query()
            ->where('billing_item_id', $item->id)
            ->whereDate('cycle_due_on', $cycleDue)
            ->exists();
    }

    private function advance(BillingItem $item): Carbon
    {
        $from = $item->next_due_on ?? $this->cycle->today();
        $months = (int) ($item->period_months ?: 1);

        return $this->cycle->advanceToFuture($from, $months, $item->due_day_of_month);
    }

    private function parseAmount(string $raw): float
    {
        $normalized = str_replace(["\u{00A0}", ' '], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            throw ValidationException::withMessages(['amount' => [__('billing.amount')]]);
        }

        return round((float) $normalized, 2);
    }

    private function closeLinkedTask(BillingItem $item, User $actor, TaskStatus $to, string $comment): void
    {
        $task = $item->lastTask;
        if (! $task instanceof Task) {
            return;
        }

        try {
            $this->tasks->addComment($task, $actor, $comment, ContentSource::PlainText);
        } catch (AuthorizationException) {
            $bot = $this->bot->user();
            $this->tasks->addComment($task, $bot, $comment, ContentSource::PlainText);
        }

        if (! $task->status->isOpen()) {
            return;
        }

        $bot = $this->bot->user();
        $from = $task->status;

        $updates = ['status' => $to];
        if ($to === TaskStatus::Completed) {
            $updates['completed_at'] = now();
            $updates['closed_by'] = $bot->id;
        }

        $task->update($updates);
        $this->workflow->logHistory($task, 'status', $from->value, $to->value, $bot);
    }
}
