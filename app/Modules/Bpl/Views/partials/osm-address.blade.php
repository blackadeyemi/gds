{{--
    The Alpine component behind the Address field's OpenStreetMap autocomplete.

    It lives HERE, at page level, and not in the form blade, because @push only
    reaches the layout's script stack on a full page render. The form is built
    only while its modal is open (DataGrid::formPushesAssets), so a push from
    inside it would land in a stack that had already been output and the
    component would never register — every osmAddress instance would then throw
    "osmAddress is not defined".

    Rendered via Customers::extraView().
--}}
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
