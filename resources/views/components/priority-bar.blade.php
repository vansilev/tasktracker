{{--
    Priority. Default (lists): a plain number, a gold "Urgent" mark from 9
    (same threshold as Task::isUrgent and the "Urgent only" filter).
    mode="scale": the 10-step scale, for forms where the value is being picked.
    mode="value": "N / 10" text, for the task details panel.
--}}
@props(['priority' => 5, 'size' => 'sm', 'mode' => null])

@php
    $priority = (int) $priority;
    $mode = $mode ?? 'compact';
    $urgent = $priority >= 9;
@endphp

@if ($mode === 'scale')
    @php
        $filled = max(1, min(10, $priority));
        $h = $size === 'lg' ? 'h-3' : 'h-2';
        $w = $size === 'lg' ? 'w-2' : 'w-1.5';
    @endphp
    <div {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5']) }} role="img" aria-label="{{ __('Priority') }}: {{ $priority }} / 10">
        @for ($i = 1; $i <= 10; $i++)
            <span class="{{ $w }} {{ $h }} rounded-sm {{ $i <= $filled ? ($urgent ? 'bg-accent-500' : 'bg-primary-600') : 'bg-gray-200' }}"></span>
        @endfor
    </div>
@elseif ($mode === 'value')
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-body tabular-nums '.($urgent ? 'text-accent-700 font-medium' : 'text-gray-900')]) }}>
        @if ($urgent)<x-icon.fire class="w-4 h-4 text-accent-600" />@endif
        {{ $priority }} / 10
    </span>
@elseif ($urgent)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-caption font-medium text-accent-700']) }} title="{{ __('Priority') }}: {{ $priority }} / 10">
        <x-icon.fire class="w-3.5 h-3.5 text-accent-600" />{{ __('Urgent priority') }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-body-sm tabular-nums text-gray-600']) }} title="{{ __('Priority') }}: {{ $priority }} / 10">{{ $priority }}</span>
@endif
