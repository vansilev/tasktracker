@props(['padding' => 'p-5'])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl shadow-sm border border-gray-100 '.$padding]) }}>
    @if (isset($header) || isset($actions) || isset($title))
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 pb-4 border-b border-gray-100">
            <div class="min-w-0">
                @isset($title)
                    <h2 class="text-sm font-semibold text-gray-900">{{ $title }}</h2>
                @elseif (isset($header))
                    {{ $header }}
                @endif
            </div>

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</div>
