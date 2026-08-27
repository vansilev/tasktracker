<?php

namespace Tests\Feature;

use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingPaymentType;
use App\Enums\BillingState;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\BillingItem;
use App\Models\BillingPayment;
use App\Models\Task;
use App\Models\User;
use App\Services\BillingCycleService;
use App\Services\BillingItemService;
use App\Services\BillingPaymentService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_without_permission_cannot_open_billing(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/billing')
            ->assertForbidden();
    }

    public function test_admin_can_open_billing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/billing')
            ->assertOk()
            ->assertSee(__('billing.nav'));
    }

    public function test_create_url_stays_on_billing_list(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/billing/create')
            ->assertRedirect('/billing');
    }

    public function test_index_can_open_create_popup(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);
        Volt::test('pages.billing.index')
            ->call('openCreate')
            ->assertDispatched('open-billing-create');
    }

    public function test_missing_fields_show_yellow_needs_fill_badges(): void
    {
        $admin = $this->admin();
        BillingItem::factory()->create([
            'vendor' => 'EdenAI',
            'product' => 'API',
            'kind' => BillingKind::Subscription,
            'period_months' => 1,
            'next_due_on' => now()->addDays(10)->toDateString(),
            'payment_method' => BillingPaymentMethod::Card,
            'card_last4' => null,
            'payer_user_id' => null,
            'owner_user_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/billing')
            ->assertOk()
            ->assertSee(__('billing.issue.payer'))
            ->assertSee(__('billing.issue.owner'))
            ->assertSee(__('billing.issue.card_last4'));
    }

    public function test_empty_amount_is_invoice_not_yellow(): void
    {
        $admin = $this->admin();
        BillingItem::factory()->create([
            'vendor' => 'Київстар',
            'product' => 'связь',
            'amount' => null,
            'currency' => null,
            'payment_method' => BillingPaymentMethod::Bank,
            'card_last4' => null,
            'payer_user_id' => $admin->id,
            'owner_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/billing')
            ->assertOk()
            ->assertSee(__('billing.invoice_amount'))
            ->assertDontSee(__('billing.issue.card_last4'));
    }

    public function test_card_last4_must_be_four_digits(): void
    {
        $this->expectException(ValidationException::class);

        app(BillingItemService::class)->save(null, [
            'vendor' => 'Hostinger',
            'product' => 'Cloud',
            'category' => 'hosting',
            'kind' => 'subscription',
            'period_months' => 1,
            'amount' => 10,
            'currency' => 'USD',
            'next_due_on' => now()->addMonth()->toDateString(),
            'payment_method' => 'card',
            'card_last4' => '12',
        ]);
    }

    public function test_paid_advances_late_subscription_past_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $admin = $this->admin();
        $item = BillingItem::factory()->create([
            'kind' => BillingKind::Subscription,
            'period_months' => 1,
            'next_due_on' => '2026-06-10',
            'due_day_of_month' => 10,
            'payer_user_id' => $admin->id,
        ]);

        $updated = app(BillingPaymentService::class)->markPaid($admin, $item);

        $this->assertSame('2026-09-10', $updated->next_due_on->toDateString());
        $this->assertSame(1, $item->payments()->where('type', BillingPaymentType::Paid)->count());
    }

    public function test_yearly_paid_in_august_moves_to_next_january(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $admin = $this->admin();
        $item = BillingItem::factory()->create([
            'vendor' => 'LearnDash',
            'kind' => BillingKind::Subscription,
            'period_months' => 12,
            'next_due_on' => '2026-01-14',
            'due_day_of_month' => 14,
            'payer_user_id' => $admin->id,
        ]);

        $updated = app(BillingPaymentService::class)->markPaid($admin, $item);

        $this->assertSame('2027-01-14', $updated->next_due_on->toDateString());
    }

    public function test_once_paid_clears_date_skip_advances(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $admin = $this->admin();
        $item = BillingItem::factory()->create([
            'kind' => BillingKind::Once,
            'period_months' => null,
            'next_due_on' => '2026-07-01',
            'payer_user_id' => $admin->id,
        ]);

        $paid = app(BillingPaymentService::class)->markPaid($admin, $item);
        $this->assertNull($paid->next_due_on);
        $this->assertSame(BillingState::Active, $paid->state);

        $other = BillingItem::factory()->create([
            'kind' => BillingKind::Once,
            'period_months' => null,
            'next_due_on' => '2026-07-01',
            'payer_user_id' => $admin->id,
        ]);
        $skipped = app(BillingPaymentService::class)->skip($admin, $other, 'не нужен в этом цикле');
        $this->assertSame('2026-09-01', $skipped->next_due_on->toDateString());
    }

    public function test_second_paid_for_same_cycle_is_rejected(): void
    {
        $admin = $this->admin();
        $item = BillingItem::factory()->create([
            'next_due_on' => now()->addDays(3)->toDateString(),
            'payer_user_id' => $admin->id,
        ]);
        $cycleDue = $item->next_due_on->toDateString();

        app(BillingPaymentService::class)->markPaid($admin, $item);
        BillingItem::query()->whereKey($item->id)->update(['next_due_on' => $cycleDue]);

        $this->expectException(ValidationException::class);
        app(BillingPaymentService::class)->markPaid($admin, $item->fresh());
    }

    public function test_due_day_31_clamps_in_february(): void
    {
        $date = app(BillingCycleService::class)->clampDay(Carbon::parse('2026-02-01', 'Europe/Kyiv'), 31);

        $this->assertSame('2026-02-28', $date->toDateString());
    }

    public function test_reminders_are_idempotent_and_create_one_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $dept = $this->createDepartment('IT');
        $payer = $this->createUserInDepartment($dept, 'Payer');
        $item = BillingItem::factory()->create([
            'vendor' => 'Kommo',
            'product' => 'CRM',
            'next_due_on' => '2026-08-27',
            'payer_user_id' => $payer->id,
            'owner_user_id' => $payer->id,
        ]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(1, Task::query()->count());
        $this->assertTrue(
            $payer->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'billing.due_7'),
        );

        $taskId = $item->fresh()->last_task_id;
        $count = $payer->fresh()->notifications->count();

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(1, Task::query()->count());
        $this->assertSame($taskId, $item->fresh()->last_task_id);
        $this->assertSame($count, $payer->fresh()->notifications->count());
    }

    public function test_due_three_notifies_without_second_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $dept = $this->createDepartment('IT');
        $payer = $this->createUserInDepartment($dept, 'Payer Three');
        BillingItem::factory()->create([
            'next_due_on' => '2026-08-27',
            'payer_user_id' => $payer->id,
        ]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(1, Task::query()->count());

        Carbon::setTestNow(Carbon::parse('2026-08-24', 'Europe/Kyiv')->startOfDay());
        $this->artisan('billing:send-reminders')->assertSuccessful();

        $this->assertSame(1, Task::query()->count());
        $this->assertTrue(
            $payer->fresh()->notifications->contains(fn ($n) => ($n->data['event'] ?? '') === 'billing.due_3'),
        );
    }

    public function test_empty_payer_does_not_create_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        BillingItem::factory()->create([
            'next_due_on' => '2026-08-27',
            'payer_user_id' => null,
        ]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_overdue_first_run_does_not_create_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $dept = $this->createDepartment('IT');
        $payer = $this->createUserInDepartment($dept, 'Late Payer');
        $item = BillingItem::factory()->create([
            'next_due_on' => '2026-06-10',
            'payer_user_id' => $payer->id,
        ]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(0, Task::query()->count());
        $this->assertSame('2026-06-10', $item->fresh()->reminder_overdue_sent_for?->toDateString());
    }

    public function test_archived_and_paused_are_skipped_by_cron(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $dept = $this->createDepartment('IT');
        $payer = $this->createUserInDepartment($dept, 'Paused Payer');

        BillingItem::factory()->create([
            'state' => BillingState::Archived,
            'next_due_on' => '2026-08-27',
            'payer_user_id' => $payer->id,
        ]);
        BillingItem::factory()->create([
            'state' => BillingState::Paused,
            'paused_until' => '2026-09-01',
            'next_due_on' => '2026-08-27',
            'payer_user_id' => $payer->id,
        ]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_pause_until_resumes_on_cron(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20', 'Europe/Kyiv')->startOfDay());
        $item = BillingItem::factory()->create([
            'state' => BillingState::Paused,
            'paused_until' => '2026-08-19',
            'next_due_on' => '2026-09-10',
        ]);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->assertSame(BillingState::Active, $item->fresh()->state);
    }

    public function test_completing_task_manually_does_not_record_payment(): void
    {
        $dept = $this->createDepartment('IT');
        $payer = $this->createUserInDepartment($dept, 'Manual Closer');
        $item = BillingItem::factory()->create([
            'next_due_on' => '2026-09-10',
            'payer_user_id' => $payer->id,
        ]);
        $task = $this->createTask($payer, $payer, $this->createCategory());
        $item->update(['last_task_id' => $task->id]);
        $due = $item->next_due_on->toDateString();

        $task->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

        $this->assertSame(0, BillingPayment::query()->count());
        $this->assertSame($due, $item->fresh()->next_due_on->toDateString());
    }

    public function test_payer_without_view_billing_can_mark_own_task_but_not_others(): void
    {
        $dept = $this->createDepartment('IT');
        $payer = $this->createUserInDepartment($dept, 'Own Payer');
        $stranger = $this->createUserInDepartment($dept, 'Stranger');
        $own = BillingItem::factory()->create([
            'next_due_on' => now()->addDays(5)->toDateString(),
            'payer_user_id' => $payer->id,
        ]);
        $other = BillingItem::factory()->create([
            'next_due_on' => now()->addDays(5)->toDateString(),
            'payer_user_id' => $stranger->id,
        ]);
        $task = $this->createTask($payer, $payer, $this->createCategory());
        $own->update(['last_task_id' => $task->id]);

        $this->actingAs($payer)
            ->get('/billing')
            ->assertForbidden();

        $this->actingAs($payer);
        Volt::test('pages.tasks.show', ['task' => $task])
            ->assertSee(__('billing.mark_paid'))
            ->call('openBillingPay')
            ->call('confirmBillingPay')
            ->assertHasNoErrors();

        $this->assertSame(1, $own->payments()->count());

        $this->expectException(AuthorizationException::class);
        app(BillingPaymentService::class)->markPaid($payer, $other);
    }

    public function test_create_wizard_saves_item(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);
        Volt::test('pages.billing.create')
            ->call('openModal')
            ->assertSet('open', true)
            ->set('vendor', 'Hostinger')
            ->set('product', 'KVM 2')
            ->call('nextStep')
            ->set('amount', '12 355,20')
            ->set('currency', 'UAH')
            ->call('nextStep')
            ->set('kind', BillingKind::Subscription->value)
            ->set('periodMonths', 12)
            ->set('nextDueOn', now()->addMonth()->toDateString())
            ->call('nextStep')
            ->set('paymentMethod', BillingPaymentMethod::Bank->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false);

        $this->assertDatabaseHas('billing_items', [
            'vendor' => 'Hostinger',
            'product' => 'KVM 2',
            'currency' => 'UAH',
        ]);
    }

    private function admin(): User
    {
        $dept = $this->createDepartment('IT');
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin-billing@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
            'department_id' => $dept->id,
        ]);

        return $admin;
    }
}
