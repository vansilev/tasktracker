@props(['keys' => null])

@php
    $parts = $keys === null ? [] : (is_array($keys) ? $keys : [$keys]);
@endphp

@if (count($parts) === 1)
    <kbd {{ $attributes->merge(['class' => 'pointer-events-none inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded border border-gray-200 bg-gray-50 px-1 font-sans text-[10px] font-medium leading-none text-gray-500']) }}>
        {{ $parts[0] }}
    </kbd>
@elseif (count($parts) > 1)
    <span {{ $attributes->merge(['class' => 'pointer-events-none inline-flex items-center gap-0.5']) }}>
        @foreach ($parts as $key)
            <kbd class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded border border-gray-200 bg-gray-50 px-1 font-sans text-[10px] font-medium leading-none text-gray-500">{{ $key }}</kbd>
        @endforeach
    </span>
@else
    <kbd {{ $attributes->merge(['class' => 'pointer-events-none inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded border border-gray-200 bg-gray-50 px-1 font-sans text-[10px] font-medium leading-none text-gray-500']) }}>
        {{ $slot }}
    </kbd>
@endif
