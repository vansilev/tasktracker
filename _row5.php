<?php
require __DIR__.'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
$s = IOFactory::load('C:\Users\m.goldt.IT-MG\Downloads\IT Task Tracker.xlsx')->getSheetByName('Лист задач');
$r = 5;
for ($c=1;$c<=11;$c++) echo "[$c] ".trim((string)$s->getCell([$c,$r])->getValue())."\n";
