<?php
require __DIR__.'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'C:\Users\m.goldt.IT-MG\Downloads\IT Task Tracker.xlsx';
$wb = IOFactory::load($path);
$sheet = $wb->getSheetByName('New (from 06.07)');
if (! $sheet) { echo "no sheet\n"; exit; }
echo 'ROWS: '.$sheet->getHighestRow()."\n";
for ($col = 1; $col <= 10; $col++) {
    echo "[$col] ".trim((string) $sheet->getCell([$col, 1])->getValue())."\n";
}
$open = 0; $closed = 0; $items = [];
for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
    $num = trim((string) $sheet->getCell([1, $row])->getValue());
    $desc = trim((string) $sheet->getCell([5, $row])->getValue());
    $status = trim((string) $sheet->getCell([8, $row])->getValue());
    if ($desc === '' && $status === '') continue;
    $active = ! in_array($status, ['Отклонена', 'Отменена'], true) && ! str_contains(mb_strtolower($status), 'выполнена');
    if ($active) {
        $open++;
        $items[] = ['row' => $row, 'number' => $num, 'status' => $status, 'desc' => mb_substr($desc, 0, 80)];
    } else {
        $closed++;
    }
}
echo "open=$open closed=$closed\n";
foreach ($items as $i) {
    echo "  row {$i['row']} #{$i['number']} [{$i['status']}] {$i['desc']}\n";
}
