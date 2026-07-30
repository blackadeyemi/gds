<div x-on:print-labels.window="window.open($event.detail.url, '_blank')">
    <div class="page-head">
        <h1>Supplier Deliveries</h1>
        <p>Generate and print raw-material barcodes for goods received from a supplier.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div class="card card-pad" style="max-width:760px;margin:0 auto;">
        @if ($step === 'form')
            <form wire:submit="toConfirm" wire:key="rmsd-step-form">
                <div class="form-group">
                    <label class="form-label">Current Date</label>
                    @include('bil::partials.date-field', ['model' => 'dateIso', 'disabled' => ! $this->canBackdate()])
                    @error('dateIso') <div class="form-error">{{ $message }}</div> @enderror
                    @unless($this->canBackdate())
                        <div class="text-muted text-sm" style="margin-top:.25rem;">Locked to today — needs the “backdate” permission.</div>
                    @endunless
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    @include('bil::partials.combobox', [
                        'model' => 'productid',
                        'labelText' => 'Product Name',
                        'placeholder' => 'Search product…',
                        'items' => $this->products->map(fn ($p) => ['value' => $p->id, 'label' => $p->productname]),
                    ])
                    @include('bil::partials.combobox', [
                        'model' => 'suppliercode',
                        'labelText' => 'Supplier',
                        'placeholder' => 'Search supplier…',
                        'items' => $this->suppliers->map(fn ($s) => ['value' => $s->suppliercode, 'label' => $s->suppliername]),
                    ])
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Weight (kg)</label>
                        <input type="number" step="0.01" min="0" class="form-control" wire:model="weight" placeholder="e.g. 20.49">
                        @error('weight') <div class="form-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Number of Barcodes <span class="text-muted text-sm">(max {{ $this->maxBarcodes }})</span></label>
                        <input type="number" min="1" max="{{ $this->maxBarcodes }}" class="form-control" wire:model="numBarcode" placeholder="e.g. 10">
                        @error('numBarcode') <div class="form-error">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary" style="width:100%;">Create Barcode</button>
                </div>
            </form>
        @else
            <form wire:submit="generate" wire:key="rmsd-step-confirm">
                <div class="flex items-center justify-between" style="margin-bottom:1rem;">
                    <h3 class="mb-0">Confirm weights — {{ count($weights) }} barcode{{ count($weights) === 1 ? '' : 's' }}</h3>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="back">&larr; Back</button>
                </div>
                <p class="text-muted text-sm" style="margin-bottom:1rem;">Adjust the weight for each barcode if needed, then confirm to create and print.</p>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:0.6rem;max-height:50vh;overflow-y:auto;padding:2px;">
                    @foreach ($weights as $i => $w)
                        <div class="form-group" style="margin-bottom:0;" wire:key="weight-row-{{ $i }}">
                            <label class="form-label text-sm">#{{ $i + 1 }}</label>
                            <input type="number" step="0.01" min="0" class="form-control" style="text-align:center;" wire:model="weights.{{ $i }}" wire:key="weight-input-{{ $i }}">
                            @error('weights.' . $i) <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>

                <div style="margin-top:1.25rem;">
                    <button type="submit" class="btn btn-primary" style="width:100%;" wire:loading.attr="disabled" wire:target="generate">
                        <span wire:loading.remove wire:target="generate">Confirm &amp; Print</span>
                        <span wire:loading wire:target="generate">Creating…</span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
