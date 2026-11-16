{{--
    File picker styled as a secondary button instead of the browser's "Choose files".
    The native input stays in the DOM (visually hidden) so Livewire uploads and keyboard focus work.
    Pass names="false" where the page already lists the chosen files.
--}}
@props([
    'id' => null,
    'label' => null,
    'names' => true,
])

@php
    $id = $id ?? 'file-'.\Illuminate\Support\Str::slug($attributes->wire('model')->value() ?: 'input');
@endphp

<div {{ $attributes->only('class') }} @if ($names) x-data="{ names: [] }" @endif>
    <input
        id="{{ $id }}"
        type="file"
        class="peer sr-only"
        {{ $attributes->except('class') }}
        @if ($names) x-on:change="names = Array.from($event.target.files).map((file) => file.name)" @endif
    />
    <label for="{{ $id }}"
           class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-control border border-gray-300 bg-surface px-4 text-body font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-900 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-600 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed peer-disabled:opacity-50">
        <x-icon.paper-clip class="w-4 h-4 text-gray-500" />
        {{ $label ?? __('Attach files') }}
    </label>
    @if ($names)
        <p class="mt-1 text-caption text-gray-600 break-words" x-show="names.length" x-text="names.join(', ')" x-cloak></p>
    @endif
</div>
