// Chart.js is loaded on demand (code-split): only pages with a [data-chart] download it.
let chartLoading = null;

function loadChart() {
    chartLoading ??= import('chart.js/auto').then(({ default: Chart }) => {
        Chart.defaults.font.family = '"Inter Variable", Inter, ui-sans-serif, system-ui, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.boxHeight = 10;

        return Chart;
    });

    return chartLoading;
}

import attachmentLightbox from './attachment-lightbox';
import richTextEditor from './rich-text-editor';
import kanbanBoard, { bindKanbanBoard, bindTaskUiState } from './kanban-board';
import subtaskSort from './subtask-sort';
import registerUiKit, { bindUiShortcuts } from './ui-kit';

// Theme: light | dark | system. The class on <html> is set early by partials/theme-init.
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

function themeMode() {
    try {
        return localStorage.getItem('theme') || 'system';
    } catch {
        return 'system';
    }
}

function applyTheme() {
    const mode = themeMode();
    const dark = mode === 'dark' || (mode === 'system' && darkQuery.matches);
    document.documentElement.classList.toggle('dark', dark);
    document.querySelectorAll('[data-chart]').forEach((element) => {
        const config = parseChartConfig(element);
        if (config && chartInstances.has(element.id)) {
            destroyChart(element.id);
            createOrUpdateChart(element.id, config);
        }
    });
}

window.uiTheme = {
    get: themeMode,
    set(mode) {
        try {
            if (mode === 'system') {
                localStorage.removeItem('theme');
            } else {
                localStorage.setItem('theme', mode);
            }
        } catch {
            // Private mode: the choice lasts for this page only.
        }
        applyTheme();
    },
};

darkQuery.addEventListener('change', () => {
    if (themeMode() === 'system') {
        applyTheme();
    }
});

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

// Neutral chart colours follow the current theme tokens; navy series turn lighter in dark mode.
function themedChartConfig(Chart, config) {
    const css = getComputedStyle(document.documentElement);
    const token = (name) => `rgb(${css.getPropertyValue(name).trim()})`;
    Chart.defaults.color = token('--c-gray-600');
    Chart.defaults.borderColor = token('--c-gray-100');
    if (!document.documentElement.classList.contains('dark')) {
        return config;
    }
    const swap = (value) => (value === '#1F3A8A' ? '#7895D1' : value);
    const themed = structuredClone(config);
    (themed.data?.datasets ?? []).forEach((dataset) => {
        dataset.backgroundColor = Array.isArray(dataset.backgroundColor)
            ? dataset.backgroundColor.map(swap)
            : swap(dataset.backgroundColor);
        dataset.borderColor = token('--c-surface');
    });

    return themed;
}

async function createOrUpdateChart(id, config) {
    if (!config) {
        return;
    }

    const element = document.getElementById(id);
    if (!element) {
        return;
    }

    // All-zero data: show the empty message instead of an empty axis.
    const isEmpty = (config.data?.datasets ?? [])
        .flatMap((dataset) => dataset.data ?? [])
        .every((value) => !Number(value));
    element.hidden = isEmpty;
    const emptyNote = document.querySelector(`[data-chart-empty="${id}"]`);
    if (emptyNote) {
        emptyNote.hidden = !isEmpty;
    }

    const existing = chartInstances.get(id);
    if (existing) {
        config = themedChartConfig(await loadChart(), config);
        existing.config.type = config.type;
        existing.data = config.data;
        existing.options = config.options;
        existing.update();
        return;
    }

    const Chart = await loadChart();
    config = themedChartConfig(Chart, config);
    if (!element.isConnected || chartInstances.has(id)) {
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
