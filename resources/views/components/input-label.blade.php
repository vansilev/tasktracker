@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-body text-gray-700']) }}>
    {{ $value ?? $slot }}
</label>
