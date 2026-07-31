<div style="display:grid;grid-template-columns:140px 1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Type</label>
        <select class="form-control" wire:model="type">
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
        ])
        @error('customercountry') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Port</label>
        <input type="text" class="form-control" wire:model="port" placeholder="e.g. Cotonou">
        @error('port') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="text" class="form-control" wire:model="customertelephone" placeholder="+234…">
        @error('customertelephone') <div class="form-error">{{ $message }}</div> @enderror
    </div>
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

<div class="form-group">
    <label class="form-label">Address</label>
    <textarea class="form-control" rows="2" wire:model="customeraddress" placeholder="Customer address"></textarea>
    @error('customeraddress') <div class="form-error">{{ $message }}</div> @enderror
</div>
