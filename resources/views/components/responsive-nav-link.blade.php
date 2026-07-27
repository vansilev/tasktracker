@props(['active'])

@php
$classes = ($active ?? false)
            ? 'flex items-center gap-2 w-full ps-3 pe-4 py-2 rounded-lg text-start text-sm font-medium text-indigo-700 bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition duration-150 ease-in-out'
            : 'flex items-center gap-2 w-full ps-3 pe-4 py-2 rounded-lg text-start text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
