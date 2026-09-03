function isEditableTarget(target) {
    if (!target || typeof target.closest !== 'function') {
        return false;
    }

    return Boolean(target.closest('input, textarea, select, [contenteditable="true"]'));
}

function sameValue(a, b) {
    if (a === null || a === undefined || a === '') {
        return b === null || b === undefined || b === '';
    }

    return String(a) === String(b);
}

function clampPanel(x, y, width, height) {
    const pad = 8;
    const maxX = Math.max(pad, window.innerWidth - width - pad);
    const maxY = Math.max(pad, window.innerHeight - height - pad);

    return {
        x: Math.min(Math.max(pad, x), maxX),
        y: Math.min(Math.max(pad, y), maxY),
    };
}

function dispatchLivewire(name, params) {
    if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
        window.Livewire.dispatch(name, params);
    }
}

export default function registerUiKit(Alpine) {
    Alpine.store('toasts', {
        items: [],
        show(message, timeoutOrOptions = 3200) {
            const options = typeof timeoutOrOptions === 'number'
                ? { timeout: timeoutOrOptions }
                : (timeoutOrOptions || {});
            const id = Date.now() + Math.random();
            const timeout = options.timeout ?? 3200;
            this.items.push({
                id,
                message,
                undo: options.undo || null,
            });
            window.setTimeout(() => this.dismiss(id), timeout);
        },
        undo(toast) {
            this.dismiss(toast.id);
            if (toast.undo?.event) {
                dispatchLivewire(toast.undo.event, toast.undo.params || {});
            }
        },
        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    });

    Alpine.store('hoverCard', {
        open: false,
        x: 0,
        y: 0,
        data: null,
        showTimer: null,
        hideTimer: null,
        show(anchor, data) {
            window.clearTimeout(this.hideTimer);
            window.clearTimeout(this.showTimer);
            this.showTimer = window.setTimeout(() => {
                const rect = anchor.getBoundingClientRect();
                this.data = data;
                this.x = rect.left;
                this.y = rect.bottom + 8;
                this.open = true;
                this.$nextTick?.();
            }, 200);
        },
        keep() {
            window.clearTimeout(this.hideTimer);
        },
        hide() {
            window.clearTimeout(this.showTimer);
            this.hideTimer = window.setTimeout(() => {
                this.open = false;
            }, 160);
        },
        close() {
            window.clearTimeout(this.showTimer);
            window.clearTimeout(this.hideTimer);
            this.open = false;
        },
    });

    Alpine.store('contextMenu', {
        open: false,
        x: 0,
        y: 0,
        view: 'root',
        payload: null,
        comment: '',
        commentTarget: null,
        touchTimer: null,
        touchPayload: null,
        suppressClick: false,
        show(event, payload) {
            event.preventDefault();
            event.stopPropagation();
            Alpine.store('hoverCard').close();
            this.payload = payload;
            this.view = 'root';
            this.comment = '';
            this.commentTarget = null;
            this.open = true;
            this.x = event.clientX;
            this.y = event.clientY;
            this.suppressClick = true;
            window.setTimeout(() => {
                this.suppressClick = false;
            }, 400);
        },
        touchStart(event, payload) {
            if (event.touches.length !== 1) {
                return;
            }

            this.touchCancel();
            this.touchPayload = payload;
            const touch = event.touches[0];
            this.touchTimer = window.setTimeout(() => {
                this.show({
                    preventDefault() {},
                    stopPropagation() {},
                    clientX: touch.clientX,
                    clientY: touch.clientY,
                }, payload);
            }, 520);
        },
        touchCancel() {
            window.clearTimeout(this.touchTimer);
            this.touchTimer = null;
            this.touchPayload = null;
        },
        close() {
            this.open = false;
            this.view = 'root';
            this.comment = '';
            this.commentTarget = null;
        },
        openTask() {
            if (this.payload?.number) {
                dispatchLivewire('task-open-peek', { number: this.payload.number });
            } else if (this.payload?.url && window.Livewire?.navigate) {
                window.Livewire.navigate(this.payload.url);
            }
            this.close();
        },
        copyLink() {
            if (!this.payload) {
                return;
            }
            window.uiCopy(this.payload.url, this.payload.copyMessage);
            this.close();
        },
        pickStatus(item) {
            if (item.needsComment) {
                this.commentTarget = { kind: 'status', value: item.value, label: item.label };
                this.comment = '';
                this.view = 'comment';
                return;
            }
            dispatchLivewire('task-quick-transition', { taskId: this.payload.id, status: item.value });
            this.close();
        },
        pickAssignee(person) {
            if (Number(person.id) === Number(this.payload.assigneeId)) {
                this.close();
                return;
            }
            this.commentTarget = { kind: 'assign', value: person.id, label: person.name };
            this.comment = '';
            this.view = 'comment';
        },
        submitComment() {
            const text = this.comment.trim();
            if (text === '' || !this.commentTarget || !this.payload) {
                return;
            }
            if (this.commentTarget.kind === 'status') {
                dispatchLivewire('task-quick-transition', {
                    taskId: this.payload.id,
                    status: this.commentTarget.value,
                    comment: text,
                });
            } else {
                dispatchLivewire('task-quick-assign', {
                    taskId: this.payload.id,
                    userId: this.commentTarget.value,
                    comment: text,
                });
            }
            this.close();
        },
    });

    Alpine.data('uiCombobox', (config) => ({
        options: Array.isArray(config.options) ? config.options : [],
        placeholder: config.placeholder || '',
        disabled: Boolean(config.disabled),
        searchPlaceholder: config.searchPlaceholder || '',
        value: null,
        open: false,
        query: '',
        highlight: 0,

        isSelected(option) {
            return sameValue(option.value, this.value);
        },

        get selected() {
            return this.options.find((option) => sameValue(option.value, this.value)) ?? null;
        },

        get selectedLabel() {
            return this.selected?.label ?? this.placeholder;
        },

        get filtered() {
            const query = this.query.trim().toLowerCase();
            if (query === '') {
                return this.options;
            }

            return this.options.filter((option) => {
                const label = String(option.label ?? '').toLowerCase();
                const value = String(option.value ?? '').toLowerCase();

                return label.includes(query) || value.includes(query);
            });
        },

        toggle() {
            if (this.disabled) {
                return;
            }

            this.open ? this.close() : this.show();
        },

        show() {
            if (this.disabled) {
                return;
            }

            this.open = true;
            this.query = '';
            this.highlight = Math.max(0, this.filtered.findIndex((option) => sameValue(option.value, this.value)));
            this.$nextTick(() => this.$refs.search?.focus());
        },

        close() {
            this.open = false;
            this.query = '';
        },

        select(option) {
            this.value = option.value === undefined ? null : option.value;
            this.close();
        },

        move(delta) {
            const total = this.filtered.length;
            if (total === 0) {
                return;
            }

            this.highlight = (this.highlight + delta + total) % total;
            this.$nextTick(() => {
                const active = this.$refs.list?.querySelector('[data-active="true"]');
                active?.scrollIntoView({ block: 'nearest' });
            });
        },

        chooseHighlighted() {
            const option = this.filtered[this.highlight];
            if (option) {
                this.select(option);
            }
        },
    }));

    window.uiToast = (message, options) => {
        Alpine.store('toasts').show(message, options);
    };

    window.uiCopy = async (text, message) => {
        try {
            await navigator.clipboard.writeText(text);
            window.uiToast(message);
        } catch {
            window.uiToast(message);
        }
    };

    window.copyToClipboard = window.copyToClipboard ?? (async (text) => {
        await navigator.clipboard.writeText(text);
    });

    Alpine.data('uiFloatHost', (storeName) => ({
        init() {
            this.$watch(`$store.${storeName}.open`, (open) => {
                if (!open) {
                    return;
                }

                this.$nextTick(() => {
                    const panel = this.$refs.panel;
                    const store = this.$store[storeName];
                    if (!panel || !store.open) {
                        return;
                    }

                    const pos = clampPanel(store.x, store.y, panel.offsetWidth, panel.offsetHeight);
                    store.x = pos.x;
                    store.y = pos.y;
                });
            });
        },
    }));

    window.uiHover = Alpine.store('hoverCard');
    window.uiContext = Alpine.store('contextMenu');
}

export function bindUiShortcuts() {
    document.addEventListener('click', (event) => {
        const menu = window.Alpine?.store('contextMenu');
        if (!menu?.open || menu.suppressClick) {
            return;
        }

        if (event.target?.closest?.('[data-ui="context-menu"]')) {
            return;
        }

        menu.close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            window.Alpine?.store('contextMenu')?.close();
            window.Alpine?.store('hoverCard')?.close();
        }

        const isCommandK = (event.metaKey || event.ctrlKey) && (event.key === 'k' || event.key === 'K');
        if (isCommandK) {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('ui-command-toggle'));
            return;
        }

        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (isEditableTarget(event.target)) {
            return;
        }

        if (event.key === '/') {
            const search = document.querySelector('[data-shortcut="task-search"]');
            if (!search) {
                return;
            }

            event.preventDefault();
            search.focus();
            search.select?.();
            return;
        }

        const createKeys = new Set(['c', 'C', 'с', 'С']);
        if (createKeys.has(event.key)) {
            const create = document.querySelector('[data-shortcut="create-task"]');
            if (!create) {
                return;
            }

            event.preventDefault();
            create.click();
        }
    });
}

export { clampPanel };
