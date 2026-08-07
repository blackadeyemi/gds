<?php

namespace Modules\Core\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Support\GridExporter;

/**
 * Reusable statistics dashboard base. A module builds an analytics page by
 * extending this and declaring its sections + the tiles/charts each one shows;
 * the base handles the section nav, the time-range control, and rendering
 * through the shared `core::livewire.statistics` view (KPI tiles + Chart.js
 * cards, theme-aware, light/dark). Charts are declared as plain specs — see
 * chartSpec() — and drawn client-side by public/js/statistics.js.
 *
 * A concrete page implements: pageTitle(), sections(), and section($key) which
 * returns ['tiles' => [...], 'charts' => [...]] for the active section, using
 * rangeStart()/rangeEnd() to scope its queries to the chosen time range.
 */
#[Layout('core::layouts.admin')]
abstract class StatisticsPage extends Component
{
    #[Url]
    public string $section = '';

    #[Url]
    public string $range = '30d';

    /** 'rounded' = compact figures (48.4M kg); 'exact' = full numbers. */
    #[Url]
    public string $figures = 'rounded';

    /* ---------------- Child contract ---------------- */

    abstract public function pageTitle(): string;

    public function pageSubtitle(): string
    {
        return '';
    }

    /** Section key => label, in nav order. The first is the default. */
    abstract public function sections(): array;

    /** Return ['tiles' => [...], 'charts' => [...]] for the given section key. */
    abstract protected function section(string $key): array;

    /* ---------------- Time range ---------------- */

    /** Range key => [label, days|months|null]. null = all time. */
    public function rangeOptions(): array
    {
        return [
            '7d' => ['Last 7 days', 7],
            '30d' => ['Last 30 days', 30],
            '90d' => ['Last 90 days', 90],
            '12m' => ['Last 12 months', 365],
            'all' => ['All time', null],
        ];
    }

    /** Start of the selected range, or null for "all time". */
    protected function rangeStart(): ?Carbon
    {
        // NB: use the option's own value, not `?? 30` — 'all' stores null on
        // purpose (all-time) and `null ?? 30` would wrongly clamp it to 30 days.
        $opt = $this->rangeOptions()[$this->range] ?? [null, 30];
        $days = $opt[1];

        return $days === null ? null : now()->subDays($days - 1)->startOfDay();
    }

    protected function rangeEnd(): Carbon
    {
        return now()->endOfDay();
    }

    public function rangeLabel(): string
    {
        return $this->rangeOptions()[$this->range][0] ?? 'Last 30 days';
    }

    /** Whether figures should be shown compact (rounded) rather than in full. */
    public function isRounded(): bool
    {
        return $this->figures !== 'exact';
    }

    /**
     * Format a numeric quantity/weight per the current Rounded/Exact toggle.
     * Rounded → K/M/B compaction (≥100k); Exact → full comma-grouped number.
     */
    public function figure(float $v, ?string $unit = null): string
    {
        if ($this->isRounded()) {
            $a = abs($v);
            if ($a >= 1e9) {
                $out = rtrim(rtrim(number_format($v / 1e9, 1), '0'), '.') . 'B';
            } elseif ($a >= 1e6) {
                $out = rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M';
            } elseif ($a >= 1e5) {
                $out = rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'K';
            } else {
                $out = number_format($v);
            }
        } else {
            $out = number_format($v);
        }

        return $unit ? $out . ' ' . $unit : $out;
    }

    /* ---------------- Tile / chart builders (helpers for children) ---------------- */

    /** A KPI tile. tone: 'pos' | 'neg' | 'brand' | null. */
    protected function tile(string $label, $value, ?string $sub = null, ?string $tone = null): array
    {
        return ['label' => $label, 'value' => $value, 'sub' => $sub, 'tone' => $tone];
    }

