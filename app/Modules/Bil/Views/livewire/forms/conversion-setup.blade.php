{{-- One line's conversion setup: which product it runs, and the bundle target. --}}
<div class="form-group">
    <label class="form-label">Line</label>
    <select class="form-control" wire:model="line_id">
        <option value="">— Select line —</option>
        @foreach ($this->lines as $l)
            <option value="{{ $l['id'] }}">{{ $l['label'] }}</option>
        @endforeach
    </select>
    @error('line_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Product <span class="text-muted">(leave empty if the line is idle)</span></label>
    @include('core::partials.searchable-select', [
        'field' => 'productname',
        'options' => $this->products,
        'valueKey' => 'name',
        'labelKey' => 'label',
        'placeholder' => 'Select product…',
    ])
    @error('productname') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Bundle Target</label>
    <input type="number" min="0" step="1" class="form-control" wire:model="bundles">
    @error('bundles') <div class="form-error">{{ $message }}</div> @enderror
</div>

<p class="text-muted text-sm" style="margin-bottom:0;">
    Changing the product or the target records a changeover in the Conversion History,
    stamped with your username and the time.
</p>
