{{--
    Conversion Output: book pallets off a converting line and print their labels.
    The line list only holds lines active in Conversion Setup, and picking one
    fills in the product and bundles-per-pallet from that setup.
--}}
@php
    $setup = $this->setup;
    $product = $this->product;
    $place = $this->placement;
    $maxPallets = \Modules\Bil\Livewire\FinishedGoods\ConversionOutput::MAX_PALLETS;
    $wasteBlock = $this->wasteBlock;
@endphp

<div>
    @include('core::partials.shift-guard')

    <div class="page-head">
        <h1>Conversion Output</h1>
        <p>Record pallets coming off a line and print their barcode labels.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div class="card" style="padding:1.4rem;">
        <form wire:submit="generate">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Line</label>
                    <select class="form-control" wire:model.live="line_id">
                        <option value="">— Select line —</option>
                        @foreach ($this->activeLines as $l)
                            <option value="{{ $l['id'] }}">{{ $l['label'] }}</option>
                        @endforeach
                    </select>
                    @if ($this->activeLines->isEmpty())
                        <div class="form-error">No line is set up to convert anything — set one in Conversion Setup first.</div>
                    @endif
                    @error('line_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>

                <div class="form-group">
                    <label class="form-label">Shift</label>
                    <select class="form-control" wire:model="shift">
                        <option value="day">Day</option>
                        <option value="night">Night</option>
                    </select>
                    @error('shift') <div class="form-error">{{ $message }}</div> @enderror
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Date
                        @unless ($this->canBackdate()) <span class="text-muted">(today)</span> @endunless
                    </label>
                    @include('bil::partials.date-field', [
                        'model' => 'dateIso',
                        'live' => false,
                        'max' => now()->format('Y-m-d'),
                        'disabled' => ! $this->canBackdate(),
                    ])
                </div>
            </div>

            {{-- What the chosen line is set up to run. Read-only: it comes from
                 Conversion Setup, and changing it here would desync the two. --}}
            @if ($setup)
                <div class="card" style="background:var(--surface-2);padding:0.9rem 1.1rem;margin-bottom:1rem;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(170px, 1fr));gap:0.75rem 1rem;">
                        <div>
                            <div class="form-label" style="margin-bottom:0.15em;">Product</div>
                            <div>{{ $setup->productname }}</div>
                        </div>
                        <div>
                            <div class="form-label" style="margin-bottom:0.15em;">Product Code</div>
                            <div>{{ $product?->productcode ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="form-label" style="margin-bottom:0.15em;">Bundles per Pallet</div>
                            <div>{{ $setup->bundles }}</div>
                        </div>
                        <div>
                            <div class="form-label" style="margin-bottom:0.15em;">Factory</div>
                            <div>{{ $place['factory']->name ?? '—' }} @if ($place['code'] ?? null)<span class="text-muted">({{ $place['code'] }})</span>@endif</div>
                        </div>
                        <div>
                            <div class="form-label" style="margin-bottom:0.15em;">Line / Sub-line</div>
                            <div>{{ $place['linename'] ?? '—' }}@if (($place['sublinename'] ?? null) !== ($place['linename'] ?? null)) → {{ $place['sublinename'] }}@endif</div>
                        </div>
                        <div>
                            <div class="form-label" style="margin-bottom:0.15em;">Weight per Pallet</div>
                            <div>
                                @if ($product)
                                    {{ number_format((float) $product->netweight * (int) $setup->bundles / 1000, 2) }} kg net
                                @else — @endif
                            </div>
                        </div>
                    </div>

                    @if (! $product)
                        <p class="form-error" style="margin:0.75rem 0 0;">
                            "{{ $setup->productname }}" is not in the finished-goods master, so no barcode can be
                            created for it. Fix the product on the line in Conversion Setup.
                        </p>
                    @endif
                    @if (! ($place['code'] ?? null))
                        <p class="form-error" style="margin:0.75rem 0 0;">
                            This line has no factory with a barcode code (B1 / B2 / GB), so a label can't be minted.
                        </p>
                    @endif
                </div>
            @endif

            {{-- The waste rule, said before the form is filled in rather than
                 after Generate is pressed. --}}
            @if ($wasteBlock)
                <div class="card" style="border-color:var(--danger);margin-bottom:1rem;padding:0.8rem 1.25rem;">
                    <div style="color:var(--danger);font-weight:600;">Previous run's waste is not confirmed</div>
                    <div class="text-sm" style="margin-top:0.25rem;">{{ $wasteBlock }}</div>
                    <a href="{{ route('bil.finished-goods.conversion-waste') }}" class="btn btn-ghost btn-sm" style="margin-top:0.5rem;">
                        Go to Conversion Waste
                    </a>
                </div>
            @endif

            {{-- Stacked and centred: the count and the action are the end of the
                 form, not another pair of side-by-side fields. --}}
            <div style="text-align:center;">
                <div class="form-group" style="max-width:14rem;margin:0 auto 1rem;">
                    <label class="form-label" style="text-align:center;">Number of Pallets</label>
                    <input type="number" min="1" max="{{ $maxPallets }}" step="1"
                           class="form-control" style="text-align:center;" wire:model="pallets">
                    <div class="text-muted text-sm" style="margin-top:0.35em;">Up to {{ $maxPallets }} labels in one run.</div>
                    @error('pallets') <div class="form-error">{{ $message }}</div> @enderror
                </div>

                <button type="submit" class="btn btn-primary"
                        @disabled(! $setup || ! $product || ! ($place['code'] ?? null) || $wasteBlock)>
                    <span wire:loading.remove wire:target="generate">Create pallets &amp; print labels</span>
                    <span wire:loading wire:target="generate">Creating…</span>
                </button>
            </div>
        </form>
    </div>

    {{-- Labels open in their own tab, which prints itself. --}}
    <script>
        window.addEventListener('print-labels', function (e) {
            window.open(e.detail.url, '_blank');
        });
    </script>
</div>
