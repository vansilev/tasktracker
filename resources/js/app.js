import Chart from 'chart.js/auto';
import richTextEditor from './rich-text-editor';

// Livewire 3 ships Alpine, so register before it starts rather than importing it.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('richTextEditor', richTextEditor);
    window.Alpine.data('subtaskSort', () => ({
        draggingId: null,
        overId: null,
        start(id, event) {
            this.draggingId = id;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(id));
            const row = event.currentTarget.closest('li');
            if (row) {
                event.dataTransfer.setDragImage(row, 16, 16);
            }
        },
        over(id) {
            if (this.draggingId !== null && this.draggingId !== id) {
                this.overId = id;
            }
        },
        drop(id) {
            if (this.draggingId === null || this.draggingId === id) {
                this.end();
                return;
            }

            const ids = [...this.$el.querySelectorAll('[data-subtask-id]')]
                .map((el) => Number(el.dataset.subtaskId));
            const from = ids.indexOf(this.draggingId);
            const to = ids.indexOf(id);

            if (from === -1 || to === -1) {
                this.end();
                return;
            }

            ids.splice(from, 1);
            ids.splice(to, 0, this.draggingId);
            this.end();
            this.$wire.reorderSubtasks(ids);
        },
        end() {
            this.draggingId = null;
            this.overId = null;
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