    /**
     * A chart spec consumed by public/js/statistics.js.
     *
     * @param  string  $type   'donut' | 'bar' | 'hbar' | 'line'
     * @param  array   $labels x-axis / slice labels
     * @param  array   $series [['name' => string, 'data' => number[]], ...]
     * @param  array   $opt    ['subtitle','height','span'(1|2),'valueFmt'('int'|'kg'|'hrs'|'pct')]
     */
    protected function chartSpec(string $id, string $type, string $title, array $labels, array $series, array $opt = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'subtitle' => $opt['subtitle'] ?? null,
            'labels' => $labels,
            'series' => $series,
            'height' => $opt['height'] ?? 240,
            'span' => $opt['span'] ?? 1,
            'valueFmt' => $opt['valueFmt'] ?? 'int',
            'compact' => $this->isRounded(),
        ];
    }

    /* ---------------- Lifecycle ---------------- */

    public function mount(): void
    {
        $keys = array_keys($this->sections());
        if ($this->section === '' || ! in_array($this->section, $keys, true)) {
            $this->section = $keys[0] ?? '';
        }
        if (! array_key_exists($this->range, $this->rangeOptions())) {
            $this->range = '30d';
        }
        if (! in_array($this->figures, ['rounded', 'exact'], true)) {
            $this->figures = 'rounded';
        }
    }

    public function selectSection(string $key): void
    {
        if (array_key_exists($key, $this->sections())) {
            $this->section = $key;
        }
    }

    /* ---------------- Export ---------------- */

    /**
     * Enable the per-tab Export menu by returning the download route name (which
     * should `new` this component, set section/range from the query, and return
     * exportResponse($format)). Null (default) hides the menu.
     */
    protected function exportRouteName(): ?string
    {
        return null;
    }

    /**
     * The page-registry key gating this dashboard's export (e.g.
     * 'bil.raw_materials.statistics'). Null = not access-gated (export follows
     * page view only).
     */
    protected function exportPageKey(): ?string
    {
        return null;
    }

    public function canExport(): bool
    {
        if ($this->exportRouteName() === null) {
            return false;
        }
        $key = $this->exportPageKey();

        return $key === null || (bool) auth()->user()?->canDo($key, 'export');
    }

    /** Print prints the dashboard cards in-browser (window.print + @media print). */
    public function canPrint(): bool
    {
        return true;
    }

    public function exportUrl(string $format): string
    {
        return route($this->exportRouteName(), [
            'format' => $format,
            'section' => $this->section,
            'range' => $this->range,
        ]);
    }

    protected function fmtExport($v, string $kind): string
    {
        if ($v === null) {
            return '';
        }
        $n = (float) $v;

        return match ($kind) {
            'pct' => rtrim(rtrim(number_format($n, 1), '0'), '.') . '%',
            'kg' => number_format($n) . ' kg',
            'hrs' => number_format($n) . ' h',
            default => number_format($n),
        };
    }

    /** Flatten the current section (tiles + every chart) into one Group/Item/Value table. */
    protected function flattenForExport(array $data): array
    {
        $rows = [];
        foreach ($data['tiles'] ?? [] as $t) {
            $label = $t['label'] . (! empty($t['sub']) ? ' (' . $t['sub'] . ')' : '');
            $rows[] = ['Summary', $label, (string) $t['value']];
        }
        foreach ($data['charts'] ?? [] as $c) {
            $series = $c['series'] ?? [];
            foreach (($c['labels'] ?? []) as $i => $lab) {
                if (count($series) <= 1) {
                    $rows[] = [$c['title'], (string) $lab, $this->fmtExport($series[0]['data'][$i] ?? null, $c['valueFmt'] ?? 'int')];
                } else {
                    foreach ($series as $s) {
                        $rows[] = [$c['title'], $lab . ' — ' . $s['name'], $this->fmtExport($s['data'][$i] ?? null, $c['valueFmt'] ?? 'int')];
                    }
                }
            }
        }

        return [['Group', 'Item', 'Value'], $rows];
    }

    /** Clamp section/range to valid values (used by the export/print routes). */
    protected function normalizeSelection(): void
    {
        $keys = array_keys($this->sections());
        if (! in_array($this->section, $keys, true)) {
            $this->section = $keys[0] ?? '';
        }
        if (! array_key_exists($this->range, $this->rangeOptions())) {
            $this->range = '30d';
        }
    }

    protected function exportLabel(): string
    {
        return $this->pageTitle() . ' — ' . ($this->sections()[$this->section] ?? '') . ' (' . $this->rangeLabel() . ')';
    }

    /** Build the export file for the current section/range (called from the download route). */
    public function exportResponse(string $format)
    {
        @set_time_limit(0);
        $this->normalizeSelection();

        [$headings, $rows] = $this->flattenForExport($this->section($this->section));
        $base = 'stats-' . Str::slug($this->pageTitle()) . '-' . $this->section . '-' . $this->range;

        if (strtolower($format) === 'pdf') {
            return GridExporter::pdf($base, $this->exportLabel(), $headings, $rows);
        }

        return GridExporter::download($format, $base, $headings, $rows);
    }

    /* ---------------- Render ---------------- */

    public function render()
    {
        $data = $this->section($this->section);
        $charts = $data['charts'] ?? [];

        return view('core::livewire.statistics', [
            'pageTitle' => $this->pageTitle(),
            'pageSubtitle' => $this->pageSubtitle(),
            'sectionsList' => $this->sections(),
            'activeSection' => $this->section,
            'rangeList' => array_map(fn ($o) => $o[0], $this->rangeOptions()),
            'tiles' => $data['tiles'] ?? [],
            'charts' => $charts,
        ]);
    }
}
