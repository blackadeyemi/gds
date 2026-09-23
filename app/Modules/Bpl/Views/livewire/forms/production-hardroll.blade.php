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
        {{-- The machine is baked into the barcode, so it is fixed once minted. --}}
        <select class="form-control" wire:model="papermachine" @disabled($editing)>
            <option value="">— Select machine —</option>
            @foreach ($this->machines() as $m)
                <option value="{{ $m }}">{{ $m }}</option>
            @endforeach
        </select>
        @error('papermachine') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label class="form-label">Customer</label>
    @include('core::partials.searchable-select', [
        'field' => 'customer_id',
        'options' => $this->customers,
        'valueKey' => 'id',
        'labelKey' => 'customername',
        'placeholder' => 'Select customer…',
    ])
    @error('customer_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

{{-- Grade first, then the product within it. The catalog holds 4,387 hardroll
     products; offering the lot in one picker put 405 KB of options into every
     render. Grade is also how the floor names a reel, so this is not a
     workaround so much as the right order. --}}
<div style="display:grid;grid-template-columns:1fr 2fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Grade Type</label>
        <select class="form-control" wire:model.live="form_gradetype">
            <option value="">— Select grade —</option>
            @foreach ($this->gradeTypes as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>
        @error('form_gradetype') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label class="form-label">Product</label>
        @include('core::partials.searchable-select', [
            'field' => 'product_id',
            'options' => $this->products,
            'valueKey' => 'id',
            'labelKey' => 'productname',
            'placeholder' => $this->form_gradetype === '' ? 'Choose a grade first…' : 'Select product…',
            // The option set changes with the grade, so the control has to be
            // replaced rather than morphed — it snapshots its options once.
            'key' => 'product-' . $this->form_gradetype,
        ])
        @error('product_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Core diameter (mm)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="corediameter">
        @error('corediameter') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Joints / breaks</label>
        <input type="number" step="1" min="0" class="form-control" wire:model="joints">
        @error('joints') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Weight (kg)</label>
        <input type="number" step="0.01" min="0" class="form-control" wire:model="weight">
        @error('weight') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

@unless ($editing)
    <div class="form-group">
        <label class="form-label">Hardroll cart</label>
        <select class="form-control" wire:model="cart">
            @foreach ($this->carts() as $c)
                <option value="{{ $c }}">{{ $c }}</option>
            @endforeach
        </select>
        <div class="form-hint">The letter appended to the hardroll number.</div>
        @error('cart') <div class="form-error">{{ $message }}</div> @enderror
    </div>
@endunless

<div class="form-group">
    <label class="form-label">
        <input type="checkbox" wire:model="hold"> On hold
    </label>
</div>

<div class="form-group">
    <label class="form-label">Comments</label>
    @foreach ($this->commentOptions() as $c)
        <label class="form-label" style="font-weight:400;">
            <input type="checkbox" value="{{ $c }}" wire:model="comments"> {{ $c }}
        </label>
    @endforeach
    @error('comments.*') <div class="form-error">{{ $message }}</div> @enderror
</div>
