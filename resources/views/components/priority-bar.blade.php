@props(['priority' => 5, 'size' => 'sm'])

@php
    $bars = 10;
    $filled = max(1, min(10, (int) $priority));
    $color = match (true) {
        $priority >= 9 => 'bg-red-500',
        $priority >= 7 => 'bg-orange-400',
        $priority >= 4 => 'bg-yellow-400',
        default => 'bg-green-400',
    };
    $h = $size === 'lg' ? 'h-4' : 'h-2';
    $w = $size === 'lg' ? 'w-2' : 'w-1';
@endphp

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5']) }}>
    @for ($i = 1; $i <= $bars; $i++)
        <span class="{{ $w }} {{ $h }} rounded-sm {{ $i <= $filled ? $color : 'bg-gray-200' }}"></span>
    @endfor
    @if ($priority >= 9)
        <span class="ms-1" title="{{ __('Urgent') }}">🔥</span>
    @endif
</div>
