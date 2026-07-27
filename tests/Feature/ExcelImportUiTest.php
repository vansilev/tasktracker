<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class ExcelImportUiTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private const IMPORT_NUMBER = 8801;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_admin_can_access_import_page(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/import')
            ->assertOk()
            ->assertSee(__('Excel import'));
    }

    public function test_regular_user_cannot_access_import_page(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/admin/import')
            ->assertForbidden();
    }

    public function test_dry_run_shows_expected_counts_without_creating_tasks(): void
    {
        [$admin, $upload] = $this->prepareImportFixtures();

        $this->actingAs($admin);

        Volt::test('pages.admin.import')
            ->set('importFile', $upload)
            ->call('dryRun')
            ->assertHasNoErrors()
            ->assertSet('dryRunCompleted', true)
            ->assertSet('report.imported', 1)
            ->assertSet('report.skipped', []);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_import_after_dry_run_creates_tasks_and_writes_audit_log(): void
    {
        [$admin, $upload] = $this->prepareImportFixtures();

        $this->actingAs($admin);

        Volt::test('pages.admin.import')
            ->set('importFile', $upload)
            ->call('dryRun')
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('importCompleted', true)
            ->assertSet('report.imported', 1);

        $task = Task::query()->where('number', self::IMPORT_NUMBER)->first();
        $this->assertNotNull($task);
        $this->assertSame(self::IMPORT_NUMBER, $task->number);

        $log = AuditLog::query()->where('action', 'tasks.imported')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('import.xlsx', $log->new_values['filename']);
        $this->assertSame(1, $log->new_values['imported']);
        $this->assertSame(0, $log->new_values['skipped']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reimport_same_file_reports_already_exists(): void
    {
        [$admin, $upload] = $this->prepareImportFixtures();

        $this->actingAs($admin);

        Volt::test('pages.admin.import')
            ->set('importFile', $upload)
            ->call('dryRun')
            ->call('import')
            ->assertHasNoErrors();

        $secondUpload = $this->makeUploadedSpreadsheet();

        Volt::test('pages.admin.import')
            ->set('importFile', $secondUpload)
            ->call('dryRun')
            ->assertHasNoErrors()
            ->assertSet('report.imported', 0)
            ->assertSet('report.skipped', [
                ['row' => 2, 'number' => self::IMPORT_NUMBER, 'reason' => 'already_exists'],
            ]);
    }

    public function test_non_xlsx_file_is_rejected_by_validation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.admin.import')
            ->set('importFile', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertHasErrors(['importFile']);
    }

    /** @return array{0: \App\Models\User, 1: UploadedFile} */
    private function prepareImportFixtures(): array
    {
        $dept = $this->createDepartment('IT');
        $admin = $this->createUserInDepartment($dept, 'Admin', SystemType::Admin);
        config(['tasktracker.admin_email' => $admin->email]);

        Category::query()->create([
            'name' => 'Прочие задачи',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$admin, $this->makeUploadedSpreadsheet()];
    }

    private function makeUploadedSpreadsheet(): UploadedFile
    {
        $path = $this->makeSpreadsheetPath();

        return UploadedFile::fake()->createWithContent(
            'import.xlsx',
            (string) file_get_contents($path),
        );
    }

    private function makeSpreadsheetPath(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Лист задач');

        $sheet->setCellValue([1, 2], self::IMPORT_NUMBER);
        $sheet->setCellValue([3, 2], 'UI Import Initiator');
        $sheet->setCellValue([4, 2], 'IT');
        $sheet->setCellValue([5, 2], 'UI import test task description');
        $sheet->setCellValue([6, 2], 'Прочие задачи');
        $sheet->setCellValue([8, 2], 'В работе');

        $path = sys_get_temp_dir().'/task-import-ui-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function createAdmin(): \App\Models\User
    {
        return \App\Models\User::factory()->create([
            'email' => 'import-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
