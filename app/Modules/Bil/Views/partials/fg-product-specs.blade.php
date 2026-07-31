{{--
    Read-only QC specification sheet for one finished-goods product, with a
    revision picker. Rebuilt from specs() in the legacy js/quality_control.js —
    same field order and grouping, so a printed sheet still reads the same.

    The picker lists every revision: those archived in qc_revision plus the
    product's current one. Picking an older revision shows that spec exactly as
    it was recorded.
--}}
@php
    $spec = $this->specsRow;
    $history = $this->specsHistory;
    $val = fn ($key, $fallback = '—') => ($spec[$key] ?? '') === '' || ($spec[$key] ?? null) === null
        ? $fallback
        : $spec[$key];
    // History is oldest-first, so the last entry is the product's live spec.
    $isCurrent = $history && $specsIndex === count($history) - 1;
@endphp

<div class="modal-backdrop" x-data x-show="$wire.specsOpen" x-cloak
     @keydown.escape.window="$wire.closeSpecs()" style="display:none;">
    <div class="modal-card" style="max-width:1080px;" @click.outside="$wire.closeSpecs()">
        <div class="modal-head">
            <div>
                <h3 class="modal-title">{{ $spec['productname'] ?? 'Specification' }}</h3>
                <span class="text-muted text-sm">{{ $spec['productcode'] ?? '' }}</span>
            </div>
            <button class="modal-close" wire:click="closeSpecs">&times;</button>
        </div>

        <div class="modal-body">
            @if (! $spec)
                <p class="text-muted">No specification found.</p>
            @else
                <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.1rem;">
                    <label class="form-label" style="margin:0;">Revision</label>
                    {{-- Keyed by position: legacy revision numbers repeat. --}}
                    <select class="form-control" style="width:auto;min-width:190px;" wire:model.live="specsIndex">
                        @foreach ($history as $i => $revision)
                            <option value="{{ $i }}">
                                Rev {{ $revision['revnumber'] ?? '—' }}@if ($loop->last) (current) @endif
                                — {{ $revision['revdate'] ?? '' }}
                            </option>
                        @endforeach
                    </select>
                    @unless ($isCurrent)
                        <span class="badge badge-muted">Archived revision</span>
                    @endunless
                    <span class="text-muted text-sm" style="margin-left:auto;">
                        {{ count($history) }} {{ \Illuminate\Support\Str::plural('revision', count($history)) }} on record
                    </span>
                </div>

                @php
                    // [label, value] pairs, grouped exactly as the legacy sheet.
                    $sections = [
                        'Product' => [
                            ['Revision Date', $val('revdate')],
                            ['Product Group', $val('productgroup')],
                            ['Product Grade', $this->gradeNameFor($spec['basepaper'] ?? '') ?: '—'],
                            ['Production Machine', $val('mach')],
                            ['Number of Ply', $val('ply')],
                            ['Production Waste (%)', $val('waste')],
                            ['Embossing', $val('embossing')],
                            ['Lam / Edge', $val('lamedge')],
                        ],
                        'Hardroll' => [
                            ['Hardroll Grade Type', $val('basepaper')],
                            ['Hardroll Source', $val('hardrollsource')],
                            ['Hardroll GSM (g/m²)', $val('hardrollgsm')],
                            ['Hardroll Width (cm)', $val('hardrollwidth')],
                        ],
                        'Packing' => [
                            ['Rolls per Pack', $val('productrolls')],
                            ['Packs per Bundle', $val('productpacks')],
                            ['Rolls per Bundle', $val('rollsperbundle')],
                            ['Bundles per Palette', $val('productbundles')],
                            ['Bundles per Tonne', $val('bundlespertonne')],
                        ],
                        'Weights & Measurements' => [
                            ['Log Weight (g)', $val('logweight')],
                            ['Roll / Clip Weight (g)', $val('clipweight')],
                            ['Core Weight (g)', $val('coreweight')],
                            ['Actual Roll Weight (g)', $val('actualrollweight')],
                            ['Roll Diameter (cm)', $val('diameter')],
                            ['Perimeter (cm)', $val('perimeter')],
                            ['Core Diameter (cm)', $val('corediameter')],
                            ['Sheet Width (cm)', $val('sheetwidth')],
                            ['Wrapper Weight (g)', $val('wrapperweight')],
                            ['Polybag Weight (g)', $val('polybagweight')],
                            ['Polybundle Weight (g)', $val('polybundleweight')],
                            ['Sheet Length (cm)', $val('sheetlength')],
                            ['GSM (g/m²)', $val('gsm')],
                            ['Pulls', $val('pulls')],
                            ['Sheet Counts', $val('sheetcounts')],
                            ['Roll Length (m)', $val('rolllength')],
                            ['Net Weight (g) — tissue only', $val('netweight')],
                            ['Gross Weight (g)', $val('grossweight')],
                        ],
                    ];
                @endphp

                @foreach ($sections as $heading => $fields)
                    <div class="form-label" style="margin:0 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">{{ $heading }}</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));gap:0.75rem 1rem;margin-bottom:0.6rem;">
                        @foreach ($fields as [$label, $value])
                            <div>
                                <div class="form-label" style="margin-bottom:0.15em;">{{ $label }}</div>
                                <div>{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                {{-- A recorded-but-unresolvable picture is called out rather than
                     just omitted: otherwise a wrong BIL_QC_PICS_PATH looks
                     exactly like "this product has no photo". --}}
                @if ($this->specsImage)
                    <div class="form-label" style="margin:0 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Product Picture</div>
                    <img src="{{ $this->specsImage }}" alt="{{ $spec['productname'] ?? '' }}"
                         style="max-height:240px;max-width:100%;border-radius:8px;border:1px solid var(--line);">
                @elseif (trim((string) ($spec['imagepath'] ?? '')) !== '')
                    <div class="form-label" style="margin:0 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Product Picture</div>
                    <p class="text-muted text-sm" style="margin:0;">
                        A picture is recorded for this revision ({{ $spec['imagepath'] }}) but is not available in this
                        environment — the quality-control picture folder isn't reachable from this server.
                    </p>
                @endif
            @endif
        </div>

        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" wire:click="closeSpecs">Close</button>
        </div>
    </div>
</div>
