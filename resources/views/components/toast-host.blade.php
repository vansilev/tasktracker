<div
    x-data
    class="pointer-events-none fixed bottom-4 right-4 z-[80] flex w-[min(100%-2rem,22rem)] flex-col gap-2"
    aria-live="polite"
>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            class="pointer-events-auto flex items-start gap-3 rounded-lg border border-gray-200 bg-white px-3.5 py-3 text-sm text-gray-900 shadow-lg"
        >
            <p class="min-w-0 flex-1 leading-5" x-text="toast.message"></p>
            <template x-if="toast.undo">
                <button
                    type="button"
                    x-on:click="$store.toasts.undo(toast)"
                    data-ui="toast-undo"
                    class="shrink-0 rounded-md px-1.5 py-0.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50"
                >
                    {{ __('Undo') }}
                </button>
            </template>
            <button
                type="button"
                class="shrink-0 rounded-md p-0.5 text-gray-400 hover:bg-gray-50 hover:text-gray-700"
                x-on:click="$store.toasts.dismiss(toast.id)"
                aria-label="{{ __('Close') }}"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </template>
</div>
