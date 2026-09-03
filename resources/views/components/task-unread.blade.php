@props(['count' => 0])

@php
    $n = (int) $count;
    $label = $n === 1
        ? __('New activity')
        : __(':count new', ['count' => $n]);
@endphp

@if ($n > 0)
    <span
        {{ $attributes->merge([
            'data-ui' => 'task-unread',
            'data-unread-count' => (string) $n,
            'class' => $n === 1
                ? 'inline-block h-2 w-2 shrink-0 rounded-full bg-indigo-500'
                : 'inline-flex h-4 min-w-[1.125rem] shrink-0 items-center justify-center rounded-full bg-indigo-600 px-1 text-[10px] font-bold leading-none text-white',
        ]) }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
    >{{ $n > 1 ? ($n > 9 ? '9+' : $n) : '' }}</span>
@endif
