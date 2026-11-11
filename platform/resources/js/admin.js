/*
 * Woodex OS admin bundle.
 *
 * - Alpine is provided by Livewire 4. Do NOT import Alpine here (docs/07 D-11).
 * - Preline: import only the plugins the admin uses (each registers window.HS*), and re-initialise
 *   them after Livewire navigation and DOM morphs (D-12). Add a plugin here AND to PRELINE_PLUGINS
 *   when a screen starts using it (e.g. 'preline/plugins/overlay' → 'HSOverlay').
 * - Heavy libraries (SortableJS, ApexCharts) load only on screens that need them.
 */
import 'preline/plugins/dropdown';
import { createThemeStore, readStorage, writeStorage } from './admin/theme';
import { registerQuoteWorkbench } from './admin/quote-workbench';
import { registerDocumentPreview } from './admin/document-preview';

const PRELINE_PLUGINS = ['HSDropdown'];

const initPreline = () => {
    PRELINE_PLUGINS.forEach((name) => window[name]?.autoInit?.());
};

/* ---------- Alpine stores: sidebar + colour theme (TailAdmin behaviour) ---------- */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;
    registerQuoteWorkbench(Alpine);
    registerDocumentPreview(Alpine);

    Alpine.store('sidebar', {
        expanded: true,
        mobileOpen: false,
        // P10.1: collapsed groups persist per browser ('wx.groups' → { slug: false }). Default: open.
        groups: {},
        init() {
            // The desktop preference; below xl the sidebar is an off-canvas drawer (mobileOpen) at full width.
            this.expanded = readStorage('wx.sidebar') !== 'collapsed';
            try {
                this.groups = JSON.parse(readStorage('wx.groups') || '{}') || {};
            } catch {
                this.groups = {};
            }
            this.applyWidth();
        },
        groupOpen(key) {
            return this.groups[key] !== false;
        },
        toggleGroup(key) {
            this.groups[key] = !this.groupOpen(key);
            writeStorage('wx.groups', JSON.stringify(this.groups));
        },
        toggle() {
            if (window.innerWidth < 1280) {
                this.mobileOpen = !this.mobileOpen;
                return;
            }
            this.expanded = !this.expanded;
            writeStorage('wx.sidebar', this.expanded ? 'expanded' : 'collapsed');
            this.applyWidth();
        },
        applyWidth() {
            // Drives --wx-sidebar-w (admin.css), which sizes the sidebar and offsets the content column.
            document.documentElement.dataset.sidebar = this.expanded ? 'expanded' : 'collapsed';
        },
        close() {
            this.mobileOpen = false;
        },
    });

    Alpine.store('theme', createThemeStore());

    Alpine.store('toasts', {
        items: [],
        push(message, type = 'success', action = null) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type, action });
            setTimeout(() => this.remove(id), action ? 8000 : 4500);
        },
        remove(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });
});

/* ---------- Toasts dispatched from Livewire: $this->dispatch('toast', message: '…', type: 'success') ---------- */
window.addEventListener('toast', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    window.Alpine?.store('toasts')?.push(detail?.message ?? 'Saved', detail?.type ?? 'success');
});

/* ---------- Live website chat (P10.2): toast + optional sound when a visitor texts the bubble.
   New inbound messages open a closed drawer; draft-aware switches stay explicit. ---------- */
window.addEventListener('chat-alert', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    const name = detail?.name ?? 'Website visitor';
    const preview = detail?.preview ?? 'New message';
    window.Alpine?.store('toasts')?.push(`Website chat — ${name}: ${preview}`, 'chat', {
        label: 'Open chat',
        event: detail?.conversationId ? 'open-website-thread' : 'open-live-chat',
        detail: { id: detail?.conversationId },
    });

    if (detail?.sound) {
        // The studio-wide switch (Settings → Notifications, default off) travels with the event.
        try {
            new Audio('/sounds/chat-alert.wav').play().catch(() => {});
        } catch {
            /* A blocked autoplay is fine: the toast alone still tells the story. */
        }
    }
});

/* ---------- Pipeline board (SortableJS) ---------- */
let sortables = [];

