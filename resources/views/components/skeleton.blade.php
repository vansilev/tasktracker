@props([
    'rows' => 6,
])

<div {{ $attributes->merge(['class' => 'animate-pulse']) }} aria-hidden="true">
    <div class="hidden md:block">
        <div class="border-b border-gray-100 bg-gray-50/80 px-4 py-2.5">
            <div class="flex gap-8">
                <div class="h-3 w-24 rounded bg-gray-200"></div>
                <div class="h-3 w-16 rounded bg-gray-200"></div>
                <div class="h-3 w-20 rounded bg-gray-200"></div>
                <div class="h-3 w-28 rounded bg-gray-200"></div>
                <div class="h-3 w-16 rounded bg-gray-200"></div>
            </div>
        </div>
        @for ($i = 0; $i < $rows; $i++)
            <div class="flex items-center gap-4 border-b border-gray-50 px-4 py-3">
                <div class="h-4 w-10 rounded bg-gray-200"></div>
                <div class="h-4 flex-1 rounded bg-gray-100"></div>
                <div class="h-5 w-20 rounded-full bg-gray-100"></div>
                <div class="h-3 w-24 rounded bg-gray-100"></div>
            </div>
        @endfor
    </div>
    <div class="divide-y divide-gray-100 md:hidden">
        @for ($i = 0; $i < min(4, $rows); $i++)
            <div class="space-y-2 px-4 py-4">
                <div class="h-4 w-3/4 rounded bg-gray-200"></div>
                <div class="h-3 w-1/2 rounded bg-gray-100"></div>
            </div>
        @endfor
    </div>
</div>
