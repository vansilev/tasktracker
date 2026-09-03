<button
    type="button"
    {{ $attributes->merge(['class' => 'relative z-10 shrink-0 text-sm tabular-nums text-gray-400 hover:text-indigo-700']) }}
    title="{{ __('Copy link') }}"
    aria-label="{{ __('Copy link to #:number', ['number' => $task->number]) }}"
    onclick="event.stopPropagation()"
    x-on:click.stop="window.uiCopy({{ \Illuminate\Support\Js::from(route('tasks.show', $task)) }}, {{ \Illuminate\Support\Js::from(__('Link to #:number copied', ['number' => $task->number])) }})"
    x-on:mouseenter="window.uiHover.show($event.currentTarget, {{ \Illuminate\Support\Js::from([
        'number' => $task->number,
        'title' => $task->title ?: '',
        'status' => $task->status->label(),
        'statusClass' => $task->status->badgeClasses(),
        'assignee' => $task->assignee?->name,
        'department' => $task->department?->name,
        'deadline' => $task->deadline?->timezone(config('app.timezone'))->format('d.m.Y'),
        'excerpt' => \Illuminate\Support\Str::limit($task->plainDescription(), 180),
    ]) }})"
    x-on:mouseleave="window.uiHover.hide()"
>
    #{{ $task->number }}
</button>
