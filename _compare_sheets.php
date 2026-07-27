<?php
require __DIR__.'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
$wb = IOFactory::load('C:\Users\m.goldt.IT-MG\Downloads\IT Task Tracker.xlsx');
foreach (['Лист задач', 'New (from 06.07)'] as $name) {
    $s = $wb->getSheetByName($name);
    echo "=== $name ===\n";
    foreach ([181, 182, 184, 207] as $n) {
        for ($r = 2; $r <= $s->getHighestRow(); $r++) {
            if (trim((string) $s->getCell([1, $r])->getValue()) === (string) $n) {
                echo "#$n row $r | ".trim((string) $s->getCell([8, $r])->getValue())."\n";
                echo "  dept: ".trim((string) $s->getCell([4, $r])->getValue())."\n";
                echo "  init: ".trim((string) $s->getCell([3, $r])->getValue())."\n";
                echo "  cat: ".trim((string) $s->getCell([6, $r])->getValue())."\n";
                echo "  ".mb_substr(trim((string) $s->getCell([5, $r])->getValue()), 0, 120)."\n\n";
                break;
            }
        }
    }
}
