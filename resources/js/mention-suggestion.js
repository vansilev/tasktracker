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
        minQueryLength: 1,
        items: async ({ query, signal }) => {
            const term = (query ?? '').trim();
            if (term === '') {
                return [];
            }

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
            if (!token) {
                return;
            }

            const nodeAfter = editor.view.state.selection.$to.nodeAfter;
            if (nodeAfter?.text?.startsWith(' ')) {
                range.to += 1;
            }

            editor
                .chain()
                .focus()
                .insertContentAt(range, `@${token} `)
                .run();

            editor.view.dom.ownerDocument.defaultView?.getSelection()?.collapseToEnd();
        },
        render: () => {
            let popup = null;
            let list = null;
            let selectedIndex = 0;
            let latestProps = null;

            const destroyPopup = () => {
                if (popup) {
                    popup.remove();
                    popup = null;
                    list = null;
                    onPopupEl(null);
                }
            };

            const selectItem = (index) => {
                if (!latestProps?.items?.length) {
                    return;
                }

                const item = latestProps.items[index];
                if (item) {
                    latestProps.command(item);
                }
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

            const renderItems = () => {
                if (!list || !latestProps) {
                    return;
                }

                list.innerHTML = '';
                const items = latestProps.items ?? [];

                if (items.length === 0) {
                    const empty = document.createElement('li');
                    empty.className = 'mention-suggestion-empty';
                    empty.setAttribute('role', 'presentation');
                    empty.textContent = labels.empty || '—';
                    list.appendChild(empty);
                    selectedIndex = 0;
                    updatePosition();

                    return;
                }

                selectedIndex = Math.min(selectedIndex, items.length - 1);

                items.forEach((item, index) => {
                    const li = document.createElement('li');
                    li.id = `mention-option-${item.id}`;
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', index === selectedIndex ? 'true' : 'false');
                    li.className = 'mention-suggestion-item'+(index === selectedIndex ? ' is-selected' : '');

                    const name = document.createElement('span');
                    name.className = 'mention-suggestion-name';
                    name.textContent = item.name;

                    const meta = document.createElement('span');
                    meta.className = 'mention-suggestion-meta';
                    meta.textContent = item.email;

                    li.append(name, meta);
                    li.addEventListener('mouseenter', () => {
                        selectedIndex = index;
                        renderItems();
                    });
                    li.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        selectItem(index);
                    });

                    list.appendChild(li);
                });

                list.setAttribute('aria-activedescendant', `mention-option-${items[selectedIndex].id}`);
                updatePosition();
            };

            return {
                onStart: (props) => {
                    latestProps = props;
                    selectedIndex = 0;

                    popup = document.createElement('div');
                    popup.className = 'mention-suggestion-popup';
                    popup.setAttribute('role', 'listbox');
                    popup.setAttribute('aria-label', labels.list || 'Mentions');

                    list = document.createElement('ul');
                    list.className = 'mention-suggestion-list';
                    list.setAttribute('role', 'presentation');
                    popup.appendChild(list);
                    document.body.appendChild(popup);
                    onPopupEl(popup);

                    renderItems();
                },

                onUpdate: (props) => {
                    latestProps = props;
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
                        destroyPopup();

                        return true;
                    }

                    const count = latestProps.items?.length ?? 0;
                    if (count === 0) {
                        return false;
                    }

                    if (event.key === 'ArrowUp') {
                        selectedIndex = (selectedIndex + count - 1) % count;
                        renderItems();

                        return true;
                    }

                    if (event.key === 'ArrowDown') {
                        selectedIndex = (selectedIndex + 1) % count;
                        renderItems();

                        return true;
                    }

                    if (event.key === 'Enter') {
                        selectItem(selectedIndex);

                        return true;
                    }

                    return false;
                },

                onExit: () => {
                    destroyPopup();
                    latestProps = null;
                },
            };
        },
    };
}
