import Chart from 'chart.js/auto';
import richTextEditor from './rich-text-editor';

// Livewire 3 ships Alpine, so register before it starts rather than importing it.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('richTextEditor', richTextEditor);
    window.Alpine.data('subtaskSort', () => ({
        draggingId: null,
        moveListener: null,
        upListener: null,
        onDown(id, event) {
            if (event.button != null && event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.unbindWindow();
            this.draggingId = Number(id);
            try {
                event.currentTarget.setPointerCapture(event.pointerId);
            } catch {
                // Capture is optional; window listeners still track the pointer.
            }

            const row = this.row(this.draggingId);
            if (row) {
                row.classList.add('opacity-60');
            }

            this.moveListener = (e) => this.onMove(e);
            this.upListener = () => this.persist();
            document.addEventListener('pointermove', this.moveListener, true);
            document.addEventListener('pointerup', this.upListener, true);
            document.addEventListener('pointercancel', this.upListener, true);
        },
        onMove(event) {
            if (this.draggingId === null) {
                return;
            }

            const dragged = this.row(this.draggingId);
            if (! dragged) {
                return;
            }

            const others = [...this.$el.querySelectorAll('[data-subtask-id]')]
                .filter((el) => el !== dragged);

            for (const item of others) {
                const rect = item.getBoundingClientRect();
                if (event.clientY < rect.top + rect.height / 2) {
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
        persist() {
            if (this.draggingId === null) {
                return;
            }

            const row = this.row(this.draggingId);
            if (row) {
                row.classList.remove('opacity-60');
            }

            this.unbindWindow();
            this.draggingId = null;
            const ids = [...this.$el.querySelectorAll('[data-subtask-id]')]
                .map((el) => Number(el.dataset.subtaskId));
            this.$wire.reorderSubtasks(ids);
        },
        unbindWindow() {
            if (this.moveListener) {
                document.removeEventListener('pointermove', this.moveListener, true);
            }
            if (this.upListener) {
                document.removeEventListener('pointerup', this.upListener, true);
                document.removeEventListener('pointercancel', this.upListener, true);
            }
            this.moveListener = null;
            this.upListener = null;
        },
        row(id) {
            return this.$el.querySelector(`[data-subtask-id="${id}"]`);
        },
    }));
});

const chartInstances = new Map();

function parseChartConfig(element) {
    const raw = element.getAttribute('data-chart');
    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function destroyChart(id) {
    const existing = chartInstances.get(id);
    if (existing) {
        existing.destroy();
        chartInstances.delete(id);
    }
}

function createOrUpdateChart(id, config) {
    if (!config) {
        return;
    }

    const element = document.getElementById(id);
    if (!element) {
        return;
    }

    const existing = chartInstances.get(id);
    if (existing) {
        existing.config.type = config.type;
        existing.data = config.data;
        existing.options = config.options;
        existing.update();
        return;
    }

    const chart = new Chart(element, config);
    chartInstances.set(id, chart);
}

function initChartsFromDom() {
    document.querySelectorAll('[data-chart]').forEach((element) => {
        const config = parseChartConfig(element);
        if (!config || !element.id) {
            return;
        }

        destroyChart(element.id);
        createOrUpdateChart(element.id, config);
    });
}

function handleDashboardChartsUpdated(payload) {
    const data = payload?.detail ?? payload ?? {};
    createOrUpdateChart('dashboard-department-chart', data.departmentChart);
    createOrUpdateChart('dashboard-category-chart', data.categoryChart);
}

document.addEventListener('DOMContentLoaded', initChartsFromDom);
document.addEventListener('livewire:navigated', initChartsFromDom);

document.addEventListener('livewire:init', () => {
    Livewire.on('dashboard-charts-updated', handleDashboardChartsUpdated);
});

document.addEventListener('dashboard-charts-updated', (event) => {
    handleDashboardChartsUpdated(event);
});
