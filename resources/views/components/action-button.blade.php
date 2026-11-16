{{-- Deprecated: use <x-button size="sm">. Kept for unmerged branches. --}}
@props(['variant' => 'primary', 'size' => 'sm'])
<x-button :variant="$variant" :size="$size" :attributes="$attributes">{{ $slot }}</x-button>
