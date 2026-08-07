<?php

namespace Tests\Feature;

use App\Enums\ContentFormat;
use App\Models\Category;
use App\Models\Task;
use App\Services\ExcelTaskImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class ExcelTaskImportTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_imported_task_receives_title_from_description(): void
    {
        $dept = $this->createDepartment('IT');
        $admin = $this->createUserInDepartment($dept, 'Admin');
        config(['tasktracker.admin_email' => $admin->email]);
        Category::query()->create([
            'name' => 'Прочие задачи',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $description = trim(str_repeat('Import title test sentence. ', 20));
        $path = $this->makeSpreadsheet($description);

        app(ExcelTaskImportService::class)->import($path, dryRun: false);

        $task = Task::query()->where('number', 999)->first();

        $this->assertNotNull($task);
        $this->assertNotSame('', $task->title);
        $this->assertLessThanOrEqual(120, mb_strlen($task->title));
        $this->assertStringStartsWith('Import title test sentence.', $task->title);
        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertSame('<p>'.$description.'</p>', $task->description);

        @unlink($path);
    }

    public function test_imported_script_cell_is_stored_as_inert_text(): void
    {
        $dept = $this->createDepartment('IT');
        $admin = $this->createUserInDepartment($dept, 'Admin');
        config(['tasktracker.admin_email' => $admin->email]);
        Category::query()->create([
            'name' => 'Прочие задачи',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $payload = '<script>alert(1)</script>';
        $path = $this->makeSpreadsheet($payload, number: 1001);

        app(ExcelTaskImportService::class)->import($path, dryRun: false);

        $task = Task::query()->where('number', 1001)->first();

        $this->assertNotNull($task);
        $this->assertSame(ContentFormat::Html, $task->description_format);
        $this->assertStringNotContainsString('<script>', $task->description);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $task->description);
        $this->assertStringContainsString('alert(1)', $task->renderedDescription());
        $this->assertStringNotContainsString('<script>', $task->renderedDescription());

        @unlink($path);
    }

    public function test_imported_benign_markup_stays_literal_after_the_editor_cutover(): void
    {
        $dept = $this->createDepartment('IT');
        $admin = $this->createUserInDepartment($dept, 'Admin');
        config(['tasktracker.admin_email' => $admin->email]);
        Category::query()->create([
            'name' => 'Прочие задачи',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // The editor write path sanitizes, which would turn these into real
        // markup. Spreadsheet cells must keep going through the escaping path.
        $payload = 'Условие: a < b & <b>жирный</b>';
        $path = $this->makeSpreadsheet($payload, number: 1002);

        app(ExcelTaskImportService::class)->import($path, dryRun: false);

        $task = Task::query()->where('number', 1002)->first();

        $this->assertNotNull($task);
        $this->assertSame('<p>Условие: a &lt; b &amp; &lt;b&gt;жирный&lt;/b&gt;</p>', $task->description);
        $this->assertStringNotContainsString('<b>', $task->description);
        $this->assertStringNotContainsString('<b>', $task->renderedDescription());
        $this->assertSame($payload, $task->description_text);

        @unlink($path);
    }

    private function makeSpreadsheet(string $description, int $number = 999): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Лист задач');

        $sheet->setCellValue([1, 2], $number);
        $sheet->setCellValue([3, 2], 'Test Initiator');
        $sheet->setCellValue([4, 2], 'IT');
        $sheet->setCellValue([5, 2], $description);
        $sheet->setCellValue([6, 2], 'Прочие задачи');
        $sheet->setCellValue([8, 2], 'В работе');

        $path = sys_get_temp_dir().'/task-import-test-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
