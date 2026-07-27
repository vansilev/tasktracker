<?php

require __DIR__.'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$path = $argv[1] ?? 'C:\Users\m.goldt.IT-MG\Downloads\IT Task Tracker.xlsx';

if (! is_readable($path)) {
    fwrite(STDERR, "File not readable: {$path}\n");
    exit(1);
}

function isActiveStatus(string $status): bool
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

function mapStatus(string $status): string
{
    return match (true) {
        str_contains($status, 'Ожидает данных') => 'awaiting_initiator',
        str_contains($status, 'В работе') => 'in_progress',
        str_contains($status, 'проверка инициатором') => 'on_review',
        str_contains($status, 'На проверке') => 'on_review',
        str_contains($status, 'Отложена') => 'postponed',
        default => 'new',
    };
}

function parseDate(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return \Carbon\Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
            ->format('Y-m-d');
    }
    try {
        return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
    } catch (\Throwable) {
        return null;
    }
}

function titleFromDescription(string $description): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? '');

    return mb_substr($normalized, 0, 120);
}

$spreadsheet = IOFactory::load($path);
$sheetNames = $spreadsheet->getSheetNames();
$sheet = $spreadsheet->getSheetByName('Лист задач') ?? $spreadsheet->getSheet(0);

echo "FILE: {$path}\n";
echo "SHEETS: ".implode(', ', $sheetNames)."\n";
echo "ACTIVE SHEET: {$sheet->getTitle()}\n";
echo "ROWS: {$sheet->getHighestRow()}\n\n";

// Header row
$headers = [];
for ($col = 1; $col <= 12; $col++) {
    $headers[$col] = trim((string) $sheet->getCell([$col, 1])->getValue());
}
echo "HEADERS:\n";
foreach ($headers as $col => $h) {
    if ($h !== '') {
        echo "  [{$col}] {$h}\n";
    }
}
echo "\n";

$open = [];
$closed = [];
$empty = 0;
$noNumber = [];

for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
    $numberRaw = trim((string) $sheet->getCell([1, $row])->getValue());
    $created = parseDate($sheet->getCell([2, $row])->getValue());
    $initiator = trim((string) $sheet->getCell([3, $row])->getValue());
    $dept = trim((string) $sheet->getCell([4, $row])->getValue());
    $description = trim((string) $sheet->getCell([5, $row])->getValue());
    $category = trim((string) $sheet->getCell([6, $row])->getValue());
    $deadline = parseDate($sheet->getCell([7, $row])->getValue());
    $statusRaw = trim((string) $sheet->getCell([8, $row])->getValue());
    $specUrl = trim((string) $sheet->getCell([10, $row])->getValue());
    $resultUrl = trim((string) $sheet->getCell([11, $row])->getValue());

    if ($description === '' && $statusRaw === '') {
        $empty++;
        continue;
    }

    $item = [
        'row' => $row,
        'number' => is_numeric($numberRaw) ? (int) $numberRaw : null,
        'created' => $created,
        'initiator' => $initiator,
        'dept' => $dept,
        'title' => titleFromDescription($description),
        'category' => $category,
        'deadline' => $deadline,
        'status_excel' => $statusRaw,
        'status_app' => mapStatus($statusRaw),
        'spec_url' => $specUrl !== '' ? $specUrl : null,
        'result_url' => $resultUrl !== '' ? $resultUrl : null,
    ];

    if (! isActiveStatus($statusRaw)) {
        $closed[] = $item;
        continue;
    }

    if ($item['number'] === null) {
        $noNumber[] = $item;
        continue;
    }

    $open[] = $item;
}

usort($open, fn ($a, $b) => $a['number'] <=> $b['number']);

echo "SUMMARY\n";
echo "  Empty rows skipped: {$empty}\n";
echo "  Closed/completed (skip): ".count($closed)."\n";
echo "  Open without number (skip): ".count($noNumber)."\n";
echo "  OPEN TO IMPORT: ".count($open)."\n\n";

// Status breakdown
$byStatus = [];
foreach ($open as $t) {
    $byStatus[$t['status_excel']] = ($byStatus[$t['status_excel']] ?? 0) + 1;
}
echo "OPEN BY EXCEL STATUS:\n";
foreach ($byStatus as $s => $c) {
    echo "  {$s}: {$c}\n";
}
echo "\n";

$byDept = [];
foreach ($open as $t) {
    $byDept[$t['dept'] ?: '(пусто)'] = ($byDept[$t['dept'] ?: '(пусто)'] ?? 0) + 1;
}
echo "OPEN BY INITIATOR DEPT:\n";
foreach ($byDept as $d => $c) {
    echo "  {$d}: {$c}\n";
}
echo "\n";

$byCat = [];
foreach ($open as $t) {
    $byCat[$t['category'] ?: '(пусто)'] = ($byCat[$t['category'] ?: '(пусто)'] ?? 0) + 1;
}
echo "OPEN BY CATEGORY:\n";
foreach ($byCat as $c => $n) {
    echo "  {$c}: {$n}\n";
}
echo "\n";

$initiators = [];
foreach ($open as $t) {
    $key = ($t['initiator'] ?: '(пусто)').' | '.$t['dept'];
    $initiators[$key] = ($initiators[$key] ?? 0) + 1;
}
echo "UNIQUE INITIATORS (open tasks):\n";
foreach ($initiators as $k => $n) {
    echo "  {$k}: {$n}\n";
}
echo "\n";

echo "OPEN TASKS LIST (tab-separated):\n";
echo "number\tcreated\tstatus_excel\tstatus_app\tdept\tcategory\tinitiator\tdeadline\ttitle\n";
foreach ($open as $t) {
    echo implode("\t", [
        $t['number'],
        $t['created'] ?? '',
        $t['status_excel'],
        $t['status_app'],
        $t['dept'],
        $t['category'],
        $t['initiator'],
        $t['deadline'] ?? '',
        str_replace(["\t", "\n", "\r"], ' ', $t['title']),
    ])."\n";
}

if ($noNumber !== []) {
    echo "\nSKIPPED OPEN (no number):\n";
    foreach ($noNumber as $t) {
        echo "  row {$t['row']}: {$t['status_excel']} — ".mb_substr($t['title'], 0, 60)."\n";
    }
}
