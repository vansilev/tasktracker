<?php

namespace App\Console\Commands;

use App\Enums\SystemType;
use App\Models\Department;
use App\Models\User;
use App\Services\UserLifecycleService;
use Illuminate\Console\Command;

class EnsureDepartmentHeads extends Command
{
    protected $signature = 'departments:ensure-heads {--dry-run : Show what would be assigned without writing}';

    protected $description = 'Assign the first active employee as head for departments without one';

    public function handle(UserLifecycleService $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $assigned = 0;

        Department::query()
            ->where('is_active', true)
            ->whereNull('head_user_id')
            ->eachById(function (Department $department) use ($lifecycle, $dryRun, &$assigned): void {
                $candidate = User::query()
                    ->where('department_id', $department->id)
                    ->where('is_active', true)
                    ->where('system_type', '!=', SystemType::Admin)
                    ->orderBy('name')
                    ->first();

                if (! $candidate) {
                    $this->warn("No candidate for {$department->name}");

                    return;
                }

                if ($dryRun) {
                    $this->line("Would assign {$candidate->name} as head of {$department->name}");
                } else {
                    $lifecycle->syncDepartmentHead($department, $candidate->id);
                    $this->info("Assigned {$candidate->name} as head of {$department->name}");
                }

                $assigned++;
            });

        $this->info(($dryRun ? 'Would assign' : 'Assigned')." {$assigned} department head(s).");

        return self::SUCCESS;
    }
}
