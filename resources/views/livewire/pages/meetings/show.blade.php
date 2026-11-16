<?php

use App\Models\Category;
use App\Models\Meeting;
use App\Models\MeetingTaskDraft;
use App\Models\User;
use App\Services\Meetings\MeetingPeople;
use App\Services\Meetings\MeetingTaskCreator;
use App\Services\Meetings\MeetingTaskExtractor;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.tasks-layout')] class extends Component
{
    public Meeting $meeting;

    #[Url]
    public string $tab = 'tasks';

    /** @var list<int> */
    public array $selected = [];

    public ?int $categoryId = null;

    public function mount(Meeting $meeting, MeetingPeople $people): void
    {
        abort_unless($people->canView(auth()->user(), $meeting), 403);

        $this->meeting = $meeting;
        $this->categoryId = Category::query()->where('is_active', true)->where('name', 'Прочие задачи')->value('id')
            ?? Category::query()->where('is_active', true)->orderBy('id')->value('id');
        $this->selectAllOpen();
    }

    public function with(MeetingPeople $people): array
    {
        $drafts = $this->meeting->drafts()->with(['assignee.department', 'task', 'mentionedUser'])->get();
        $participants = $people->participants($this->meeting);

        $speakers = $this->meeting->speakerNames();
        $palette = ['bg-primary-500', 'bg-green-500', 'bg-amber-500', 'bg-rose-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500', 'bg-orange-500', 'bg-lime-600', 'bg-pink-500'];
        $colours = [];
        foreach ($speakers as $i => $name) {
            $colours[$name] = $palette[$i % count($palette)];
        }

        $blocks = [];
        if ($this->tab === 'transcript') {
            foreach ($this->meeting->sentences ?? [] as $s) {
                $last = array_key_last($blocks);
                if ($last !== null && $blocks[$last]['speaker'] === $s['speaker'] && count($blocks[$last]['lines']) < 12) {
                    $blocks[$last]['lines'][] = $s;
                } else {
                    $blocks[] = ['speaker' => $s['speaker'], 'start' => $s['start'], 'lines' => [$s]];
                }
            }
        }

        return [
            'drafts' => $drafts,
            'openCount' => $drafts->where('status', MeetingTaskDraft::STATUS_DRAFT)->count(),
            'participants' => $participants,
            'users' => $people->users()->groupBy(fn (User $u) => $u->department?->name ?? '—')->sortKeys(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'isAdmin' => auth()->user()->isAdmin(),
            'viewers' => auth()->user()->isAdmin() ? $this->meeting->viewers()->orderBy('name')->get() : collect(),
            'accessWho' => auth()->user()->isAdmin() ? $this->whoHasAccess($people) : [],
            'colours' => $colours,
            'blocks' => $blocks,
            'speakerUsers' => collect($participants)->filter(fn ($p) => $p['speaker'])->mapWithKeys(fn ($p) => [$p['speaker'] => $p['user']?->name ?? ($p['external'] ? __('meetings.external') : null)]),
        ];
    }

    public function analyze(MeetingTaskExtractor $extractor): void
    {
        if (! $extractor->configured()) {
            session()->flash('meeting_error', __('meetings.not_configured', ['name' => 'OPENAI_API_KEY']));

            return;
        }

        $this->meeting->refresh();
        if ($this->meeting->isBeingAnalyzed()) {
            return;
        }

        $this->meeting->update(['analysis_started_at' => now(), 'analysis_error' => null]);
        $this->tab = 'tasks';
        \App\Jobs\AnalyzeMeeting::dispatchAfterResponse($this->meeting->id);
    }

    /** Polled while the model reads the meeting. */
    public function checkAnalysis(): void
    {
        $this->meeting->refresh();
        if ($this->meeting->isBeingAnalyzed()) {
            return;
        }

        $this->selectAllOpen();
        if ($this->meeting->analysis_error) {
            session()->flash('meeting_error', __('meetings.analysis_failed', ['error' => $this->meeting->analysis_error]));
        } else {
            session()->flash('meeting_status', __('meetings.analyzed_ok', ['count' => count($this->selected)]));
        }
    }

    public function setAssignee(int $draftId, string $userId): void
    {
        $draft = $this->openDraft($draftId);
        $draft->update([
            'assignee_id' => $userId !== '' ? (int) $userId : null,
            'assignment_reason' => 'manual',
        ]);
    }

    public function setTitle(int $draftId, string $title): void
    {
        $title = trim($title);
        if ($title !== '') {
            $this->openDraft($draftId)->update(['title' => mb_substr($title, 0, 250)]);
        }
    }

    public function setDeadline(int $draftId, string $date): void
    {
        $this->openDraft($draftId)->update(['deadline' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null]);
    }

    public function dismiss(int $draftId): void
    {
        $this->openDraft($draftId)->update(['status' => MeetingTaskDraft::STATUS_DISMISSED]);
        $this->selected = array_values(array_diff($this->selected, [$draftId]));
    }

    public function restore(int $draftId): void
    {
        $draft = $this->meeting->drafts()->where('status', MeetingTaskDraft::STATUS_DISMISSED)->findOrFail($draftId);
        $draft->update(['status' => MeetingTaskDraft::STATUS_DRAFT]);
    }

    public function toggleAll(): void
    {
        $open = $this->openIds();
        $this->selected = count(array_intersect($open, $this->selected)) === count($open) ? [] : $open;
    }

    public function createSelected(MeetingTaskCreator $creator): void
    {
        $this->validate(['categoryId' => ['required', 'integer', 'exists:categories,id']]);

        $created = 0;
        $errors = [];
        $drafts = $this->meeting->drafts()
            ->whereIn('id', array_map('intval', $this->selected))
            ->where('status', MeetingTaskDraft::STATUS_DRAFT)
            ->get();

        foreach ($drafts as $draft) {
            try {
                $creator->create(auth()->user(), $draft, (int) $this->categoryId);
                $created++;
            } catch (ValidationException $e) {
                $errors[] = '«'.$draft->title.'»: '.collect($e->errors())->flatten()->first();
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                $errors[] = '«'.$draft->title.'»: '.$e->getMessage();
            }
        }

        $this->selected = [];
        if ($created > 0) {
            session()->flash('meeting_status', __('meetings.created_ok', ['count' => $created]));
        }
        if ($errors) {
            session()->flash('meeting_error', implode("\n", $errors));
        }
    }

    public function mapPerson(int $index, string $value, MeetingPeople $people, MeetingTaskExtractor $extractor): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $row = $people->participants($this->meeting)[$index] ?? null;
        if (! $row) {
            return;
        }

        $external = $value === 'external';
        $userId = ! $external && $value !== '' ? (int) $value : null;

        foreach (array_filter([$row['email'], $row['speaker']]) as $key) {
            $people->remember($key, $userId, $external, auth()->user());
        }

        $extractor->reassign($this->meeting);
        session()->flash('meeting_status', __('meetings.mapping_saved'));
    }

    public function setAccessMode(string $mode, \App\Services\AuditLogService $audit): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        if (! in_array($mode, Meeting::ACCESS_MODES, true) || $mode === $this->meeting->access_mode) {
            return;
        }

        $old = $this->meeting->access_mode;
        $this->meeting->update(['access_mode' => $mode]);
        $audit->log('meeting.access_changed', auth()->user(), $this->meeting, ['access_mode' => $old], ['access_mode' => $mode]);
        session()->flash('meeting_status', __('meetings.access_saved'));
    }

    public function addViewer(string $userId, \App\Services\AuditLogService $audit): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $user = User::query()->where('is_active', true)->find((int) $userId);
        if (! $user) {
            return;
        }

        $this->meeting->viewers()->syncWithoutDetaching([$user->id]);
        $audit->log('meeting.viewer_added', auth()->user(), $this->meeting, null, ['user_id' => $user->id, 'name' => $user->name]);
    }

    public function removeViewer(int $userId, \App\Services\AuditLogService $audit): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        if ($this->meeting->viewers()->detach($userId) > 0) {
            $audit->log('meeting.viewer_removed', auth()->user(), $this->meeting, ['user_id' => $userId], null);
        }
    }

    /** @return list<string> names of non-admins who can open the meeting now */
    private function whoHasAccess(MeetingPeople $people): array
    {
        return $people->users()
            ->filter(fn (User $u) => ! $u->isAdmin() && $people->canView($u, $this->meeting))
            ->pluck('name')
            ->values()
            ->all();
    }

    public function showAt(float $seconds): void
    {
        $this->tab = 'transcript';
        $this->dispatch('meeting-scroll', at: (int) floor($seconds));
    }

    private function openDraft(int $id): MeetingTaskDraft
    {
        return $this->meeting->drafts()->where('status', MeetingTaskDraft::STATUS_DRAFT)->findOrFail($id);
    }

    /** @return list<int> */
    private function openIds(): array
    {
        return $this->meeting->drafts()->where('status', MeetingTaskDraft::STATUS_DRAFT)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function selectAllOpen(): void
    {
        $this->selected = $this->openIds();
    }
}; ?>

