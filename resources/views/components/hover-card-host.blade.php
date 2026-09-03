<div
    x-data="uiFloatHost('hoverCard')"
    data-ui-float="panel"
    class="pointer-events-none fixed z-[85]"
    x-bind:class="{ 'is-open': $store.hoverCard.open }"
    x-bind:style="`left: ${$store.hoverCard.x}px; top: ${$store.hoverCard.y}px`"
>
    <div
        x-ref="panel"
        class="pointer-events-auto w-72 rounded-lg border border-gray-200 bg-white p-3 shadow-lg"
        x-on:mouseenter="$store.hoverCard.keep()"
        x-on:mouseleave="$store.hoverCard.hide()"
    >
        <template x-if="$store.hoverCard.data">
            <div class="space-y-2">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs tabular-nums text-gray-400" x-text="'#' + $store.hoverCard.data.number"></p>
                    <span
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        x-bind:class="$store.hoverCard.data.statusClass"
                        x-text="$store.hoverCard.data.status"
                    ></span>
                </div>
                <p class="text-sm font-medium leading-5 text-gray-900" x-text="$store.hoverCard.data.title || $store.hoverCard.data.excerpt"></p>
                <p class="text-xs text-gray-500">
                    <span x-text="$store.hoverCard.data.assignee || '—'"></span>
                    <template x-if="$store.hoverCard.data.department">
                        <span>
                            <span aria-hidden="true"> · </span>
                            <span x-text="$store.hoverCard.data.department"></span>
                        </span>
                    </template>
                </p>
                <p class="text-xs text-gray-500" x-show="$store.hoverCard.data.deadline" x-text="$store.hoverCard.data.deadline"></p>
                <p class="text-xs leading-4 text-gray-500" x-show="$store.hoverCard.data.excerpt && $store.hoverCard.data.title" x-text="$store.hoverCard.data.excerpt"></p>
            </div>
        </template>
    </div>
</div>
