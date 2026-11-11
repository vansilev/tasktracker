<?php

use App\Models\Meeting;
use App\Models\MeetingTaskDraft;
use App\Services\Meetings\FirefliesClient;
use App\Services\Meetings\MeetingImporter;
use App\Services\Meetings\MeetingPeople;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.tasks-layout')] class extends Component
{
    public function with(MeetingPeople $people): array
    {
        $user = auth()->user();

        $meetings = Meeting::query()
            ->withCount([
                'drafts as open_drafts' => fn ($q) => $q->where('status', MeetingTaskDraft::STATUS_DRAFT),
                'drafts as created_tasks' => fn ($q) => $q->where('status', MeetingTaskDraft::STATUS_CREATED),
            ])
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->filter(fn (Meeting $m) => $people->canView($user, $m))
            ->map(fn (Meeting $m) => [
                'meeting' => $m,
                'people' => count($people->presentUserIds($m)),
            ])
            ->values();

        return [
            'meetings' => $meetings,
            'canImport' => $user->isAdmin(),
        ];
    }

    public function importFromFireflies(MeetingImporter $importer, FirefliesClient $client): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if (! $client->configured()) {
            session()->flash('meetings_error', __('meetings.not_configured', ['name' => 'FIREFLIES_API_KEY']));

            return;
        }

        @set_time_limit(120);

        try {
            $count = count($importer->importRecent(10));
            session()->flash('meetings_status', __('meetings.imported', ['count' => $count]));
        } catch (\Throwable $e) {
            session()->flash('meetings_error', $e->getMessage());
        }
    }
}; ?>

<div class="space-y-4" data-ui="meetings-index">
    <x-slot name="title">{{ __('meetings.title') }}</x-slot>

    @if ($canImport)
        <div class="flex justify-end">
            <button type="button" wire:click="importFromFireflies" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
                <svg wire:loading.remove wire:target="importFromFireflies" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                <svg wire:loading wire:target="importFromFireflies" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                {{ __('meetings.import') }}
            </button>
        </div>
    @endif

    @if (session('meetings_status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('meetings_status') }}</div>
    @endif
    @if (session('meetings_error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ session('meetings_error') }}</div>
    @endif

    <x-card padding="p-0">
        <ul class="divide-y divide-gray-100">
            @forelse ($meetings as $row)
                @php($m = $row['meeting'])
                <li wire:key="meeting-{{ $m->id }}">
                    <a href="{{ route('meetings.show', $m) }}" wire:navigate class="flex flex-col gap-2 px-5 py-4 transition-colors hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-medium text-gray-900">{{ $m->title }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ $m->started_at->timezone('Europe/Kyiv')->locale(app()->getLocale())->isoFormat('dd, D MMM YYYY, HH:mm') }}
                                · {{ __('meetings.minutes', ['count' => $m->duration_minutes]) }}
                                · {{ __('meetings.participants_count', ['count' => $row['people']]) }}
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($m->analyzed_at)
                                @if ($m->open_drafts > 0)
                                    <x-pill color="amber">{{ __('meetings.drafts_count', ['count' => $m->open_drafts]) }}</x-pill>
                                @endif
                                @if ($m->created_tasks > 0)
                                    <x-pill color="green">{{ __('meetings.created_count', ['count' => $m->created_tasks]) }}</x-pill>
                                @endif
                                @if ($m->open_drafts === 0 && $m->created_tasks === 0)
                                    <x-pill>{{ __('meetings.analyzed') }}</x-pill>
                                @endif
                            @else
                                <x-pill>{{ __('meetings.not_analyzed') }}</x-pill>
                            @endif
                        </div>
                    </a>
                </li>
            @empty
                <li class="px-5 py-4"><x-empty-state>{{ $canImport ? __('meetings.empty') : __('meetings.none_visible') }}</x-empty-state></li>
            @endforelse
        </ul>
    </x-card>
</div>
