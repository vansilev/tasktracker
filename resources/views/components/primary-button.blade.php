{{-- Deprecated: use <x-button variant="primary" type="submit">. Kept for unmerged branches. --}}
<x-button variant="primary" :attributes="$attributes->merge(['type' => 'submit'])">{{ $slot }}</x-button>
