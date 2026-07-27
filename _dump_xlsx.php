<?php

require __DIR__.'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? 'import_source.xlsx';
$out = fopen(__DIR__.'/_dump.txt', 'w');

$ss = IOFactory::load($path);

foreach ($ss->getAllSheets() as $sheet) {
    $hr = $sheet->getHighestDataRow();
    $hc = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    fwrite($out, "=== SHEET: ".$sheet->getTitle()." (rows={$hr}, cols={$hc}) ===\n");

    for ($r = 1; $r <= $hr; $r++) {
        $cells = [];
        for ($c = 1; $c <= $hc; $c++) {
            $v = trim((string) $sheet->getCell([$c, $r])->getValue());
            if ($v !== '') {
                $col = Coordinate::stringFromColumnIndex($c);
                $cells[] = "{$col}{$r}={$v}";
            }
        }
        if ($cells !== []) {
            fwrite($out, implode('  ||  ', $cells)."\n");
        }
    }
    fwrite($out, "\n");
}

fclose($out);
echo "done\n";
