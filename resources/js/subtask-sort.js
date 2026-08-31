/**
 * Drag-to-reorder subtasks on the parent card.
 *
 * Drag state lives in this closure, not on the Alpine proxy: Alpine v3 wraps
 * data in a reactive Proxy, and holding a DOM node (the ghost) there is unsafe.
 *
 * Subtask titles are `a[wire:navigate]`. Alpine Navigate opens them from the
 * link's mouseup handler (then requestAnimationFrame), not from click. A
 * click-only guard after drop is too late. After a drag we cancel that
 * pending alpine:navigate and the leftover click.
 */
let suppressSubtaskOpen = false;

function blockClickIfDragging(event) {
    if (! suppressSubtaskOpen) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', blockClickIfDragging, true);
    document.addEventListener('alpine:navigate', (event) => {
        if (suppressSubtaskOpen) {
            event.preventDefault();
        }
    }, true);
}

export default function subtaskSort() {
    let pendingId = null;
    let draggingId = null;
    let ghost = null;
    let moveListener = null;
    let upListener = null;
    let startX = 0;
    let startY = 0;
    let grabOffsetX = 0;
    let grabOffsetY = 0;
    let originalIds = [];
    let didDrag = false;

    function row(root, id) {
        return root.querySelector(`[data-subtask-id="${id}"]`);
    }

    function idsOf(root) {
        return [...root.querySelectorAll('[data-subtask-id]')].map((el) => Number(el.dataset.subtaskId));
    }

    function clearListeners() {
        if (moveListener) {
            document.removeEventListener('pointermove', moveListener, true);
        }
        if (upListener) {
            document.removeEventListener('pointerup', upListener, true);
            document.removeEventListener('pointercancel', upListener, true);
        }
        moveListener = null;
        upListener = null;
    }

    function removeGhost() {
        ghost?.remove();
        ghost = null;
        document.body.style.removeProperty('cursor');
        document.body.style.removeProperty('user-select');
    }

    return {
        init() {
            this.$el.addEventListener('pointerdown', (event) => {
                const item = event.target.closest('[data-subtask-id]');
                if (! item || ! this.$el.contains(item)) {
                    return;
                }

                this.onDown(Number(item.dataset.subtaskId), event);
            });
        },

        onDown(id, event) {
            if (event.button != null && event.button !== 0) {
                return;
            }

            pendingId = Number(id);
            draggingId = null;
            didDrag = false;
            suppressSubtaskOpen = false;
            startX = event.clientX;
            startY = event.clientY;

            moveListener = (e) => this.onMove(e);
            upListener = () => this.onUp();
            document.addEventListener('pointermove', moveListener, true);
            document.addEventListener('pointerup', upListener, true);
            document.addEventListener('pointercancel', upListener, true);
        },

        onMove(event) {
            if (pendingId == null && draggingId == null) {
                return;
            }

            if (draggingId == null) {
                if (Math.abs(event.clientY - startY) + Math.abs(event.clientX - startX) < 6) {
                    return;
                }
                this.begin(event);
            }

            if (draggingId == null) {
                return;
            }

            event.preventDefault();
            if (ghost) {
                ghost.style.top = `${event.clientY - grabOffsetY}px`;
                ghost.style.left = `${event.clientX - grabOffsetX}px`;
            }
            this.reorder(event.clientY);
        },

        begin(event) {
            draggingId = pendingId;
            pendingId = null;
            didDrag = true;
            suppressSubtaskOpen = true;

            const dragged = row(this.$el, draggingId);
            if (! dragged) {
                draggingId = null;

                return;
            }

            originalIds = idsOf(this.$el);
            const rect = dragged.getBoundingClientRect();
            grabOffsetX = event.clientX - rect.left;
            grabOffsetY = event.clientY - rect.top;

            ghost = dragged.cloneNode(true);
            ghost.removeAttribute('wire:key');
            Object.assign(ghost.style, {
                position: 'fixed',
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                width: `${rect.width}px`,
                zIndex: '9999',
                pointerEvents: 'none',
                margin: '0',
                opacity: '0.95',
                boxShadow: '0 10px 25px rgba(15, 23, 42, 0.18)',
                background: '#fff',
                borderRadius: '8px',
                listStyle: 'none',
            });
            document.body.appendChild(ghost);

            dragged.classList.add('opacity-40');
            document.body.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
        },

        reorder(clientY) {
            const dragged = row(this.$el, draggingId);
            if (! dragged) {
                return;
            }

            const others = [...this.$el.querySelectorAll('[data-subtask-id]')]
                .filter((el) => el !== dragged);

            for (const item of others) {
                const rect = item.getBoundingClientRect();
                if (clientY < rect.top + rect.height / 2) {
                    if (dragged.nextElementSibling !== item) {
                        this.$el.insertBefore(dragged, item);
                    }

                    return;
                }
            }

            if (dragged.nextElementSibling !== null) {
                this.$el.appendChild(dragged);
            }
        },

        onUp() {
            const dragged = draggingId != null ? row(this.$el, draggingId) : null;
            if (dragged) {
                dragged.classList.remove('opacity-40');
            }

            const nextIds = draggingId != null ? idsOf(this.$el) : [];
            const changed = draggingId != null && nextIds.join() !== originalIds.join();

            removeGhost();
            clearListeners();
            pendingId = null;
            draggingId = null;

            if (didDrag) {
                // pointerup runs before mouseup; Alpine Navigate opens on the
                // next animation frame after mouseup. Hold the guard past that.
                const release = () => {
                    suppressSubtaskOpen = false;
                };
                requestAnimationFrame(() => {
                    requestAnimationFrame(release);
                });
                setTimeout(release, 100);
            }

            if (changed) {
                this.$wire.reorderSubtasks(nextIds);
            }
        },
    };
}
