{{-- One weight-allowance rule: customer + grade type + ply → kilograms off. --}}
<div class="form-group">
    <label class="form-label">Customer</label>
    @include('core::partials.searchable-select', [
        'field' => 'customer_id',
        'options' => $this->customers,
        'valueKey' => 'id',
        'labelKey' => 'customername',
        'placeholder' => 'Select customer…',
    ])
    <div class="form-hint">The allowance applies only to reels made for this customer.</div>
    @error('customer_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Grade Type</label>
        {{-- Drawn from the hardroll catalog so a rule can't be written against
             a grade that doesn't exist — the commonest way one silently
             matches nothing. --}}
        @include('core::partials.searchable-select', [
            'field' => 'gradetype',
            'options' => $this->gradeTypes->map(fn ($g) => ['value' => $g, 'label' => $g]),
            'valueKey' => 'value',
            'labelKey' => 'label',
            'placeholder' => 'Select grade type…',
        ])
        @error('gradetype') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label class="form-label">Ply</label>
        <input type="number" min="0" step="1" class="form-control" wire:model="ply">
        @error('ply') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label class="form-label">Allowance (kg)</label>
    <input type="number" min="0" step="0.01" class="form-control" wire:model="allowance">
    <div class="form-hint">Taken off the scale weight. Zero is a valid rule — it says this
        combination was considered and gets nothing.</div>
    @error('allowance') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Note <span class="text-muted">(optional)</span></label>
    <input type="text" class="form-control" wire:model="note" maxlength="255"
           placeholder="Why this value, or who asked for it">
    @error('note') <div class="form-error">{{ $message }}</div> @enderror
</div>

<p class="text-muted text-sm" style="margin-bottom:0;">
    Saving records the change in History with your username. Reels already recorded keep the
    weight they were given — a new rule applies to the next reel entered, not to past ones.
</p>
