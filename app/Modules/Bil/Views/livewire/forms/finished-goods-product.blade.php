{{--
    Finished-goods QC specification form. Rebuilt from the legacy quality
    control form (myform() in js/quality_control.js), keeping its field order,
    grouping and the figures it works out for you.

    Fields that feed a calculation use wire:model.blur — the legacy form
    recalculated on the browser's `change` event, which is the same moment, and
    it keeps the round-trips down on a form this size.
--}}
@php
    $derived = 'background:var(--surface-2);';
    $plies = (int) ($form['ply'] ?? 0);
@endphp

{{-- Identity ------------------------------------------------------------ --}}
<div style="display:grid;grid-template-columns:1fr 1.6fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Product Code</label>
        <input type="text" class="form-control" wire:model="form.productcode" placeholder="e.g. FGGO0275" autofocus>
        @error('form.productcode') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Product Name</label>
        <input type="text" class="form-control" wire:model="form.productname" placeholder="e.g. Rose Natura TLT Small 1x6x8">
        @error('form.productname') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Product Group</label>
        <select class="form-control" wire:model="form.productgroup">
            <option value="">— Select group —</option>
            @foreach (\Modules\Bil\Livewire\FinishedGoods\Products::GROUPS as $group)
                <option value="{{ $group }}">{{ $group }}</option>
            @endforeach
        </select>
        @error('form.productgroup') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

