/**
 * Document-level kanban drag. Lives outside Alpine/Livewire so a morph
 * does not drop the pointer listeners mid-gesture.
 */
const STATE_KEY = 'tasktracker.tasks.ui';

let pending = null;
let dragging = null;
let ghost = null;
let overColumn = null;
let suppressClick = false;

function columnAt(x, y) {
    ghost?.style.setProperty('visibility', 'hidden');
    const stack = document.elementsFromPoint(x, y);
    ghost?.style.removeProperty('visibility');

    for (const el of stack) {
        const column = el.closest?.('[data-kanban-column]');
        if (column) {
            return column;
        }
    }

    return null;
}

function highlight(column) {
    if (overColumn && overColumn !== column) {
        overColumn.classList.remove('ring-2', 'ring-indigo-400');
    }
    overColumn = column || null;
    overColumn?.classList.add('ring-2', 'ring-indigo-400');
}

function clearGhost() {
    if (ghost) {
        ghost.remove();
        ghost = null;
    }
    if (dragging?.el) {
        dragging.el.classList.remove('opacity-40');
    }
    highlight(null);
    pending = null;
    dragging = null;
}

function findWire(el) {
    const root = el.closest('[wire\\:id]');
    if (! root) {
        return null;
    }

    const id = root.getAttribute('wire:id');
    if (id && window.Livewire) {
        return window.Livewire.find(id);
    }

    return root.__livewire?.$wire ?? root._x_dataStack?.[0]?.$wire ?? null;
}

function startDrag(event) {
    const source = pending?.el;
    if (! source || ! pending) {
        return;
    }

    dragging = pending;
    pending = null;
    suppressClick = true;

    const rect = source.getBoundingClientRect();
    ghost = source.cloneNode(true);
    ghost.removeAttribute('data-kanban-card');
    ghost.setAttribute('data-ui', 'kanban-ghost');
    Object.assign(ghost.style, {
        position: 'fixed',
        zIndex: '90',
        width: `${rect.width}px`,
        left: `${event.clientX - dragging.offsetX}px`,
        top: `${event.clientY - dragging.offsetY}px`,
        pointerEvents: 'none',
        boxShadow: '0 12px 24px rgba(15,23,42,0.18)',
        opacity: '0.95',
    });
    document.body.appendChild(ghost);
    source.classList.add('opacity-40');
}

function onPointerDown(event) {
    if (event.button !== 0) {
        return;
    }
    if (! document.querySelector('[data-ui=kanban]')) {
        return;
    }
    if (event.target.closest('a, input, textarea, select')) {
        return;
    }

    const card = event.target.closest('[data-kanban-card]');
    if (! card) {
        return;
    }

    const rect = card.getBoundingClientRect();
    pending = {
        id: Number(card.dataset.kanbanCard),
        el: card,
        x: event.clientX,
        y: event.clientY,
        offsetX: event.clientX - rect.left,
        offsetY: event.clientY - rect.top,
    };
}

function onPointerMove(event) {
    if (! pending && ! dragging) {
        return;
    }

    if (! dragging && pending) {
        if (Math.hypot(event.clientX - pending.x, event.clientY - pending.y) < 5) {
            return;
        }
        event.preventDefault();
        startDrag(event);
    }

    if (! dragging || ! ghost) {
        return;
    }

    event.preventDefault();
    ghost.style.left = `${event.clientX - dragging.offsetX}px`;
    ghost.style.top = `${event.clientY - dragging.offsetY}px`;
    highlight(columnAt(event.clientX, event.clientY));
}

function onPointerUp(event) {
    const move = dragging;
    const column = move ? columnAt(event.clientX, event.clientY) : null;
    clearGhost();

    if (move) {
        window.setTimeout(() => {
            suppressClick = false;
        }, 0);
    }

    if (! move || ! column) {
        return;
    }

    const status = column.dataset.kanbanColumn;
    if (! status) {
        return;
    }

    const wire = findWire(column);
    if (wire && typeof wire.kanbanMove === 'function') {
        wire.kanbanMove(move.id, status);
        return;
    }

    if (window.Livewire) {
        const root = document.querySelector('[data-ui=kanban]')?.closest('[wire\\:id]');
        const id = root?.getAttribute('wire:id');
        if (id) {
            window.Livewire.find(id)?.call('kanbanMove', move.id, status);
        }
    }
}

function onClickCapture(event) {
    if (! suppressClick) {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
}

export function bindKanbanBoard() {
    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('pointermove', onPointerMove, { capture: true, passive: false });
    document.addEventListener('pointerup', onPointerUp, true);
    document.addEventListener('pointercancel', onPointerUp, true);
    document.addEventListener('click', onClickCapture, true);
    document.addEventListener('dragstart', (event) => {
        if (event.target.closest?.('[data-kanban-card]')) {
            event.preventDefault();
        }
    }, true);
}

export function bindTaskUiState() {
    window.taskUiState = {
        save(state) {
            try {
                window.localStorage.setItem(STATE_KEY, JSON.stringify(state));
            } catch {
                // Ignore quota / private mode.
            }
        },
        load(userId) {
            try {
                const saved = JSON.parse(window.localStorage.getItem(STATE_KEY) || 'null');
                if (! saved || typeof saved !== 'object') {
                    return null;
                }
                if (userId && saved.userId && Number(saved.userId) !== Number(userId)) {
                    return null;
                }
                return saved;
            } catch {
                return null;
            }
        },
    };
}

export default function kanbanBoard() {
    return {
        init() {},
    };
}
