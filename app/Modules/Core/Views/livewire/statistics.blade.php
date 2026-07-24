<div>
    @assets
        <script src="{{ asset('js/chart.umd.min.js') }}"></script>
        <script src="{{ asset('js/statistics.js') }}"></script>
    @endassets

    <div class="page-head">
        <h1>{{ $pageTitle }}</h1>
        @if ($pageSubtitle)
            <p>{{ $pageSubtitle }}</p>
        @endif
    </div>

    {{-- Controls: section nav + time range --}}
    <div class="stats-controls">
        <nav class="stats-tabs">
            @foreach ($sectionsList as $key => $label)
                <button type="button"
                        class="stats-tab {{ $key === $activeSection ? 'active' : '' }}"
                        wire:click="selectSection('{{ $key }}')">
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        <div class="stats-range">
            <div class="figures-toggle" role="group" aria-label="Figure format">
                <button type="button" class="{{ $this->isRounded() ? 'active' : '' }}"
                        wire:click="$set('figures', 'rounded')" title="Rounded figures (48.4M)">Rounded</button>
                <button type="button" class="{{ ! $this->isRounded() ? 'active' : '' }}"
                        wire:click="$set('figures', 'exact')" title="Exact figures (48,412,345)">Exact</button>
            </div>

            <span class="text-muted text-sm">Range</span>
            <select class="form-control" style="width:auto;" wire:model.live="range">
                @foreach ($rangeList as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>

            @if ($this->canExport())
                <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                    <button class="btn btn-ghost btn-icon btn-sm" @click="open = !open" title="Export this tab">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                    </button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition @click="open = false" style="right:0;left:auto;">
                        <a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                            Export Excel (.xlsx)
                        </a>
                        <a class="dropdown-item" href="{{ $this->exportUrl('csv') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            Export CSV
                        </a>
                        <a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 18h6M9 12h2"/></svg>
                            Export PDF
                        </a>
                        @if ($this->canPrint())
                            <div class="dropdown-sep"></div>
                            <button type="button" class="dropdown-item" @click="open = false; $nextTick(() => window.print())">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                                Print cards
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Everything below re-mounts when section or range changes, so the charts
         rebuild cleanly (wire:key forces Livewire to replace the subtree). --}}
    <div wire:key="stats-{{ $activeSection }}-{{ $range }}"
         wire:loading.class="stats-dim">

        <div class="print-only stats-print-head">
            <strong>{{ $pageTitle }}</strong> — {{ $sectionsList[$activeSection] ?? '' }}
            <span>· {{ $rangeList[$range] ?? '' }}</span>
        </div>

        @if ($tiles)
            <div class="stat-flex">
                @foreach ($tiles as $t)
                    <div class="stat-card">
                        <div class="stat-val {{ $t['tone'] ? 'tone-' . $t['tone'] : '' }}">{{ $t['value'] }}</div>
                        <div class="stat-lbl">{{ $t['label'] }}</div>
                        @if (! empty($t['sub']))
                            <div class="stat-sub">{{ $t['sub'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($charts)
            <div class="chart-grid">
                @foreach ($charts as $c)
                    <div class="chart-card" style="grid-column: span {{ $c['span'] }};"
                         wire:key="chart-{{ $c['id'] }}-{{ $activeSection }}-{{ $range }}">
                        <div class="chart-card-head">
                            <h3>{{ $c['title'] }}</h3>
                            @if (! empty($c['subtitle']))
                                <span class="chart-sub">{{ $c['subtitle'] }}</span>
                            @endif
                        </div>
                        @if (empty($c['labels']))
                            <div class="chart-empty" style="height:{{ $c['height'] }}px;">No data for this range.</div>
                        @else
                            <div class="chart-wrap" style="height:{{ $c['height'] }}px;"
                                 x-data="statChart(@js($c))">
                                <canvas x-ref="canvas"></canvas>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if (! $tiles && ! $charts)
            <div class="card"><p class="text-muted mb-0">No statistics available.</p></div>
        @endif
    </div>
</div>
