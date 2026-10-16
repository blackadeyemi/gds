<div class="dash-stats">
    @if ($failed || ! $summary)
        <p class="dash-stats-note">These figures could not be loaded just now. Open the statistics page for the full view.</p>
    @else
        <p class="dash-stats-range">
            {{ $summary['section'] }} · {{ $summary['range'] }}
            @if ($adjusted)
                <span class="dash-adjusted" title="This module does not offer the chosen range, so it shows its closest one.">closest available</span>
            @endif
        </p>

        @if ($summary['tiles'])
            <div class="dash-tiles">
                @foreach (array_slice($summary['tiles'], 0, 4) as $t)
                    <div class="dash-tile">
                        <div class="stat-val {{ $t['tone'] ? 'tone-' . $t['tone'] : '' }}">{{ $t['value'] }}</div>
                        <div class="stat-lbl">{{ $t['label'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($c = $summary['chart'])
            <div class="dash-chart">
                <div class="dash-chart-head">
                    <span>{{ $c['title'] }}</span>
                    @if (! empty($c['subtitle']))
                        <span class="chart-sub">{{ $c['subtitle'] }}</span>
                    @endif
                </div>
                @if (empty($c['labels']))
                    <div class="chart-empty" style="height:170px;">No data for this range.</div>
                @else
                    <div class="chart-wrap" style="height:170px;" x-data="statChart(@js(array_merge($c, ['height' => 170])))">
                        <canvas x-ref="canvas"></canvas>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
