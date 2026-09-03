/**
 * Statistics charts — a small Chart.js layer wired to the app's theme.
 *
 * Charts are declared server-side (StatisticsPage) as plain specs and rendered
 * by an Alpine component (`statChart`) on an x-ref canvas. A wire:key tied to
 * section+range forces Livewire to replace the chart subtree on change, so
 * Alpine re-inits and the chart rebuilds cleanly (no stale canvas).
 *
 * Colour follows the data-viz method: single-hue (brand blue) for single-series
 * magnitude, the validated 8-hue categorical palette for identity (donuts,
 * multi-series). Both palettes are stepped for light and dark surfaces.
 */
(function () {
    // Validated categorical palette (identity) — fixed order, light / dark steps.
    const CAT_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
    const CAT_DARK  = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
    // Single-hue magnitude ramp (blue), light / dark.
    const SEQ = { light: '#256abf', dark: '#3987e5' };

    const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';

    // Read a CSS custom property off :root so chrome tracks the app theme.
    const cssVar = (name, fallback) => {
        const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    };

    const chrome = () => {
        const dark = isDark();
        return {
            cats: dark ? CAT_DARK : CAT_LIGHT,
            seq: dark ? SEQ.dark : SEQ.light,
            surface: cssVar('--surface', dark ? '#1e293b' : '#ffffff'),
            ink: cssVar('--ink', dark ? '#e2e8f0' : '#1f2937'),
            muted: cssVar('--muted', dark ? '#94a3b8' : '#6b7280'),
            grid: dark ? 'rgba(148,163,184,0.16)' : 'rgba(17,24,39,0.08)',
        };
    };

    const compactNum = (n) => {
        const a = Math.abs(n);
        if (a >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
        if (a >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (a >= 1e5) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
        return n.toLocaleString(undefined, { maximumFractionDigits: 0 });
    };

    const fmtNumber = (v, kind, compact) => {
        if (v === null || v === undefined) return '—';
        const n = Number(v);
        if (kind === 'pct') return n.toFixed(1) + '%';
        const base = compact ? compactNum(n) : n.toLocaleString(undefined, { maximumFractionDigits: 0 });
        // Money leads with its symbol; everything else trails with its unit.
        return ({ ngn: '\u20A6' }[kind] || '') + base + ({ kg: ' kg', hrs: ' h' }[kind] || '');
    };

    // Chart.js shared defaults.
    function applyDefaults() {
        if (!window.Chart) return;
        const c = chrome();
        Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = c.muted;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.boxHeight = 10;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.padding = 12;
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.boxPadding = 5;
        Chart.defaults.maintainAspectRatio = false;
    }

    const gridScale = (c, { pct, compact } = {}) => ({
        grid: { color: c.grid, drawTicks: false, drawBorder: false },
        border: { display: false },
        ticks: {
            color: c.muted, padding: 8,
            callback: (v) => pct ? v + '%' : (compact ? compactNum(v) : Number(v).toLocaleString()),
        },
    });
    const catScale = (c) => ({
        grid: { display: false, drawBorder: false },
        border: { display: false },
        ticks: { color: c.muted, autoSkip: true, maxRotation: 0 },
    });

    function build(canvas, spec) {
        const c = chrome();
        const type = spec.type || 'bar';
        const labels = spec.labels || [];
        const series = spec.series || [];
        const kind = spec.valueFmt || 'int';
        const compact = !!spec.compact;
        const tip = (ctx) => `${ctx.dataset.label ? ctx.dataset.label + ': ' : ''}${fmtNumber(ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed, kind, compact)}`;

        if (type === 'donut') {
            return new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: series[0]?.data || [],
                        backgroundColor: labels.map((_, i) => c.cats[i % c.cats.length]),
                        borderColor: c.surface, borderWidth: 2, hoverOffset: 4,
                    }],
                },
                options: {
                    cutout: '62%',
                    plugins: {
                        legend: { position: 'right', labels: { color: c.ink } },
                        tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${fmtNumber(ctx.parsed, kind, compact)}` } },
                    },
                },
            });
        }

        if (type === 'hbar') {
            return new Chart(canvas, {
                type: 'bar',
                data: { labels, datasets: [{
                    label: series[0]?.name || '', data: series[0]?.data || [],
                    backgroundColor: c.seq, borderRadius: 4, borderSkipped: false,
                    barPercentage: 0.7, categoryPercentage: 0.8,
                }] },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: tip } } },
                    scales: { x: gridScale(c, { pct: kind === 'pct', compact }), y: catScale(c) },
                },
            });
        }

        if (type === 'line') {
            return new Chart(canvas, {
                type: 'line',
                data: { labels, datasets: series.map((s, i) => ({
                    label: s.name, data: s.data,
                    borderColor: series.length > 1 ? c.cats[i % c.cats.length] : c.seq,
                    backgroundColor: 'transparent',
                    borderWidth: 2, tension: 0.3, pointRadius: 0, pointHoverRadius: 4,
                    pointHoverBackgroundColor: series.length > 1 ? c.cats[i % c.cats.length] : c.seq,
                    pointHoverBorderColor: c.surface, pointHoverBorderWidth: 2,
                })) },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: series.length > 1, labels: { color: c.ink } },
                        tooltip: { callbacks: { label: tip } },
                    },
                    scales: { x: catScale(c), y: gridScale(c, { pct: kind === 'pct', compact }) },
                },
            });
        }

        // default: vertical bar (single or grouped)
        return new Chart(canvas, {
            type: 'bar',
            data: { labels, datasets: series.map((s, i) => ({
                label: s.name, data: s.data,
                backgroundColor: series.length > 1 ? c.cats[i % c.cats.length] : c.seq,
                borderRadius: 4, borderSkipped: false, barPercentage: 0.7, categoryPercentage: 0.8,
            })) },
            options: {
                plugins: {
                    legend: { display: series.length > 1, labels: { color: c.ink } },
                    tooltip: { callbacks: { label: tip } },
                },
                scales: { x: catScale(c), y: gridScale(c, { pct: kind === 'pct', compact }) },
            },
        });
    }

    document.addEventListener('alpine:init', () => {
        applyDefaults();
        window.Alpine.data('statChart', (spec) => ({
            chart: null,
            init() {
                if (!window.Chart) return;
                applyDefaults();
                this.chart = build(this.$refs.canvas, spec);
            },
            destroy() { if (this.chart) { this.chart.destroy(); this.chart = null; } },
        }));
    });
})();