async function initKanban() {
    sortables.forEach((sortable) => sortable.destroy());
    sortables = [];

    const columns = document.querySelectorAll('[data-kanban-column]');
    if (!columns.length) return;

    const { default: Sortable } = await import('sortablejs');

    // The pipeline ships with these defaults; the project board overrides them per column with
    // data-kanban-item / data-kanban-id-attr / data-kanban-method / data-kanban-group.
    columns.forEach((column) => {
        sortables.push(
            Sortable.create(column, {
                group: column.dataset.kanbanGroup ?? 'pipeline',
                animation: 150,
                draggable: column.dataset.kanbanItem ?? '[data-lead-id]',
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                onEnd(event) {
                    const attr = column.dataset.kanbanIdAttr ?? 'leadId';
                    const id = Number(event.item.dataset[attr]);
                    const toStage = event.to.dataset.kanbanColumn;
                    if (!id || !toStage || event.to === event.from) return;

                    const root = event.to.closest('[wire\\:id]');
                    const component = root ? window.Livewire.find(root.getAttribute('wire:id')) : null;
                    component?.call(column.dataset.kanbanMethod ?? 'moveLead', id, toStage);
                },
            }),
        );
    });
}

/* ---------- Quote builder: drag sections and lines (SortableJS), focus after adding ---------- */
let quoteSortables = [];

async function initQuoteBuilder() {
    quoteSortables.forEach((sortable) => sortable.destroy());
    quoteSortables = [];

    const sections = document.querySelector('[data-quote-sections]');
    if (!sections || !sections.querySelector('[data-section-handle], [data-line-handle]')) return;

    const { default: Sortable } = await import('sortablejs');
    const component = () => {
        const root = sections.closest('[wire\\:id]');
        return root ? window.Livewire.find(root.getAttribute('wire:id')) : null;
    };

    quoteSortables.push(
        Sortable.create(sections, {
            animation: 150,
            handle: '[data-section-handle]',
            draggable: '[data-section-id]',
            ghostClass: 'sortable-ghost',
            onEnd(event) {
                if (event.oldIndex === event.newIndex) return;
                component()?.call('moveSection', Number(event.item.dataset.sectionId), event.newIndex);
            },
        }),
    );

    sections.querySelectorAll('[data-quote-lines]').forEach((list) => {
        quoteSortables.push(
            Sortable.create(list, {
                group: 'quote-lines',
                animation: 150,
                handle: '[data-line-handle]',
                draggable: '[data-item-id]',
                ghostClass: 'sortable-ghost',
                onEnd(event) {
                    if (event.from === event.to && event.oldIndex === event.newIndex) return;
                    component()?.call('moveLine', Number(event.item.dataset.itemId), Number(event.to.dataset.sectionId), event.newIndex);
                },
            }),
        );
    });
}

// $this->dispatch('quote-focus', target: 'line-12-description') after adding a line or section.
window.addEventListener('quote-focus', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    setTimeout(() => {
        const element = document.getElementById(detail?.target ?? '');
        element?.focus();
        element?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }, 60);
});

const activeCharts = new Map();
const chartTheme = (element) => {
    const dark = document.documentElement.classList.contains('dark');
    const css = getComputedStyle(document.documentElement);
    const muted = css.getPropertyValue('--wx-muted').trim();
    const config = JSON.parse(element.dataset.chart || '{}');
    return {
        chart: { foreColor: muted, background: 'transparent' },
        colors: config.colors ?? (dark ? ['#82b3f4', '#e7b687', '#6ce9a6'] : ['#344d75', '#a87442', '#027a48']),
        xaxis: { labels: { style: { colors: muted } } },
        yaxis: { labels: { style: { colors: muted } } },
        legend: { labels: { colors: muted } },
        grid: { borderColor: css.getPropertyValue('--wx-border').trim(), strokeDashArray: 4 },
        tooltip: { theme: dark ? 'dark' : 'light' },
        theme: { mode: dark ? 'dark' : 'light' },
    };
};
window.addEventListener('wx-theme-changed', () => {
    for (const [element, chart] of activeCharts) {
        if (element.isConnected) chart.updateOptions(chartTheme(element), false, false);
        else activeCharts.delete(element);
    }
});

