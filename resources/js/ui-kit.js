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

export default function registerUiKit(Alpine) {
    Alpine.store('toasts', {
        items: [],
        show(message, timeout = 3200) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message });
            window.setTimeout(() => this.dismiss(id), timeout);
        },
        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
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

    window.uiToast = (message) => {
        Alpine.store('toasts').show(message);
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
}

export function bindUiShortcuts() {
    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (isEditableTarget(event.target)) {
            return;
        }

        if (event.key === '/' ) {
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
