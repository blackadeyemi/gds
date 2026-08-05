<div style="display:grid;grid-template-columns:150px 1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Type</label>
        <select class="form-control" wire:model.live="type">
            <option value="">— Select —</option>
            <option value="Local">Local</option>
            <option value="Export">Export</option>
        </select>
        @error('type') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Label</label>
        <input type="text" class="form-control" wire:model="customerlabel" maxlength="20" placeholder="e.g. S.I.C.I.E." autofocus>
        @error('customerlabel') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Customer</label>
        <input type="text" class="form-control" wire:model="customername" placeholder="Full customer name">
        @error('customername') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Country</label>
        @include('core::partials.searchable-select', [
            'field' => 'customercountry',
            'options' => $this->countries,
            'valueKey' => 'name',
            'labelKey' => 'name',
            'placeholder' => 'Select country…',
            'live' => true,
        ])
        @error('customercountry') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    @if ($type === 'Export')
    <div class="form-group">
        <label class="form-label">Port</label>
        @include('core::partials.searchable-select', [
            'field' => 'port',
            'options' => $this->ports,
            'valueKey' => 'value',
            'labelKey' => 'label',
            'placeholder' => $customercountry ? 'Select port…' : 'Choose a country first',
            'key' => 'port-' . $customercountry,
        ])
        @error('port') <div class="form-error">{{ $message }}</div> @enderror
        @if ($customercountry && $this->ports->isEmpty())
            <div class="text-sm text-muted" style="margin-top:0.25rem;">No ports listed for {{ $customercountry }} yet.</div>
        @endif
    </div>
    @endif
</div>

<div style="display:grid;grid-template-columns:minmax(220px,270px) 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Dial code</label>
        @include('core::partials.searchable-select', [
            'field' => 'phone_dialcode',
            'options' => $this->dialCodes,
            'valueKey' => 'value',
            'labelKey' => 'label',
            'placeholder' => 'Code…',
        ])
        @error('phone_dialcode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Phone number</label>
        <input type="tel" class="form-control" wire:model="customertelephone" placeholder="801 234 5678" inputmode="tel">
        @error('customertelephone') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Fax</label>
        <input type="text" class="form-control" wire:model="fax" placeholder="Optional">
        @error('fax') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" class="form-control" wire:model="email" placeholder="Optional">
        @error('email') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

{{-- Address with OpenStreetMap autocomplete + map pin --}}
<div class="form-group" x-data="osmAddress()" @click.outside="open = false">
    <label class="form-label">Address</label>
    <div class="ss" style="position:relative;">
        <input type="text" class="form-control" wire:model="customeraddress"
               @input.debounce.700ms="search()" @focus="results.length && (open = true)"
               placeholder="Start typing an address, then pick a match…" autocomplete="off">
        <div class="ss-menu" x-show="open" x-cloak x-transition style="display:none;">
            <div class="ss-list">
                <template x-for="r in results" :key="r.label">
                    <button type="button" class="ss-option" @click="choose(r)">
                        <span x-text="r.label"></span>
                    </button>
                </template>
                <div class="ss-empty" x-show="!results.length && !loading">No matches</div>
                <div class="ss-empty" x-show="loading">Searching…</div>
            </div>
        </div>
    </div>
    @error('customeraddress') <div class="form-error">{{ $message }}</div> @enderror

    <template x-if="mapUrl()">
        <div style="margin-top:0.5rem;">
            <iframe :src="mapUrl()" style="width:100%;height:180px;border:1px solid var(--line);border-radius:6px;" loading="lazy" referrerpolicy="no-referrer"></iframe>
            <div class="text-sm text-muted" style="margin-top:0.25rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                <span>📍 <span x-text="coords()"></span></span>
                <a :href="'https://www.openstreetmap.org/?mlat=' + $wire.get('latitude') + '&mlon=' + $wire.get('longitude') + '#map=15/' + $wire.get('latitude') + '/' + $wire.get('longitude')" target="_blank" rel="noopener">View on OpenStreetMap</a>
            </div>
        </div>
    </template>
    <div class="text-sm text-muted" style="margin-top:0.25rem;">Address search © OpenStreetMap contributors.</div>
</div>

@push('scripts')
@once
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('osmAddress', () => ({
            results: [],
            open: false,
            loading: false,
            async search() {
                const term = (this.$wire.get('customeraddress') || '').trim();
                if (term.length < 3) { this.results = []; this.open = false; return; }
                this.loading = true;
                this.open = true;
                try {
                    const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=6&q=' + encodeURIComponent(term);
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    this.results = data.map((d) => ({ label: d.display_name, lat: parseFloat(d.lat), lon: parseFloat(d.lon) }));
                } catch (e) {
                    this.results = [];
                }
                this.loading = false;
                this.open = this.results.length > 0;
            },
            choose(r) {
                this.$wire.set('customeraddress', r.label);
                this.$wire.set('latitude', r.lat);
                this.$wire.set('longitude', r.lon);
                this.results = [];
                this.open = false;
            },
            coords() {
                const lat = parseFloat(this.$wire.get('latitude'));
                const lon = parseFloat(this.$wire.get('longitude'));
                if (!lat || !lon) return '';
                return lat.toFixed(5) + ', ' + lon.toFixed(5);
            },
            mapUrl() {
                const lat = parseFloat(this.$wire.get('latitude'));
                const lon = parseFloat(this.$wire.get('longitude'));
                if (!lat || !lon) return '';
                const d = 0.01;
                const bbox = [lon - d, lat - d, lon + d, lat + d].join(',');
                return 'https://www.openstreetmap.org/export/embed.html?bbox=' + bbox + '&layer=mapnik&marker=' + lat + ',' + lon;
            },
        }));
    });
</script>
@endonce
@endpush
