@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'h-9 px-3 text-body text-gray-900 border-gray-300 rounded-control placeholder:text-gray-500 focus:border-primary-600 focus:ring-primary-600 disabled:bg-gray-50 disabled:text-gray-500']) }}>
