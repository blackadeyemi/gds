@php
    $editing = (bool) $this->editingId;
@endphp

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Date of manufacture</label>
        @include('bil::partials.date-field', ['model' => 'dateofmanufacture', 'disabled' => $editing || ! $this->canBackdate()])
        @error('dateofmanufacture') <div class="form-error">{{ $message }}</div> @enderror
        @unless ($this->canBackdate())
            <div class="form-hint">Fixed to today — you do not have backdate rights on this page.</div>
        @endunless
    </div>

    <div class="form-group">
        <label class="form-label">Paper machine</label>
        {{-- Part of the barcode, so fixed once minted. --}}
        <select class="form-control" wire:model="papermachine" @disabled($editing)>
            <option value="">— Select machine —</option>
            @foreach ($this->machines() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @error('papermachine') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label class="form-label">Product</label>
    @include('core::partials.searchable-select', [
        'field' => 'product_id',
        'options' => $this->products,
        'valueKey' => 'id',
        'labelKey' => 'productname',
        'placeholder' => 'Select product…',
    ])
    <div class="form-hint">Grade, grammage and diameter come from the product — they are no longer typed per roll.</div>
    @error('product_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Brightness (% ISO)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="brightness">
        <div class="form-hint">Measured off this roll.</div>
        @error('brightness') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Weight (kg)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="weight">
        @error('weight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>
