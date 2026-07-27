@props(['title'])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between py-4']) }}>
    <div class="min-w-0">
        @isset($breadcrumbs)
            <div class="mb-1">
                {{ $breadcrumbs }}
            </div>
        @endisset
        <h1 class="text-lg font-semibold text-gray-900 leading-tight truncate">
            {{ $title }}
        </h1>
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
