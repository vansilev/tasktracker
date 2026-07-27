<?php
require __DIR__.'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
$s = IOFactory::load('C:\Users\m.goldt.IT-MG\Downloads\IT Task Tracker.xlsx')->getSheetByName('Лист задач');
foreach ([182, 207, 5] as $n) {
    for ($r = 2; $r <= 1000; $r++) {
        $v = trim((string) $s->getCell([1, $r])->getValue());
        if ($v === (string) $n) {
            echo "#$n row $r status=".trim((string) $s->getCell([8, $r])->getValue())."\n";
            echo "  ".trim((string) $s->getCell([5, $r])->getValue())."\n";
            break;
        }
        if ($r === 1000 && $n !== 5) echo "#$n NOT FOUND on main sheet\n";
    }
}
// row 5 no number
$r = 5;
echo "row 5 number=".trim((string)$s->getCell([1,$r])->getValue())." status=".trim((string)$s->getCell([8,$r])->getValue())."\n";
echo "  ".mb_substr(trim((string)$s->getCell([5,$r])->getValue()),0,100)."\n";