{{-- Manufacture --------------------------------------------------------- --}}
<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Number of Ply</label>
        <select class="form-control" wire:model.live="form.ply">
            <option value="0">N/A</option>
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
        </select>
        @error('form.ply') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Production Machine</label>
        @include('core::partials.searchable-select', [
            'field' => 'form.mach',
            'options' => collect($this->machines)->map(fn ($m) => ['value' => $m, 'label' => $m]),
            'valueKey' => 'value',
            'labelKey' => 'label',
            'placeholder' => 'Select machine…',
        ])
        @error('form.mach') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Embossing</label>
        <input type="text" class="form-control" wire:model="form.embossing" placeholder="e.g. Macro-Macro">
        @error('form.embossing') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Lam / Edge</label>
        <select class="form-control" wire:model="form.lamedge">
            @foreach (\Modules\Bil\Livewire\FinishedGoods\Products::LAM_EDGE as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>
        @error('form.lamedge') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

{{-- Hardroll ------------------------------------------------------------ --}}
<div class="form-label" style="margin:0.5rem 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Hardroll</div>

@if ($plies > 0)
    <div style="display:grid;grid-template-columns:repeat({{ min($plies + 1, 4) }}, 1fr);gap:0.75rem;">
        @for ($i = 0; $i < $plies; $i++)
            <div class="form-group">
                <label class="form-label">Ply {{ $i + 1 }} Grade Type</label>
                @include('core::partials.searchable-select', [
                    'field' => 'gradeTypes.' . $i,
                    'options' => $this->grades,
                    'valueKey' => 'type',
                    'labelKey' => 'label',
                    'placeholder' => 'Select grade…',
                    'live' => true,
                    'key' => 'grade-' . $i,
                ])
            </div>
        @endfor

        @if ($plies > 1)
            <div class="form-group">
                <label class="form-label">Grouping</label>
                <select class="form-control" wire:model.live="gradeGrouping">
                    <option value="none">None (separate hardrolls)</option>
                    <option value="1-2">1-2</option>
                    @if ($plies > 2)
                        <option value="2-3">2-3</option>
                        <option value="1-2-3">1-2-3</option>
                    @endif
                </select>
            </div>
        @endif
    </div>

    <div class="form-group">
        <label class="form-label">Hardroll Grade Type</label>
        <input type="text" class="form-control" style="{{ $derived }}" value="{{ $form['basepaper'] ?? '' }}" readonly>
        <div class="text-muted text-sm" style="margin-top:0.35em;">
            Built from the grade type of each ply; brackets mark plies that share one hardroll.
        </div>
        @error('form.basepaper') <div class="form-error">{{ $message }}</div> @enderror
    </div>
@else
    <p class="text-muted text-sm">Set a ply count to specify the hardroll grade type.</p>
@endif

<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Hardroll Source</label>
        @include('core::partials.searchable-select', [
            'field' => 'form.hardrollsource',
            'options' => collect($this->hardrollSources)->map(fn ($s) => ['value' => $s, 'label' => $s]),
            'valueKey' => 'value',
            'labelKey' => 'label',
            'placeholder' => 'Select source…',
        ])
        @error('form.hardrollsource') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Hardroll Width (cm)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.hardrollwidth">
        @error('form.hardrollwidth') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Hardroll GSM (g/m²)</label>
        <input type="text" class="form-control" wire:model="form.hardrollgsm" maxlength="5">
        @error('form.hardrollgsm') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

{{-- Packing ------------------------------------------------------------- --}}
<div class="form-label" style="margin:0.5rem 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Packing</div>

<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Rolls per Pack</label>
        <input type="number" step="1" class="form-control" wire:model.blur="form.productrolls">
        @error('form.productrolls') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Packs per Bundle</label>
        <input type="number" step="1" class="form-control" wire:model.blur="form.productpacks">
        @error('form.productpacks') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Rolls per Bundle <span class="text-muted">(rolls × packs)</span></label>
        <input type="text" class="form-control" style="{{ $derived }}" wire:model="form.rollsperbundle" readonly>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Bundles per Palette</label>
        <input type="number" step="1" class="form-control" wire:model="form.productbundles">
        @error('form.productbundles') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Production Waste (%)</label>
        <input type="number" step="0.1" class="form-control" wire:model.blur="form.waste">
        @error('form.waste') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Bundles per Tonne <span class="text-muted">(from net weight & waste)</span></label>
        <input type="text" class="form-control" style="{{ $derived }}" wire:model="form.bundlespertonne" readonly>
    </div>
</div>

{{-- Weights & measurements ---------------------------------------------- --}}
<div class="form-label" style="margin:0.5rem 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Weights &amp; Measurements</div>

<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Log Weight (g)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.logweight">
        @error('form.logweight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Roll / Clip Weight (g)</label>
        <input type="number" step="0.01" class="form-control" wire:model.blur="form.clipweight">
        @error('form.clipweight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Core Weight (g)</label>
        <input type="number" step="0.01" class="form-control" wire:model.blur="form.coreweight">
        @error('form.coreweight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Actual Roll Weight (g) <span class="text-muted">(clip − core)</span></label>
        <input type="text" class="form-control" style="{{ $derived }}" wire:model="form.actualrollweight" readonly>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Roll Diameter (cm)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.diameter">
        @error('form.diameter') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Perimeter (cm)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.perimeter">
        @error('form.perimeter') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Core Diameter (cm)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.corediameter">
        @error('form.corediameter') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Sheet Width (cm) <span class="text-muted">min / mid / max</span></label>
        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.35rem;">
            <input type="number" step="0.01" class="form-control" wire:model="form.sheetwidthmin" placeholder="min">
            <input type="number" step="0.01" class="form-control" wire:model="form.sheetwidthmid" placeholder="mid">
            <input type="number" step="0.01" class="form-control" wire:model="form.sheetwidthmax" placeholder="max">
        </div>
        @error('form.sheetwidthmin') <div class="form-error">{{ $message }}</div> @enderror
        @error('form.sheetwidthmid') <div class="form-error">{{ $message }}</div> @enderror
        @error('form.sheetwidthmax') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Wrapper Weight (g)</label>
        <input type="number" step="0.01" class="form-control" wire:model.blur="form.wrapperweight">
        @error('form.wrapperweight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Polybag Weight (g)</label>
        <input type="number" step="0.01" class="form-control" wire:model.blur="form.polybagweight">
        @error('form.polybagweight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Polybundle Weight (g)</label>
        <input type="number" step="0.01" class="form-control" wire:model.blur="form.polybundleweight">
        @error('form.polybundleweight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Sheet Length (cm)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.sheetlength">
        @error('form.sheetlength') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">GSM (g/m²)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.gsm">
        @error('form.gsm') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Pulls</label>
        <input type="number" step="0.01" class="form-control" wire:model.blur="form.pulls">
        @error('form.pulls') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Sheet Counts <span class="text-muted">(pulls × ply)</span></label>
        <input type="text" class="form-control" style="{{ $derived }}" wire:model="form.sheetcounts" readonly>
    </div>
    <div class="form-group">
        <label class="form-label">Roll Length (m)</label>
        <input type="number" step="0.01" class="form-control" wire:model="form.rolllength">
        @error('form.rolllength') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Net Weight (g) <span class="text-muted">tissue only — actual roll × rolls per bundle</span></label>
        <input type="text" class="form-control" style="{{ $derived }}" wire:model="form.netweight" readonly>
    </div>
    <div class="form-group">
        <label class="form-label">Gross Weight (g) <span class="text-muted">net + wrapper + polybag + polybundle</span></label>
        <input type="text" class="form-control" style="{{ $derived }}" wire:model="form.grossweight" readonly>
    </div>
</div>

{{-- Picture -------------------------------------------------------------- --}}
<div class="form-label" style="margin:0.5rem 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Product Picture</div>

<div style="display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:start;">
    <div class="form-group">
        <input type="file" class="form-control" wire:model="image" accept="image/*">
        <div class="text-muted text-sm" style="margin-top:0.35em;">
            Optional, max 2 MB. Leaving this empty keeps the current picture.
        </div>
        @error('image') <div class="form-error">{{ $message }}</div> @enderror
        <div wire:loading wire:target="image" class="text-muted text-sm">Uploading…</div>
    </div>

    @if ($image)
        <img src="{{ $image->temporaryUrl() }}" alt="New picture" style="max-height:110px;border-radius:8px;border:1px solid var(--line);">
    @elseif ($this->currentImageUri)
        <img src="{{ $this->currentImageUri }}" alt="Current picture" style="max-height:110px;border-radius:8px;border:1px solid var(--line);">
    @endif
</div>

@if ($editingId)
    <p class="text-muted text-sm" style="margin-top:0.5rem;">
        Saving records this as a new revision — the current specification is archived first.
    </p>
@endif
