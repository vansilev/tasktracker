{{--
    The one button. Variants: primary (at most one per screen or block), secondary,
    ghost (row and secondary actions), danger (confirmations only).
    Sizes: sm 32px, md 36px. Pass href to render a link styled as a button.
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    // Toggle buttons: true/false renders the pressed look and aria-pressed; null = plain button.
    'pressed' => null,
])

@php
$base = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-control border font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed aria-disabled:opacity-50 aria-disabled:pointer-events-none';

$sizes = [
    'sm' => 'h-8 px-3 text-body-sm',
    'md' => 'h-9 px-4 text-body',
];

$variants = [
    'primary' => 'border-transparent bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 focus-visible:ring-primary-600',
    'secondary' => 'border-gray-300 bg-surface text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus-visible:ring-primary-600',
    'ghost' => 'border-transparent bg-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-primary-600',
    'danger' => 'border-transparent bg-alert-600 text-white hover:bg-alert-700 active:bg-alert-800 focus-visible:ring-danger-600',
];

if ($pressed === true && $variant === 'secondary') {
    $variants['secondary'] = 'border-primary-300 bg-primary-50 text-primary-700 hover:bg-primary-100 focus-visible:ring-primary-600';
}

$classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
if ($pressed !== null) {
    $attributes = $attributes->merge(['aria-pressed' => $pressed ? 'true' : 'false']);
}
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => $type, 'class' => $classes]) }}>{{ $slot }}</button>
@endif
