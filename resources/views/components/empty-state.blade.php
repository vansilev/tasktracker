@props(['title' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center py-12 px-4']) }}>
    @isset($icon)
        <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-50 text-gray-400 ring-1 ring-gray-100">
            {{ $icon }}
        </div>
    @endisset

    @if (filled($title))
        <h3 class="text-sm font-semibold text-gray-900">{{ $title }}</h3>
    @endif

    <div @class(['text-sm text-gray-500 max-w-sm', 'mt-1' => filled($title)])>
        {{ $slot }}
    </div>

    @isset($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endisset
</div>
