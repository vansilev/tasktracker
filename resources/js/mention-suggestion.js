/**
 * TipTap Suggestion renderer for @mentions.
 *
 * Inserts plain-text `@Token` (no span/data-* attrs) so HTMLPurifier's
 * task_content profile keeps the mention parseable after sanitize.
 */

const DEBOUNCE_MS = 200;

/**
 * @param {{
 *   search: (term: string, signal?: AbortSignal) => Promise<Array<{id:number,name:string,email:string,token:string}>>,
 *   labels?: { list?: string, empty?: string },
 *   onPopupEl?: (el: HTMLElement|null) => void,
 * }} options
 */
export function createMentionSuggestion({ search, labels = {}, onPopupEl = () => {} }) {
    return {
        char: '@',
        allowSpaces: false,
        // null = allow @ after punctuation too (server parser already accepts those).
        // Default TipTap [' '] would hide the dropdown after ">" "(" "!" etc.
        allowedPrefixes: null,
        debounce: DEBOUNCE_MS,
        minQueryLength: 0,
        dismissOnOutsideClick: false,
        items: async ({ query, signal }) => {
            const term = (query ?? '').trim();

            try {
                const results = await search(term, signal);
                if (signal?.aborted) {
                    return [];
                }

                return Array.isArray(results) ? results : [];
            } catch {
                return [];
            }
        },
        command: ({ editor, range, props }) => {
            const token = props?.token || props?.label || props?.id;
            if (!token || !editor || editor.isDestroyed) {
                return;
            }

            // insertContentAt keeps the @query range even if the editor blurred
            // while the user was clicking the popup.
            editor.chain().focus().insertContentAt(range, `@${token} `).run();
        },
        render: () => {
            let popup = null;
            let list = null;
            let selectedIndex = 0;
            let latestProps = null;
            let applyItem = null;
            let lastItemsKey = '';

            const destroyPopup = () => {
                if (popup) {
                    popup.remove();
                    popup = null;
                    list = null;
                    onPopupEl(null);
                }
            };

            const highlightSelected = () => {
                if (!list) {
                    return;
                }

                const items = latestProps?.items ?? [];
                [...list.querySelectorAll('.mention-suggestion-item')].forEach((li) => {
                    const index = Number(li.dataset.index);
                    const on = index === selectedIndex;
                    li.classList.toggle('is-selected', on);
                    li.setAttribute('aria-selected', on ? 'true' : 'false');
                    li.style.background = on ? '#eef2ff' : 'transparent';
                });

                const current = items[selectedIndex];
                if (current) {
                    list.setAttribute('aria-activedescendant', `mention-option-${current.id}`);
                }
            };

            const itemsKey = (items) => (items ?? []).map((item) => String(item.id)).join(',');

            const selectItem = (index) => {
                const items = latestProps?.items ?? [];
                const item = items[index];
                const command = applyItem || latestProps?.command;
                if (!item || typeof command !== 'function') {
                    return;
                }

                command(item);
            };

            const updatePosition = () => {
                if (!popup || !latestProps?.clientRect) {
                    return;
                }

                const rect = latestProps.clientRect();
                if (!rect) {
                    return;
                }

                const margin = 8;
                const width = popup.offsetWidth || 240;
                const height = popup.offsetHeight || 0;
                let left = rect.left;
                let top = rect.bottom + 4;

                if (left + width > window.innerWidth - margin) {
                    left = Math.max(margin, window.innerWidth - width - margin);
                }

                if (top + height > window.innerHeight - margin && rect.top > height + 4) {
                    top = rect.top - height - 4;
                }

                popup.style.left = `${Math.max(margin, left)}px`;
                popup.style.top = `${Math.max(margin, top)}px`;
            };

            const renderItems = ({ force = false } = {}) => {
                if (!list || !latestProps) {
                    return;
                }

                const items = latestProps.items ?? [];
                const key = itemsKey(items);

                // Rebuilding <li> under the cursor cancels the click. Skip if
                // the people list did not change (caret move, debounce tick).
                if (!force && key === lastItemsKey && list.childElementCount > 0) {
                    highlightSelected();
                    updatePosition();

                    return;
                }

                lastItemsKey = key;
                list.innerHTML = '';

                if (items.length === 0) {
                    const empty = document.createElement('li');
                    empty.className = 'mention-suggestion-empty';
                    empty.setAttribute('role', 'presentation');
                    const query = (latestProps.query ?? '').trim();
                    empty.textContent = query === ''
                        ? (labels.loading || '…')
                        : (labels.empty || '—');
                    list.appendChild(empty);
                    selectedIndex = 0;
                    updatePosition();

                    return;
                }

                selectedIndex = Math.min(selectedIndex, items.length - 1);

                items.forEach((item, index) => {
                    const li = document.createElement('li');
                    li.id = `mention-option-${item.id}`;
                    li.dataset.index = String(index);
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', index === selectedIndex ? 'true' : 'false');
                    li.className = 'mention-suggestion-item'+(index === selectedIndex ? ' is-selected' : '');
                    Object.assign(li.style, {
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.125rem',
                        borderRadius: '0.375rem',
                        padding: '0.5rem 0.625rem',
                        cursor: 'pointer',
                        background: index === selectedIndex ? '#eef2ff' : 'transparent',
                    });

                    const name = document.createElement('span');
                    name.className = 'mention-suggestion-name';
                    name.textContent = item.name;
                    name.style.fontSize = '0.875rem';
                    name.style.fontWeight = '500';
                    name.style.color = '#111827';

                    const meta = document.createElement('span');
                    meta.className = 'mention-suggestion-meta';
                    meta.textContent = item.email;
                    meta.style.fontSize = '0.75rem';
                    meta.style.color = '#6b7280';

                    li.append(name, meta);
                    list.appendChild(li);
                });

                highlightSelected();
                updatePosition();
            };

            return {
                onStart: (props) => {
                    latestProps = props;
                    applyItem = props.command;
                    lastItemsKey = '';
                    selectedIndex = 0;

                    popup = document.createElement('div');
                    popup.className = 'mention-suggestion-popup';
                    popup.setAttribute('role', 'listbox');
                    popup.setAttribute('aria-label', labels.list || 'Mentions');
                    Object.assign(popup.style, {
                        position: 'fixed',
                        zIndex: '80',
                        minWidth: '16rem',
                        maxWidth: '20rem',
                        maxHeight: '14rem',
                        overflowY: 'auto',
                        background: '#fff',
                        color: '#111827',
                        border: '1px solid #e5e7eb',
                        borderRadius: '0.5rem',
                        boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
                    });

                    list = document.createElement('ul');
                    list.className = 'mention-suggestion-list';
                    list.setAttribute('role', 'presentation');
                    Object.assign(list.style, {
                        margin: '0',
                        padding: '0.25rem',
                        listStyle: 'none',
                    });
                    popup.appendChild(list);

                    // pointerdown, not mousedown: the editor blurs on pointerdown
                    // and TipTap would destroy this popup before mousedown fires.
                    const pickFromPointer = (event) => {
                        const li = event.target.closest?.('.mention-suggestion-item');
                        if (!li || !popup.contains(li)) {
                            return;
                        }

                        event.preventDefault();
                        event.stopPropagation();
                        selectItem(Number(li.dataset.index));
                    };

                    popup.addEventListener('pointerdown', pickFromPointer, true);
                    popup.addEventListener('mousedown', pickFromPointer, true);

                    list.addEventListener('mouseover', (event) => {
                        const li = event.target.closest?.('.mention-suggestion-item');
                        if (!li || !list.contains(li)) {
                            return;
                        }

                        const index = Number(li.dataset.index);
                        if (Number.isNaN(index) || index === selectedIndex) {
                            return;
                        }

                        selectedIndex = index;
                        highlightSelected();
                    });

                    document.body.appendChild(popup);
                    onPopupEl(popup);

                    renderItems({ force: true });
                },

                onUpdate: (props) => {
                    latestProps = props;
                    applyItem = props.command;
                    if (!popup) {
                        return;
                    }
                    renderItems();
                },

                onKeyDown: ({ event }) => {
                    if (!latestProps) {
                        return false;
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        destroyPopup();

                        return true;
                    }

                    const count = latestProps.items?.length ?? 0;
                    if (count === 0) {
                        return false;
                    }

                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        selectedIndex = (selectedIndex + count - 1) % count;
                        highlightSelected();

                        return true;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        selectedIndex = (selectedIndex + 1) % count;
                        highlightSelected();

                        return true;
                    }

                    if (event.key === 'Enter' || event.key === 'Tab') {
                        event.preventDefault();
                        event.stopPropagation();
                        selectItem(selectedIndex);

                        return true;
                    }

                    return false;
                },

                onExit: () => {
                    destroyPopup();
                    // Keep command for one frame: pointerdown can race onExit.
                    requestAnimationFrame(() => {
                        latestProps = null;
                        applyItem = null;
                    });
                },
            };
        },
    };
}
