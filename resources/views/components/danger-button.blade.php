{{-- Deprecated: use <x-button variant="danger" type="submit">. Kept for unmerged branches. --}}
<x-button variant="danger" :attributes="$attributes->merge(['type' => 'submit'])">{{ $slot }}</x-button>
