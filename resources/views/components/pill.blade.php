@props(['color' => 'gray'])

@php
    $classes = match ($color) {
        'green' => 'bg-green-100 text-green-800 ring-1 ring-green-200',
        'red' => 'bg-red-100 text-red-800 ring-1 ring-red-200',
        'blue' => 'bg-blue-100 text-blue-800 ring-1 ring-blue-200',
        'amber' => 'bg-amber-100 text-amber-800 ring-1 ring-amber-200',
        'indigo' => 'bg-primary-100 text-primary-800 ring-1 ring-primary-200',
        'purple' => 'bg-purple-100 text-purple-800 ring-1 ring-purple-200',
        default => 'bg-gray-100 text-gray-700 ring-1 ring-gray-200',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold '.$classes]) }}>
    {{ $slot }}
</span>
