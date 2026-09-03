@props([
    'open' => false,
])

<div
    data-ui="sheet"
    class="fixed inset-0 z-[70]"
    x-data="{
        init() {
            document.documentElement.classList.add('overflow-hidden');
        },
        destroy() {
            document.documentElement.classList.remove('overflow-hidden');
        },
    }"
    x-on:keydown.escape.window="
        if ($event.defaultPrevented || document.querySelector('[data-ui=command-palette]')) {
            return;
        }
        $event.preventDefault();
        $wire.closePeek();
    "
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Task preview') }}"
>
    <button
        type="button"
        class="absolute inset-0 bg-gray-900/30"
        wire:click="closePeek"
        tabindex="-1"
        aria-label="{{ __('Close') }}"
    ></button>

    <div
        class="absolute inset-x-0 bottom-0 flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl md:inset-y-0 md:right-0 md:left-auto md:max-h-none md:w-[min(100%,40rem)] md:rounded-none md:border-l md:border-gray-200"
        x-on:click.stop
    >
        {{ $slot }}
    </div>
</div>
