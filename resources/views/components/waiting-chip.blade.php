@props(['open' => true])

@php
    $style = $open
        ? 'background:#FACC15;color:#422006;border:1px solid #CA8A04'
        : 'background:#E5E7EB;color:#6B7280;border:1px solid #D1D5DB';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold leading-4']) }} data-open="{{ $open ? '1' : '0' }}" style="{{ $style }}">{{ $slot }}</span>
