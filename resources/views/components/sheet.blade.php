@props([
    'open' => false,
])

<div
    data-ui="sheet"
    class="fixed inset-0 z-[70]"
    x-data="{
        width: 640,
        minWidth: 480,
        maxWidth: 1100,
        resizing: false,
        hoveringHandle: false,
        init() {
            const saved = Number(window.localStorage.getItem('tasktracker.peekWidth') || 0);
            if (saved > 0) {
                this.width = Math.max(this.minWidth, Math.min(this.maxWidth, saved));
            }
            document.documentElement.classList.add('overflow-hidden');
        },
        destroy() {
            this.stopResize();
            document.documentElement.classList.remove('overflow-hidden');
        },
        panelStyle() {
            if (! window.matchMedia('(min-width: 768px)').matches) {
                return '';
            }

            const width = `width: min(100vw, ${this.width}px)`;
            if (! (this.hoveringHandle || this.resizing)) {
                return width;
            }

            return width + '; border-left-color:#4f46e5; border-left-width:3px';
        },
        startResize(event) {
            if (! window.matchMedia('(min-width: 768px)').matches) {
                return;
            }

            this.resizing = true;
            this.hoveringHandle = true;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            this.onResizeMove = this.onResizeMove?.bind(this) || ((e) => {
                const next = window.innerWidth - e.clientX;
                this.width = Math.max(this.minWidth, Math.min(this.maxWidth, next));
            });
            this.onResizeUp = this.onResizeUp?.bind(this) || (() => this.stopResize());
            window.addEventListener('pointermove', this.onResizeMove);
            window.addEventListener('pointerup', this.onResizeUp, { once: true });
            event.preventDefault();
        },
        stopResize() {
            if (! this.resizing) {
                return;
            }

            this.resizing = false;
            this.hoveringHandle = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            window.removeEventListener('pointermove', this.onResizeMove);
            window.localStorage.setItem('tasktracker.peekWidth', String(Math.round(this.width)));
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
        x-bind:style="panelStyle()"
        x-on:click.stop
    >
        <div
            role="separator"
            aria-orientation="vertical"
            aria-label="{{ __('Resize preview panel') }}"
            title="{{ __('Resize preview panel') }}"
            data-ui="sheet-resize-handle"
            class="absolute left-0 top-0 z-20 hidden w-6 -translate-x-1/2 touch-none select-none md:block"
            style="height:100%; cursor:col-resize"
            x-on:pointerdown="startResize($event)"
            x-on:mouseenter="hoveringHandle = true"
            x-on:mouseleave="hoveringHandle = false"
        ></div>
        {{ $slot }}
    </div>
</div>
