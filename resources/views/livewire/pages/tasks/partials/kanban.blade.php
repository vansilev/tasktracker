<div
    data-ui="kanban"
    class="overflow-x-auto pb-2"
    x-data="kanbanBoard"
>
    <p class="mb-2 text-xs text-gray-500">{{ __('Drag a card to another column to change its status.') }}</p>
    <div class="flex min-w-max items-start gap-3">
        @foreach ($boardColumns as $column)
            <section
                data-kanban-column="{{ $column['status'] }}"
                class="flex w-72 shrink-0 flex-col rounded-xl border border-gray-200 bg-gray-50/80"
            >
                <header class="flex items-center justify-between gap-2 px-3 py-2.5">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $column['badge'] }}">
                        {{ $column['label'] }}
                    </span>
                    <span class="text-xs tabular-nums text-gray-500">{{ $column['total'] }}</span>
                </header>

                <div class="flex max-h-[70vh] flex-col gap-2 overflow-y-auto px-2 pb-3">
                    @forelse ($column['tasks'] as $task)
                        @php
                            $deadline = $this->deadlineMeta($task);
                            $rowMenu = \Illuminate\Support\Js::from($this->rowActions($task));
                        @endphp
                        <article
                            data-kanban-card="{{ $task->id }}"
                            draggable="false"
                            class="cursor-grab select-none rounded-lg border border-gray-100 bg-white p-3 shadow-sm transition hover:border-indigo-200"
                            style="touch-action:none"
                            x-on:click="if (window.getSelection()?.toString() || window.uiContext?.suppressClick) return; $wire.openPeek({{ $task->number }})"
                            x-on:contextmenu.prevent="window.uiContext.show($event, {{ $rowMenu }})"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <x-task-number :task="$task" />
                                        <x-task-unread :count="(int) ($unreadCounts[$task->id] ?? 0)" />
                                        <span class="truncate text-sm {{ ($unreadCounts[$task->id] ?? 0) > 0 ? 'font-semibold text-gray-950' : 'font-medium text-gray-900' }}">{{ $task->title ?: Str::limit($task->plainDescription(), 60) }}</span>
                                    </div>
                                    @if ($task->parent)
                                        <p class="mt-0.5 truncate text-xs text-indigo-600">
                                            {{ __('Part of #:number · :title', ['number' => $task->parent->number, 'title' => $task->parent->title]) }}
                                        </p>
                                    @endif
                                </div>
                                <x-priority-bar :priority="$task->priority" />
                            </div>
                            <p class="mt-2 truncate text-xs text-gray-500">
                                {{ $task->assignee?->name }}
                                @if ($task->department?->name)
                                    <span aria-hidden="true">&middot;</span>
                                    {{ $task->department->name }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs {{ $deadline['class'] }}">{{ $deadline['text'] }}</p>
                        </article>
                    @empty
                        <p class="px-1 py-6 text-center text-xs text-gray-400">{{ __('No tasks') }}</p>
                    @endforelse

                    @if ($column['total'] > $column['tasks']->count())
                        <p class="px-1 text-center text-xs text-gray-400">
                            {{ __('Showing :shown of :total', ['shown' => $column['tasks']->count(), 'total' => $column['total']]) }}
                        </p>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
