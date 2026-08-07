<?php

namespace App\Services;

use App\Enums\AuthProvider;
use App\Enums\ContentFormat;
use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelTaskImportService
{
    private User $fallbackUser;

    private Department $itDepartment;

    /** @var array<string, User> */
    private array $initiatorCache = [];

    /** @var array<string, Department> */
    private array $departmentCache = [];

    /** @var array<string, Category> */
    private array $categoryCache = [];

    public function __construct(
        private TaskContentService $content,
        private HtmlContentService $html,
    ) {}

    public function import(string $path, bool $dryRun = false): array
    {
        return $this->importFromWorkbook($path, $dryRun, useRealInitiators: false);
    }

    public function importApprovedOpenTasks(string $path, bool $dryRun = false): array
    {
        return $this->importFromWorkbook($path, $dryRun, useRealInitiators: true);
    }

    private function importFromWorkbook(string $path, bool $dryRun, bool $useRealInitiators): array
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("File not readable: {$path}");
        }

        $this->fallbackUser = User::query()
            ->where('email', config('tasktracker.admin_email'))
            ->firstOrFail();

        $this->itDepartment = Department::query()->where('name', 'IT')->firstOrFail();

        $workbook = IOFactory::load($path);
        $sheet = $workbook->getSheetByName('Лист задач') ?? $workbook->getSheet(0);
        $imported = 0;
        $skipped = [];
        $preview = [];

        DB::transaction(function () use ($sheet, $workbook, $dryRun, $useRealInitiators, &$imported, &$skipped, &$preview) {
            for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
                $numberRaw = trim((string) $sheet->getCell([1, $row])->getValue());
                $statusRaw = trim((string) $sheet->getCell([8, $row])->getValue());
                $description = trim((string) $sheet->getCell([5, $row])->getValue());

                if ($description === '' && $statusRaw === '') {
                    continue;
                }

                if (! $this->isActiveStatus($statusRaw)) {
                    continue;
                }

                $numberOverride = null;
                if (($numberRaw === '' || ! is_numeric($numberRaw)) && $useRealInitiators && $row === 5) {
                    $numberOverride = 185;
                }

                if (($numberRaw === '' || ! is_numeric($numberRaw)) && $numberOverride === null) {
                    $skipped[] = ['row' => $row, 'reason' => 'missing_number', 'status' => $statusRaw];

                    continue;
                }

                $number = $numberOverride ?? (int) $numberRaw;

                if (Task::query()->where('number', $number)->exists()) {
                    $skipped[] = ['row' => $row, 'number' => $number, 'reason' => 'already_exists'];

                    continue;
                }

                $imported += $this->importRow(
                    $sheet,
                    $row,
                    $number,
                    $useRealInitiators,
                    $dryRun,
                    $preview,
                );
            }

            if ($useRealInitiators) {
                $supplemental = $workbook->getSheetByName('New (from 06.07)');
                if ($supplemental) {
                    foreach ([
                        ['row' => 3, 'number' => 207],
                        ['row' => 5, 'number' => 208],
                    ] as $spec) {
                        $statusRaw = trim((string) $supplemental->getCell([8, $spec['row']])->getValue());
                        $description = trim((string) $supplemental->getCell([5, $spec['row']])->getValue());

                        if ($description === '' || ! $this->isActiveStatus($statusRaw)) {
                            continue;
                        }

                        if (Task::query()->where('number', $spec['number'])->exists()) {
                            $skipped[] = ['row' => $spec['row'], 'sheet' => 'New (from 06.07)', 'number' => $spec['number'], 'reason' => 'already_exists'];

                            continue;
                        }

                        $imported += $this->importRow(
                            $supplemental,
                            $spec['row'],
                            $spec['number'],
                            true,
                            $dryRun,
                            $preview,
                        );
                    }
                }
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'preview' => $preview,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $preview
     */
    private function importRow(
        Worksheet $sheet,
        int $row,
        int $number,
        bool $useRealInitiators,
        bool $dryRun,
        array &$preview,
    ): int {
        $statusRaw = trim((string) $sheet->getCell([8, $row])->getValue());
        $description = trim((string) $sheet->getCell([5, $row])->getValue());
        $status = $this->mapStatus($statusRaw);
        $initiatorDepartment = $this->mapDepartment(trim((string) $sheet->getCell([4, $row])->getValue()));
        $category = $this->mapCategory(trim((string) $sheet->getCell([6, $row])->getValue()));
        $initiatorLabel = trim((string) $sheet->getCell([3, $row])->getValue());
        $initiator = $useRealInitiators
            ? $this->resolveInitiatorReal($initiatorLabel, $initiatorDepartment)
            : $this->resolveInitiator($initiatorLabel, $initiatorDepartment);
        $assignee = $useRealInitiators
            ? $this->resolveDepartmentHeadAssignee($initiatorDepartment)
            : $this->resolveAssignee();

        $deadline = $this->parseDate($sheet->getCell([7, $row])->getValue());
        $createdAt = $this->parseDate($sheet->getCell([2, $row])->getValue()) ?? now();
        $specUrl = $this->normalizeUrl($sheet->getCell([10, $row])->getValue());
        $resultUrl = $this->normalizeUrl($sheet->getCell([11, $row])->getValue());
        $title = $this->titleFromDescription($description);

        $preview[] = [
            'number' => $number,
            'row' => $row,
            'status' => $status->value,
            'initiator_label' => $initiatorLabel,
            'initiator_email' => $initiator->email,
            'assignee_email' => $assignee->email,
            'title' => $title,
        ];

        if ($dryRun) {
            return 1;
        }

        $task = new Task([
            'number' => $number,
            'initiator_id' => $initiator->id,
            'assignee_id' => $assignee->id,
            'department_initiator_id' => $initiatorDepartment->id,
            'department_id' => $assignee->department_id,
            'category_id' => $category->id,
            'title' => $title,
            // Spreadsheet cells are untrusted: escape via fromPlainText (not sanitize).
            // TIP TAP FLIP POINT: fromUserInput → sanitize if imports ever carry HTML.
            'description' => $this->content->fromUserInput($description),
            'priority' => 5,
            'status' => $status,
            'deadline' => $deadline,
            'spec_url' => $specUrl,
            'result_url' => $resultUrl,
            'review_due_at' => $status === TaskStatus::OnReview ? now()->addDays(3) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        // description_format is not mass-assignable.
        $task->description_format = ContentFormat::Html;
        $task->save();

        return 1;
    }

    private function isActiveStatus(string $status): bool
    {
        if ($status === '') {
            return false;
        }

        if (in_array($status, ['Отклонена', 'Отменена'], true)) {
            return false;
        }

        if (str_contains(mb_strtolower($status), 'выполнена')) {
            return false;
        }

        return true;
    }

    private function mapStatus(string $status): TaskStatus
    {
        return match (true) {
            str_contains($status, 'Ожидает данных') => TaskStatus::AwaitingInitiator,
            str_contains($status, 'В работе') => TaskStatus::InProgress,
            str_contains($status, 'проверка инициатором') => TaskStatus::OnReview,
            str_contains($status, 'На проверке') => TaskStatus::OnReview,
            str_contains($status, 'Отложена') => TaskStatus::Postponed,
            default => TaskStatus::New,
        };
    }

    private function mapDepartment(string $name): Department
    {
        $normalized = match ($name) {
            'Финансовый отедл' => 'Финансовый отдел',
            'маркетинг', 'Маркетинг', 'Отдел маркетинга' => 'Отдел маркетинга',
            'продажи', 'Продажи', 'Отдел продаж' => 'Отдел продаж',
            'Операционный', 'Операционный отдел' => 'Операционный отдел',
            'Учебный отдел' => 'Учебный отдел',
            'IT', 'ИТ' => 'IT',
            default => $name,
        };

        if (isset($this->departmentCache[$normalized])) {
            return $this->departmentCache[$normalized];
        }

        $dept = Department::query()->where('name', $normalized)->first()
            ?? Department::query()->where('name', 'IT')->firstOrFail();

        return $this->departmentCache[$normalized] = $dept;
    }

    private function mapCategory(string $name): Category
    {
        if (isset($this->categoryCache[$name])) {
            return $this->categoryCache[$name];
        }

        $cat = Category::query()->where('name', $name)->first()
            ?? Category::query()->where('name', 'Прочие задачи')->firstOrFail();

        return $this->categoryCache[$name] = $cat;
    }

    private function resolveInitiatorReal(string $label, Department $initiatorDepartment): User
    {
        $normalized = mb_strtolower(trim($label));

        $explicit = [
            'татьяна' => 'training@tcsavant.com',
            '@salardo1' => 'rop@tcsavant.com',
            '@anna_belka_2806' => 'assistant@tcsavant.com',
            '@corvettejz2' => 'accounting@tcsavant.com',
            '@lizagrigorenko' => 'training2@tcsavant.com',
            '@stepanyash' => 'rop@tcsavant.com',
            '@tyurkova' => 'artemsonko7@gmail.com',
        ];

        if (isset($explicit[$normalized])) {
            $user = User::query()->where('email', $explicit[$normalized])->where('is_active', true)->first();
            if ($user) {
                return $user;
            }
        }

        if (str_contains($normalized, '@') && filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            $user = User::query()->where('email', $normalized)->where('is_active', true)->first();
            if ($user) {
                return $user;
            }
        }

        $user = $this->matchInitiatorByName($label, $initiatorDepartment);
        if ($user) {
            return $user;
        }

        $initiatorDepartment->loadMissing('head');
        if ($initiatorDepartment->head && $initiatorDepartment->head->is_active) {
            return $initiatorDepartment->head;
        }

        return $this->fallbackUser;
    }

    private function matchInitiatorByName(string $label, Department $initiatorDepartment): ?User
    {
        $tokens = preg_split('/[\s@._-]+/u', mb_strtolower($label)) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $t) => mb_strlen($t) >= 3));

        if ($tokens === []) {
            return null;
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->where('department_id', $initiatorDepartment->id)
            ->get();

        foreach ($candidates as $user) {
            $name = mb_strtolower($user->name);
            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    return $user;
                }
            }
        }

        return null;
    }

    private function resolveInitiator(string $label, Department $initiatorDepartment): User
    {
        if ($label === '') {
            return $this->fallbackUser;
        }

        $cacheKey = $label.'|'.$initiatorDepartment->id;
        if (isset($this->initiatorCache[$cacheKey])) {
            return $this->initiatorCache[$cacheKey];
        }

        $slug = Str::slug(Str::replace(['@', ' '], ['', '-'], $label));
        if ($slug === '') {
            $slug = 'unknown-'.substr(md5($label), 0, 8);
        }

        $email = "import.{$slug}@tcsavant.com";

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $label,
                'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(),
                'system_type' => SystemType::User,
                'auth_provider' => AuthProvider::Password,
                'department_id' => $initiatorDepartment->id,
                'locale' => 'ru',
                'is_active' => true,
            ]
        );

        if ($user->department_id !== $initiatorDepartment->id) {
            $user->update(['department_id' => $initiatorDepartment->id]);
        }

        return $this->initiatorCache[$cacheKey] = $user;
    }

    public function reassignTasksToDepartmentHeads(): array
    {
        $this->fallbackUser = User::query()
            ->where('email', config('tasktracker.admin_email'))
            ->firstOrFail();

        $updated = [];

        Task::query()
            ->with(['departmentInitiator.head'])
            ->orderBy('number')
            ->each(function (Task $task) use (&$updated) {
                $department = $task->departmentInitiator;

                if (! $department) {
                    return;
                }

                $assignee = $this->resolveDepartmentHeadAssignee($department);

                if ($task->assignee_id === $assignee->id && $task->department_id === $assignee->department_id) {
                    return;
                }

                $task->update([
                    'assignee_id' => $assignee->id,
                    'department_id' => $assignee->department_id,
                ]);

                $updated[] = [
                    'number' => $task->number,
                    'department' => $department->name,
                    'assignee_email' => $assignee->email,
                ];
            });

        return $updated;
    }

    private function resolveDepartmentHeadAssignee(Department $department): User
    {
        try {
            return app(TaskAssignmentService::class)->resolveAssignee($department);
        } catch (\RuntimeException) {
            $member = User::query()
                ->where('department_id', $department->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            return $member ?? $this->fallbackUser;
        }
    }

    private function resolveAssignee(): User
    {
        if ($this->itDepartment->head_user_id) {
            $head = User::query()->where('is_active', true)->find($this->itDepartment->head_user_id);
            if ($head) {
                return $head;
            }
        }

        return $this->fallbackUser;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
                ->timezone(config('app.timezone'));
        }

        try {
            return Carbon::parse((string) $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = trim((string) $value);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function titleFromDescription(string $description): string
    {
        $normalized = $this->html->toPlainText($description);

        return Str::limit($normalized, 120, '');
    }
}
