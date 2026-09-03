@props(['task'])

<button
    type="button"
    {{ $attributes->merge(['class' => 'relative z-10 shrink-0 text-sm tabular-nums text-gray-400 hover:text-indigo-700']) }}
    title="{{ __('Copy link') }}"
    aria-label="{{ __('Copy link to #:number', ['number' => $task->number]) }}"
    onclick="event.stopPropagation()"
    x-on:click.stop="window.uiCopy({{ \Illuminate\Support\Js::from(route('tasks.show', $task)) }}, {{ \Illuminate\Support\Js::from(__('Link to #:number copied', ['number' => $task->number])) }})"
>
    #{{ $task->number }}
</button>
