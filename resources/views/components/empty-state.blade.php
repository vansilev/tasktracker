<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center py-10 px-4']) }}>
    @isset($icon)
        <div class="mb-3 text-gray-400">
            {{ $icon }}
        </div>
    @endisset

    <div class="text-sm text-gray-500 max-w-sm">
        {{ $slot }}
    </div>

    @isset($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endisset
</div>