/* ---------- Charts (ApexCharts): <div data-chart='{"type":"area","series":[…],"categories":[…]}'> ---------- */
async function initCharts() {
    const elements = [...document.querySelectorAll('[data-chart]:not([data-chart-ready])')];
    if (!elements.length) return;

    // Claim the elements before the first await: DOMContentLoaded and livewire:navigated both call this on the
    // first page load, and claiming after the import rendered every chart twice.
    elements.forEach((element) => (element.dataset.chartReady = '1'));

    const { default: ApexCharts } = await import('apexcharts');
    const dark = document.documentElement.classList.contains('dark');

    elements.forEach((element) => {
        if (!element.isConnected) return;
        const config = JSON.parse(element.dataset.chart || '{}');

        // Small counts (e.g. 0-3 leads a day) get whole-number ticks instead of 0, 0.5, 1 ... rounded to duplicates.
        const values = (config.series ?? [])
            .flatMap((serie) => serie.data ?? [])
            .map((point) => Number(point !== null && typeof point === 'object' ? point.y : point))
            .filter(Number.isFinite);
        const max = Math.max(0, ...values);
        const yaxis = { min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { formatter: (value) => Math.round(value).toLocaleString() } };
        if (max <= 5) Object.assign(yaxis, { max: Math.max(1, max), tickAmount: Math.max(1, max) });

        const chart = new ApexCharts(element, {
            chart: {
                type: config.type ?? 'area',
                height: config.height ?? 260,
                fontFamily: 'Inter, sans-serif',
                toolbar: { show: false },
                zoom: { enabled: false },
                foreColor: dark ? '#98a2b3' : '#667085',
            },
            series: config.series ?? [],
            colors: config.colors ?? ['#344d75', '#a87442', '#12b76a'],
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
            dataLabels: { enabled: false },
            grid: { borderColor: dark ? '#1d2939' : '#e4e7ec', strokeDashArray: 4 },
            xaxis: { categories: config.categories ?? [], axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis,
            legend: { position: 'top', horizontalAlign: 'left' },
            tooltip: { theme: dark ? 'dark' : 'light' },
        });
        chart.render().then(() => {
            if (!element.isConnected) { chart.destroy(); return; }
            activeCharts.set(element, chart);
            chart.updateOptions(chartTheme(element), false, false);
            fitChartToBox(chart, element);
        });
    });
}

// ApexCharts ignores container resizes while its entry animation runs; re-fit whenever the box really changes
// (sidebar toggle, window resize, layout settling) and clean up once Livewire navigates the element away.
function fitChartToBox(chart, element) {
    let width = element.clientWidth;
    let timer;
    const observer = new ResizeObserver(() => {
        if (!element.isConnected) {
            observer.disconnect();
            activeCharts.delete(element);
            chart.destroy();
            return;
        }
        if (!element.clientWidth || Math.abs(element.clientWidth - width) < 2) return;
        width = element.clientWidth;
        clearTimeout(timer);
        timer = setTimeout(() => chart.updateOptions({ chart: { width: '100%' } }, false, false), 120);
    });
    observer.observe(element);
}

const refreshWidgets = () => {
    initPreline();
    initKanban();
    initQuoteBuilder();
    initCharts();
};

document.addEventListener('DOMContentLoaded', () => {
    initKanban();
    initQuoteBuilder();
    initCharts();
});

document.addEventListener('livewire:navigated', refreshWidgets);

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morphed', refreshWidgets);
});

/* ---------- GLB-016: keyboard shortcuts — N new lead, Q new quote, / search, G then D dashboard ---------- */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.data('wxShortcuts', () => ({
        pendingG: false,

        typing(target) {
            if (!target) return false;
            const tag = (target.tagName || '').toUpperCase();
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable === true;
        },

        onKeydown(event) {
            // Never fight the browser or another handler's modifiers.
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            if (this.typing(event.target)) { this.pendingG = false; return; }

            const key = event.key;

            // 'G' arms the next key for one second: G then D → dashboard.
            if (key === 'g' || key === 'G') { this.pendingG = true; setTimeout(() => { this.pendingG = false; }, 1000); return; }
            if (this.pendingG && (key === 'd' || key === 'D')) {
                this.pendingG = false;
                this.go(this.$el.dataset.dashboard);
                return;
            }
            this.pendingG = false;

            if (key === 'n' || key === 'N') {
                this.go(this.$el.dataset.newLead);
            } else if (key === 'q' || key === 'Q') {
                this.go(this.$el.dataset.newQuote);
            } else if (key === '/') {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('focus-global-search'));
            }
        },

        go(url) {
            if (!url) return;
            // Livewire's SPA navigation when possible; a full load when the URL is unchanged
            // (e.g. pressing N while already on the leads list — the query param must re-land).
            if (window.location.pathname + window.location.search === url.replace(/^https?:\/\/[^/]+/, '')) {
                window.location.assign(url);
            } else if (window.Livewire?.navigate) {
                window.Livewire.navigate(url);
            } else {
                window.location.assign(url);
            }
        },
    }));
});
