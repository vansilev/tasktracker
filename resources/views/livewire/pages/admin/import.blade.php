<?php

use App\Services\AuditLogService;
use App\Services\ExcelTaskImportService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout')] class extends Component
{
    use WithFileUploads;

    public $importFile = null;

    public ?string $storedRelativePath = null;

    public ?string $originalFilename = null;

    public bool $dryRunCompleted = false;

    public ?array $report = null;

    public bool $importCompleted = false;

    public ?string $serviceError = null;

    public function updatedImportFile(SettingsService $settings): void
    {
        if ($this->storedRelativePath) {
            Storage::disk('local')->delete($this->storedRelativePath);
        }

        $this->storedRelativePath = null;
        $this->originalFilename = null;
        $this->dryRunCompleted = false;
        $this->report = null;
        $this->importCompleted = false;
        $this->serviceError = null;

        $maxKb = (int) $settings->get('attachment_max_kb', 10240);

        $this->validate([
            'importFile' => 'required|file|mimes:xlsx|max:'.$maxKb,
        ]);

        $this->storedRelativePath = $this->importFile->store('import', 'local');
        $this->originalFilename = $this->importFile->getClientOriginalName();
    }

    public function dryRun(ExcelTaskImportService $importer): void
    {
        $this->serviceError = null;
        $this->importCompleted = false;

        if (! $this->storedRelativePath) {
            $this->addError('importFile', __('Please upload an Excel file first.'));

            return;
        }

        try {
            $this->report = $importer->import($this->storedFilePath(), dryRun: true);
            $this->dryRunCompleted = true;
        } catch (\Throwable) {
            $this->dryRunCompleted = false;
            $this->report = null;
            $this->serviceError = __('Failed to read the Excel file. Check the format and try again.');
        }
    }

    public function import(ExcelTaskImportService $importer, AuditLogService $audit): void
    {
        $this->serviceError = null;

        if (! $this->dryRunCompleted || ! $this->storedRelativePath) {
            $this->addError('import', __('Run a dry-run check on this file before importing.'));

            return;
        }

        try {
            $result = $importer->import($this->storedFilePath(), dryRun: false);

            $audit->log(
                'tasks.imported',
                auth()->user(),
                newValues: [
                    'filename' => $this->originalFilename,
                    'imported' => $result['imported'],
                    'skipped' => count($result['skipped']),
                ],
            );

            Storage::disk('local')->delete($this->storedRelativePath);

            $this->report = $result;
            $this->importCompleted = true;
            $this->storedRelativePath = null;
            $this->originalFilename = null;
            $this->dryRunCompleted = false;
            $this->importFile = null;
        } catch (\Throwable) {
            $this->serviceError = __('Failed to read the Excel file. Check the format and try again.');
        }
    }

    public function skipReasonLabel(string $reason): string
    {
        return match ($reason) {
            'already_exists' => __('Task number already exists in the database'),
            'missing_number' => __('Row has no valid task number'),
            default => $reason,
        };
    }

    private function storedFilePath(): string
    {
        return Storage::disk('local')->path($this->storedRelativePath);
    }
}; ?>

<div class="space-y-4">
    <x-card padding="p-4">
        <p class="text-xs text-gray-500 mb-4">
            {{ __('Only active tasks are imported; historical numbers are preserved; existing numbers are skipped.') }}
        </p>

        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex items-start gap-3 sm:w-48 shrink-0">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $storedRelativePath ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">1</span>
                <div>
                    <p class="text-sm font-semibold text-gray-900">{{ __('Excel file (.xlsx)') }}</p>
                </div>
            </div>
            <div class="flex-1 space-y-3">
                <input
                    id="importFile"
                    type="file"
                    wire:model="importFile"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    class="block w-full text-sm text-gray-700 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                />
                <div wire:loading wire:target="importFile" class="text-xs text-gray-500">
                    {{ __('Uploading…') }}
                </div>
                @if ($originalFilename)
                    <p class="text-xs text-gray-600">{{ __('Selected file') }}: <span class="font-medium">{{ $originalFilename }}</span></p>
                @endif
                <x-input-error :messages="$errors->get('importFile')" />
            </div>
        </div>
    </x-card>

    <x-card padding="p-4">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex items-start gap-3 sm:w-48 shrink-0">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $dryRunCompleted ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">2</span>
                <div>
                    <p class="text-sm font-semibold text-gray-900">{{ __('Check (dry-run)') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Dry-run report') }}</p>
                </div>
            </div>
            <div class="flex-1">
                <x-action-button variant="primary" size="md" type="button"
                                 wire:click="dryRun"
                                 wire:loading.attr="disabled"
                                 wire:target="dryRun"
                                 :disabled="! $storedRelativePath">
                    <span wire:loading.remove wire:target="dryRun">{{ __('Check (dry-run)') }}</span>
                    <span wire:loading wire:target="dryRun">{{ __('Checking…') }}</span>
                </x-action-button>
            </div>
        </div>
    </x-card>

    <x-card padding="p-4">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex items-start gap-3 sm:w-48 shrink-0">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $importCompleted ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">3</span>
                <div>
                    <p class="text-sm font-semibold text-gray-900">{{ __('Import') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Import report') }}</p>
                </div>
            </div>
            <div class="flex-1">
                <x-action-button variant="primary" size="md" type="button"
                                 wire:click="import"
                                 wire:loading.attr="disabled"
                                 wire:target="import"
                                 :disabled="! $dryRunCompleted || $importCompleted">
                    <span wire:loading.remove wire:target="import">{{ __('Import') }}</span>
                    <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
                </x-action-button>
            </div>
        </div>
    </x-card>

    <x-input-error :messages="$errors->get('import')" />

    @if ($serviceError)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $serviceError }}
        </div>
    @endif

    @if ($importCompleted && $report)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ __('Import completed: :imported imported, :skipped skipped.', [
                'imported' => $report['imported'],
                'skipped' => count($report['skipped']),
            ]) }}
        </div>
    @endif

    @if ($report)
        <x-card>
            <h3 class="text-sm font-semibold text-gray-900 mb-2">
                @if ($importCompleted)
                    {{ __('Import report') }}
                @else
                    {{ __('Dry-run report') }}
                @endif
            </h3>
            <p class="text-sm text-gray-700 mb-3">
                {{ __('Tasks to import: :count', ['count' => $report['imported']]) }}
            </p>
            @if ($report['skipped'] !== [])
                <p class="text-xs font-medium text-gray-700 mb-2">
                    {{ __('Skipped rows: :count', ['count' => count($report['skipped'])]) }}
                </p>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">#</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">№</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($report['skipped'] as $skip)
                                <tr>
                                    <td class="px-4 py-2.5 text-gray-700">{{ __('Row :row', ['row' => $skip['row']]) }}</td>
                                    <td class="px-4 py-2.5 text-gray-700">
                                        @if (isset($skip['number']))
                                            {{ $skip['number'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-600">{{ $this->skipReasonLabel($skip['reason']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @endif
</div>