<div class="space-y-4" data-ui="meeting-show">
    <x-slot name="title">{{ __('meetings.title') }}</x-slot>

    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('meetings.index') }}" wire:navigate class="text-xs font-medium text-primary-600 hover:text-primary-800">← {{ __('meetings.back') }}</a>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $meeting->title }}</h2>
            <p class="mt-0.5 text-sm text-gray-500">
                {{ $meeting->started_at->timezone('Europe/Kyiv')->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY, HH:mm') }}
                · {{ __('meetings.minutes', ['count' => $meeting->duration_minutes]) }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="{{ $meeting->firefliesUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-surface px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 010 1.972l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z" /></svg>
                {{ __('meetings.open_fireflies') }}
            </a>
        </div>
    </div>

    @if (session('meeting_status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('meeting_status') }}</div>
    @endif
    @if (session('meeting_error'))
        <div class="whitespace-pre-line rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ session('meeting_error') }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-3">
        {{-- Main column --}}
        <div class="space-y-4 xl:col-span-2">
            <div class="flex gap-1 rounded-lg bg-gray-100 p-1 text-sm">
                @foreach (['tasks' => __('meetings.tab_tasks').($openCount ? " · {$openCount}" : ''), 'transcript' => __('meetings.tab_transcript'), 'fireflies' => __('meetings.tab_fireflies')] as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')"
                        class="flex-1 rounded-md px-3 py-1.5 font-medium transition-colors {{ $tab === $key ? 'bg-surface text-primary-700 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'tasks')
                <x-card padding="p-0">
                    {{-- Toolbar --}}
                    <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-xs text-gray-500">
                            @if ($meeting->analyzed_at)
                                {{ __('meetings.analyzed_by', ['date' => $meeting->analyzed_at->timezone('Europe/Kyiv')->format('d.m.Y H:i'), 'model' => $meeting->analysis_model]) }}
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($meeting->isBeingAnalyzed())
                                <span wire:poll.4s="checkAnalysis" class="inline-flex items-center gap-2 text-xs text-primary-700" data-ui="meeting-analyzing">
                                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                    {{ __('meetings.analyzing') }}
                                </span>
                            @else
                            <button type="button" wire:click="analyze" wire:loading.attr="disabled"
                                @if ($meeting->analyzed_at) wire:confirm="{{ __('meetings.reanalyze_confirm') }}" @endif
                                class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium shadow-sm disabled:opacity-60 {{ $meeting->analyzed_at ? 'border border-gray-200 bg-surface text-gray-700 hover:bg-gray-50' : 'bg-brand-600 text-white hover:bg-brand-700' }}">
                                <svg wire:loading wire:target="analyze" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                <svg wire:loading.remove wire:target="analyze" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                                {{ $meeting->analyzed_at ? __('meetings.reanalyze') : __('meetings.analyze') }}
                            </button>
                            @endif
                        </div>
                    </div>

                    @if ($meeting->analysis_error && ! $meeting->isBeingAnalyzed())
                        <div class="border-b border-red-100 bg-red-50 px-4 py-2 text-xs text-red-800">{{ __('meetings.analysis_failed', ['error' => $meeting->analysis_error]) }}</div>
                    @endif
                    @if (! $meeting->analyzed_at)
                        <div class="px-4 py-6"><x-empty-state>{{ $meeting->isBeingAnalyzed() ? __('meetings.analyzing') : __('meetings.not_analyzed_hint') }}</x-empty-state></div>
                    @elseif ($drafts->isEmpty())
                        <div class="px-4 py-6"><x-empty-state>{{ __('meetings.no_drafts') }}</x-empty-state></div>
                    @else
                        <ul class="divide-y divide-gray-100" data-ui="meeting-drafts">
                            @foreach ($drafts as $draft)
                                @php($open = $draft->status === \App\Models\MeetingTaskDraft::STATUS_DRAFT)
                                <li wire:key="draft-{{ $draft->id }}" class="flex gap-3 px-4 py-3 {{ $draft->status === 'dismissed' ? 'opacity-50' : '' }}">
                                    <div class="pt-1">
                                        @if ($open)
                                            <input type="checkbox" value="{{ $draft->id }}" wire:model.live="selected" aria-label="{{ __('Select') }}: {{ $draft->title }}" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                        @elseif ($draft->status === 'created')
                                            <svg class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        @else
                                            <span class="block h-4 w-4"></span>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1 space-y-1.5">
                                        @if ($open)
                                            <input type="text" value="{{ $draft->title }}" wire:change="setTitle({{ $draft->id }}, $event.target.value)" aria-label="{{ __('Title') }}"
                                                class="w-full rounded-md border-transparent px-1.5 py-0.5 -mx-1.5 text-sm font-medium text-gray-900 hover:border-gray-200 focus:border-primary-300 focus:ring-primary-200">
                                        @else
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $draft->title }}
                                                @if ($draft->task)
                                                    <a href="{{ route('tasks.show', $draft->task) }}" wire:navigate class="ml-1 text-primary-600 hover:underline">№{{ $draft->task->number }}</a>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($draft->description)
                                            <p class="text-xs text-gray-600">{{ $draft->description }}</p>
                                        @endif

                                        @if ($draft->quote)
                                            <div class="rounded-md border-l-2 border-primary-200 bg-primary-50/50 px-2.5 py-1.5 text-xs text-gray-700">
                                                «{{ $draft->quote }}»
                                                <span class="text-gray-500">— {{ $draft->speaker_name }}</span>
                                                @if ($draft->start_seconds !== null)
                                                    <span class="whitespace-nowrap">
                                                        · <a href="{{ $meeting->firefliesUrl($draft->start_seconds) }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline">▶ {{ \App\Models\Meeting::formatSeconds($draft->start_seconds) }} {{ __('meetings.in_recording') }}</a>
                                                        · <button type="button" wire:click="showAt({{ $draft->start_seconds }})" class="font-medium text-primary-600 hover:underline">{{ __('meetings.in_transcript') }}</button>
                                                    </span>
                                                @endif
                                            </div>
                                        @endif

                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                                            <label class="flex items-center gap-1.5">
                                                <span class="text-gray-500">{{ __('meetings.assignee') }}</span>
                                                @if ($open)
                                                    <select wire:change="setAssignee({{ $draft->id }}, $event.target.value)" class="rounded-md border-gray-200 py-0.5 pl-2 pr-7 text-xs focus:border-primary-300 focus:ring-primary-200 {{ $draft->assignee ? '' : 'text-red-600' }}">
                                                        <option value="">{{ __('meetings.no_assignee') }}</option>
                                                        @foreach ($users as $dept => $list)
                                                            <optgroup label="{{ $dept }}">
                                                                @foreach ($list as $u)
                                                                    <option value="{{ $u->id }}" @selected($draft->assignee_id === $u->id)>{{ $u->name }}</option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="font-medium text-gray-800">{{ $draft->assignee?->name ?? '—' }}</span>
                                                @endif
                                            </label>
                                            <label class="flex items-center gap-1.5">
                                                <span class="text-gray-500">{{ __('meetings.deadline') }}</span>
                                                @if ($open)
                                                    <input type="date" value="{{ $draft->deadline?->toDateString() }}" wire:change="setDeadline({{ $draft->id }}, $event.target.value)" class="rounded-md border-gray-200 py-0.5 px-2 text-xs focus:border-primary-300 focus:ring-primary-200">
                                                @else
                                                    <span class="text-gray-800">{{ $draft->deadline?->format('d.m.Y') ?? '—' }}</span>
                                                @endif
                                            </label>
                                            @if ($draft->deadline_phrase)
                                                <span class="text-gray-500">«{{ $draft->deadline_phrase }}»</span>
                                            @endif
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2 text-xs">
                                            @php($reasonColour = match ($draft->assignment_reason) { 'named' => 'text-green-700 bg-green-50 ring-green-200', 'manual' => 'text-gray-700 bg-gray-50 ring-gray-200', 'unassigned' => 'text-red-700 bg-red-50 ring-red-200', default => 'text-amber-800 bg-amber-50 ring-amber-200' })
                                            <span class="rounded px-1.5 py-0.5 ring-1 {{ $reasonColour }}">
                                                {{ __('meetings.reason.'.$draft->assignment_reason, ['name' => $draft->mentionedUser?->name ?? $draft->mentioned_name]) }}
                                            </span>
                                            @if ($draft->status === 'created')
                                                <span class="rounded bg-green-50 px-1.5 py-0.5 text-green-700 ring-1 ring-green-200">{{ __('meetings.created') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="shrink-0 pt-0.5">
                                        @if ($open)
                                            <button type="button" wire:click="dismiss({{ $draft->id }})" class="text-xs text-gray-500 hover:text-red-600">{{ __('meetings.dismiss') }}</button>
                                        @elseif ($draft->status === 'dismissed')
                                            <button type="button" wire:click="restore({{ $draft->id }})" class="text-xs text-primary-600 hover:text-primary-800">{{ __('meetings.restore') }}</button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        @if ($openCount > 0)
                            <div class="sticky bottom-0 flex flex-col gap-3 rounded-b-xl border-t border-gray-100 bg-surface/95 px-4 py-3 backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                                <button type="button" wire:click="toggleAll" class="text-left text-xs font-medium text-primary-600 hover:text-primary-800">{{ __('meetings.select_all') }}</button>
                                <div class="flex flex-wrap items-center gap-2">
                                    <label class="flex items-center gap-1.5 text-xs text-gray-500">
                                        {{ __('meetings.category') }}
                                        <select wire:model="categoryId" class="rounded-md border-gray-200 py-1 pl-2 pr-7 text-xs">
                                            @foreach ($categories as $category)
                                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="button" wire:click="createSelected" wire:loading.attr="disabled" @disabled(count($selected) === 0)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50">
                                        {{ __('meetings.create_selected', ['count' => count($selected)]) }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif
                </x-card>
            @elseif ($tab === 'transcript')
                <x-card padding="p-0">
                    <div x-data="{ q: '' }"
                         x-on:meeting-scroll.window="setTimeout(() => {
                             const box = $refs.box; const at = $event.detail.at;
                             const el = document.getElementById('t-' + at) || [...box.querySelectorAll('[data-at]')].reverse().find(n => +n.dataset.at <= at);
                             if (!el) return;
                             $el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                             box.scrollTo({ top: el.offsetTop - box.clientHeight / 3, behavior: 'smooth' });
                             el.classList.add('bg-yellow-200'); setTimeout(() => el.classList.remove('bg-yellow-200'), 3000);
                         }, 50)">
                        <div class="sticky top-0 z-10 border-b border-gray-100 bg-surface px-4 py-2.5">
                            <input type="search" x-model="q" placeholder="{{ __('meetings.search') }}" class="w-full rounded-md border-gray-200 text-sm focus:border-primary-300 focus:ring-primary-200">
                        </div>
                        <div x-ref="box" class="relative max-h-[70vh] space-y-3 overflow-y-auto px-4 py-3" data-ui="meeting-transcript">
                            @foreach ($blocks as $block)
                                <div class="flex gap-3" wire:key="b-{{ $loop->index }}"
                                     x-show="q === '' || {{ json_encode(mb_strtolower(implode(' ', array_column($block['lines'], 'text')).' '.$block['speaker'])) }}.includes(q.toLowerCase())">
                                    <span class="mt-1 h-7 w-7 shrink-0 rounded-full {{ $colours[$block['speaker']] ?? 'bg-gray-400' }} text-center text-xs font-semibold leading-7 text-white">{{ mb_strtoupper(mb_substr($block['speaker'], 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <div class="text-xs">
                                            <span class="font-semibold text-gray-900">{{ $block['speaker'] }}</span>
                                            @if (($speakerUsers[$block['speaker']] ?? null) && $speakerUsers[$block['speaker']] !== $block['speaker'])
                                                <span class="text-gray-500">· {{ $speakerUsers[$block['speaker']] }}</span>
                                            @endif
                                            <a href="{{ $meeting->firefliesUrl($block['start']) }}" target="_blank" rel="noopener" class="ml-1 text-gray-500 hover:text-primary-600">{{ \App\Models\Meeting::formatSeconds($block['start']) }}</a>
                                        </div>
                                        <p class="text-sm leading-relaxed text-gray-800">
                                            @foreach ($block['lines'] as $line)
                                                <span id="t-{{ (int) floor($line['start']) }}" data-at="{{ (int) floor($line['start']) }}" class="rounded transition-colors">{{ $line['text'] }}</span>
                                            @endforeach
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-card>
            @else
                <x-card>
                    <p class="mb-3 text-xs text-gray-500">{{ __('meetings.fireflies_hint') }}</p>
                    <h3 class="mb-2 text-sm font-semibold text-gray-900">{{ __('meetings.fireflies_action_items') }}</h3>
                    <div class="whitespace-pre-line text-sm text-gray-800">{{ str_replace('**', '', (string) $meeting->fireflies_action_items) ?: '—' }}</div>
                </x-card>
            @endif
        </div>

        {{-- Side column --}}
        <div class="space-y-4">
            @if ($isAdmin)
                <x-card data-ui="meeting-access">
                    <h3 class="mb-2 text-sm font-semibold text-gray-900">{{ __('meetings.access') }}</h3>
                    <div class="space-y-1.5">
                        @foreach (\App\Models\Meeting::ACCESS_MODES as $mode)
                            <label class="flex cursor-pointer items-start gap-2 text-sm">
                                <input type="radio" name="access_mode" value="{{ $mode }}" @checked($meeting->access_mode === $mode)
                                    wire:click="setAccessMode('{{ $mode }}')" class="mt-0.5 border-gray-300 text-primary-600 focus:ring-primary-500">
                                <span>
                                    <span class="font-medium text-gray-900">{{ __('meetings.access_mode.'.$mode) }}</span>
                                    <span class="block text-xs text-gray-500">{{ __('meetings.access_hint.'.$mode) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if ($meeting->access_mode !== \App\Models\Meeting::ACCESS_ADMINS)
                        <div class="mt-3 border-t border-gray-100 pt-3">
                            <div class="mb-1.5 text-xs font-medium text-gray-700">{{ __('meetings.viewers') }}</div>
                            <div class="flex flex-wrap gap-1.5">
                                @forelse ($viewers as $viewer)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-800 ring-1 ring-primary-200" wire:key="viewer-{{ $viewer->id }}">
                                        {{ $viewer->name }}
                                        <button type="button" wire:click="removeViewer({{ $viewer->id }})" class="text-primary-400 hover:text-red-600" title="{{ __('meetings.viewer_remove') }}">×</button>
                                    </span>
                                @empty
                                    <span class="text-xs text-gray-500">{{ __('meetings.viewers_none') }}</span>
                                @endforelse
                            </div>
                            <select wire:change="addViewer($event.target.value); $event.target.value = ''" aria-label="{{ __('meetings.viewer_add') }}" class="mt-2 w-full rounded-md border-gray-200 py-1 pl-2 pr-7 text-xs">
                                <option value="">{{ __('meetings.viewer_add') }}</option>
                                @foreach ($users as $dept => $list)
                                    <optgroup label="{{ $dept }}">
                                        @foreach ($list as $u)
                                            @unless ($u->isAdmin() || $viewers->contains('id', $u->id))
                                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                                            @endunless
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-600" data-ui="meeting-access-who">
                        <span class="font-medium text-gray-700">{{ __('meetings.access_now') }}</span>
                        {{ $accessWho ? implode(', ', $accessWho) : __('meetings.access_only_admins') }}
                    </div>
                </x-card>
            @endif
            @if ($meeting->summary)
                <x-card>
                    <h3 class="mb-2 text-sm font-semibold text-gray-900">{{ __('meetings.summary') }}</h3>
                    <p class="whitespace-pre-line text-sm leading-relaxed text-gray-700">{{ trim(str_replace('**', '', $meeting->summary)) }}</p>
                    @if ($meeting->keywords)
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($meeting->keywords as $keyword)
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $keyword }}</span>
                            @endforeach
                        </div>
                    @endif
                </x-card>
            @endif

            <x-card padding="p-0">
                <h3 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">{{ __('meetings.participants') }}</h3>
                <ul class="divide-y divide-gray-100" data-ui="meeting-participants">
                    @foreach ($participants as $i => $p)
                        <li class="px-4 py-2.5" wire:key="p-{{ $i }}-{{ $p['key'] }}">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ $p['speaker'] ? ($colours[$p['speaker']] ?? 'bg-gray-300') : 'bg-gray-200' }}"></span>
                                <span class="min-w-0 flex-1 truncate text-sm {{ $p['user'] ? 'font-medium text-gray-900' : 'text-gray-600' }}">{{ $p['label'] }}</span>
                                @if ($p['user'] && $p['source'] !== 'none')
                                    <span class="text-xs text-gray-500">{{ __('meetings.source.'.$p['source']) }}</span>
                                @endif
                            </div>
                            <div class="ml-4 mt-0.5 truncate text-xs text-gray-500">
                                {{ collect([$p['speaker'] && $p['speaker'] !== $p['label'] ? $p['speaker'] : null, $p['email']])->filter()->implode(' · ') }}
                                @if (! $p['spoke']) <span class="text-gray-500">· {{ __('meetings.invited') }}</span> @endif
                            </div>
                            @if ($isAdmin)
                                <select wire:change="mapPerson({{ $i }}, $event.target.value)" aria-label="{{ __('Employee') }}: {{ $p['label'] }}" class="ml-4 mt-1 w-[calc(100%-1rem)] rounded-md border-gray-200 py-0.5 pl-2 pr-7 text-xs {{ $p['user'] || $p['external'] ? 'text-gray-600' : 'text-amber-700' }}">
                                    <option value="" @selected(! $p['user'] && ! $p['external'])>{{ __('meetings.unmapped') }}</option>
                                    <option value="external" @selected($p['external'])>{{ __('meetings.external') }}</option>
                                    @foreach ($users as $dept => $list)
                                        <optgroup label="{{ $dept }}">
                                            @foreach ($list as $u)
                                                <option value="{{ $u->id }}" @selected($p['user']?->id === $u->id)>{{ $u->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            @elseif ($p['external'])
                                <div class="ml-4 text-xs text-gray-500">{{ __('meetings.external') }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</div>
