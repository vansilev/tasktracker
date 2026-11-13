@props([
    'variant' => 'primary',
    'size' => 'sm',
    'type' => 'button',
])

@php
$base = 'inline-flex items-center justify-center gap-1.5 font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed';

$sizes = [
    'sm' => 'px-2.5 py-1.5 text-xs rounded-lg',
    'md' => 'px-4 py-2 text-sm rounded-lg',
];

$variants = [
    'primary' => 'bg-indigo-600 border border-transparent text-white hover:bg-indigo-700 focus:ring-indigo-500 active:bg-indigo-800',
    'secondary' => 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:ring-indigo-500',
    'danger' => 'bg-red-600 border border-transparent text-white hover:bg-red-700 focus:ring-red-500 active:bg-red-700',
    'ghost' => 'border border-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus:ring-gray-400',
];

$classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

<button {{ $attributes->merge(['type' => $type, 'class' => $classes]) }}>
    {{ $slot }}
</button>
