<?php

namespace Tests\Feature;

use App\Enums\BillingKind;
use App\Enums\BillingPaymentMethod;
use App\Enums\BillingState;
use App\Enums\SystemType;
use App\Models\BillingItem;
use App\Models\User;
use App\Services\BillingItemService;
use App\Services\BillingSheetImportService;
use Carbon\Carbon;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class BillingSheetImportTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-27', 'Europe/Kyiv')->startOfDay());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_european_thousands_amount_parses(): void
    {
        $service = app(BillingItemService::class);

        $this->assertSame(37281.6, $service->nullableAmount('37.281,60'));
        $this->assertSame(37281.6, $service->nullableAmount('37 281,60'));
        $this->assertSame(96.35, $service->nullableAmount('96,35'));
    }

    public function test_sheet_import_applies_conflict_rules_and_advances_past_dates(): void
    {
        $this->seedPeople();
        $dir = base_path('tests/fixtures/billing');

        $result = app(BillingSheetImportService::class)->import($dir, dryRun: false);

        $this->assertGreaterThanOrEqual(45, $result['imported']);
        $this->assertSame($result['imported'], BillingItem::query()->count());

        $learndash = BillingItem::query()->where('vendor', 'LearnDash')->sole();
        $this->assertSame('200.00', $learndash->amount);
        $this->assertSame('USD', $learndash->currency);
        $this->assertSame('2027-01-14', $learndash->next_due_on?->toDateString());

        $avantWs = BillingItem::query()->where('product', 'like', '%avant.ws%')->first();
        $this->assertNotNull($avantWs);
        $this->assertSame('2026-09-18', $avantWs->next_due_on?->toDateString());

        $workspace = BillingItem::query()->where('vendor', 'like', 'Google Workspace Avant%')->first();
        $this->assertNotNull($workspace);
        $this->assertSame('980.00', $workspace->amount);
        $this->assertSame('UAH', $workspace->currency);
        $this->assertSame(BillingPaymentMethod::Bank, $workspace->payment_method);
        $this->assertNull($workspace->payer_user_id);

        $hostro = BillingItem::query()->where('vendor', 'like', '%Хостро%')->first();
        $this->assertSame(BillingState::Archived, $hostro?->state);

        $oldDomain = BillingItem::query()
            ->where('vendor', 'like', '%майбутн%')
            ->where('product', 'like', '%домен%')
            ->first();
        $this->assertSame(BillingState::Archived, $oldDomain?->state);

        $vimeo = BillingItem::query()->where('vendor', 'like', 'Vimeo General%')->first();
        $this->assertSame('780.00', $vimeo?->amount);

        $adobe = BillingItem::query()->where('vendor', 'Adobe')->first();
        $this->assertSame('2026-08-30', $adobe?->next_due_on?->toDateString());
        $this->assertNull($adobe?->payer_user_id);

        $this->assertSame(1, BillingItem::query()->where('vendor', 'Cursor')->count());
        $cursor = BillingItem::query()->where('vendor', 'Cursor')->first();
        $this->assertSame('2026-09-26', $cursor?->next_due_on?->toDateString());
        $this->assertSame('crm.manager@tcsavant.com', $cursor?->payer?->email);

        $chat = BillingItem::query()->where('vendor', 'ChatGPT')->first();
        $this->assertNotNull($chat);
        $this->assertTrue($chat->auto_renew);
        $this->assertNull($chat->payer_user_id);

        $eden = BillingItem::query()->where('vendor', 'like', '%edenai%')->first();
        $this->assertSame(BillingKind::OnDemand, $eden?->kind);
        $this->assertNull($eden?->next_due_on);

        $this->assertSame(0, BillingItem::query()->where('vendor', 'like', '%Google ads%')->count());
        $this->assertSame(0, BillingItem::query()->where('amount', 990)->count());
        $this->assertSame(1, BillingItem::query()->where('vendor', 'Ringostat')->count());

        $heygen = BillingItem::query()->where('vendor', 'like', '%Hey%')->where('kind', BillingKind::Subscription)->first();
        $this->assertSame('elearning@tcsavant.com', $heygen?->payer?->email);
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->seedPeople();

        app(BillingSheetImportService::class)->import(base_path('tests/fixtures/billing'), dryRun: true);

        $this->assertSame(0, BillingItem::query()->count());
    }

    private function seedPeople(): void
    {
        $it = $this->createDepartment('IT');
        $edu = $this->createDepartment('Учебный отдел');

        $maxim = User::factory()->create([
            'name' => 'Максим Гольдт',
            'email' => 'crm.manager@tcsavant.com',
            'system_type' => SystemType::Admin,
            'department_id' => $it->id,
            'email_verified_at' => now(),
        ]);
        $it->update(['head_user_id' => $maxim->id]);

        $eduHead = User::factory()->create([
            'name' => 'Коханский',
            'email' => 'head.education@tcsavant.com',
            'system_type' => SystemType::DeptHead,
            'department_id' => $edu->id,
            'email_verified_at' => now(),
        ]);
        $edu->update(['head_user_id' => $eduHead->id]);

        User::factory()->create([
            'name' => 'Назарова Ирина',
            'email' => 'elearning@tcsavant.com',
            'system_type' => SystemType::User,
            'department_id' => $edu->id,
            'email_verified_at' => now(),
        ]);
    }
}
