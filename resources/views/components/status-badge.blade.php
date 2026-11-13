@props(['status'])

<span {{ $attributes->merge(['class' => 'inline-flex items-center whitespace-nowrap rounded-control px-2 py-0.5 text-caption font-medium '.$status->badgeClasses()]) }}>
    {{ $status->label() }}
</span>
