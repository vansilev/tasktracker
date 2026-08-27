<?php

namespace App\Console\Commands;

use App\Services\BillingSheetImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportBillingSheet extends Command
{
    protected $signature = 'billing:import-sheet
        {--dir= : Directory with sheet_*.csv}
        {--fetch : Download CSVs from the public Google Sheet}
        {--dry-run : Preview without writing}';

    protected $description = 'One-shot import of IT payments from the Google Sheet';

    public function handle(BillingSheetImportService $importer): int
    {
        $dir = $this->option('dir') ?: storage_path('app/billing-import');

        if ($this->option('fetch') || ! is_dir($dir) || $this->missingSheets($dir)) {
            $this->info('Downloading sheet CSVs…');
            File::ensureDirectoryExists($dir);
            $importer->download($dir);
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no rows will be written.');
        }

        $result = $importer->import($dir, $dryRun);

        $this->info(($dryRun ? 'Would import' : 'Imported').": {$result['imported']}");
        $this->info('Skipped: '.count($result['skipped']));
        $this->info('Updated existing: '.$result['skipped_existing']);

        $this->table(
            ['Vendor', 'Product', 'Amount', 'Kind', 'Method', 'Due', 'Payer', 'State'],
            collect($result['preview'])->map(fn (array $row) => [
                $row['vendor'],
                \Illuminate\Support\Str::limit($row['product'], 40),
                $row['amount'] === null ? 'по счету' : $row['amount'].' '.$row['currency'],
                $row['kind'],
                $row['payment_method'],
                $row['next_due_on'] ?? '—',
                $row['payer_email'] ?? '—',
                $row['state'],
            ])->all()
        );

        if ($result['skipped'] !== []) {
            $this->warn('Skipped rows:');
            foreach ($result['skipped'] as $skip) {
                $this->line('  '.($skip['reason'] ?? '').' — '.($skip['vendor'] ?? '').' / '.($skip['product'] ?? ''));
            }
        }

        return self::SUCCESS;
    }

    private function missingSheets(string $dir): bool
    {
        foreach (BillingSheetImportService::SHEET_GIDS as $gid) {
            if (! is_file($dir.'/sheet_'.$gid.'.csv')) {
                return true;
            }
        }

        return false;
    }
}
