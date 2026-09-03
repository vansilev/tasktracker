import Chart from 'chart.js/auto';
import attachmentLightbox from './attachment-lightbox';
import richTextEditor from './rich-text-editor';
import kanbanBoard, { bindKanbanBoard, bindTaskUiState } from './kanban-board';
import subtaskSort from './subtask-sort';
import registerUiKit, { bindUiShortcuts } from './ui-kit';

// Livewire 3 ships Alpine, so register before it starts rather than importing it.
document.addEventListener('alpine:init', () => {
    registerUiKit(window.Alpine);
    window.Alpine.data('richTextEditor', richTextEditor);
    window.Alpine.data('subtaskSort', subtaskSort);
    window.Alpine.data('kanbanBoard', kanbanBoard);
    window.Alpine.data('attachmentLightbox', attachmentLightbox);
});

bindUiShortcuts();
bindKanbanBoard();
bindTaskUiState();

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
