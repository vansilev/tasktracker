<?php

namespace Tests\Feature;

use App\Models\Category;
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

        $task = \App\Models\Task::query()->where('number', 999)->first();

        $this->assertNotNull($task);
        $this->assertNotSame('', $task->title);
        $this->assertLessThanOrEqual(120, mb_strlen($task->title));
        $this->assertStringStartsWith('Import title test sentence.', $task->title);
        $this->assertSame($description, $task->description);

        @unlink($path);
    }

    private function makeSpreadsheet(string $description): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Лист задач');

        $sheet->setCellValue([1, 2], 999);
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
