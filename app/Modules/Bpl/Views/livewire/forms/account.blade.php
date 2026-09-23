@if ($missingNote)
    <div class="card" style="border-color:var(--warning,#b8860b);margin-bottom:1rem;padding:0.6rem 1rem;font-size:.875rem;">
        {{ $missingNote }} Choose a replacement, or leave it empty, and save.
    </div>
@endif

<div class="form-group">
    <label class="form-label">Account Name</label>
    <input type="text" class="form-control" wire:model="account" maxlength="50"
           placeholder="e.g. BEL Papyrus Limited" autofocus>
    @error('account') <div class="form-error">{{ $message }}</div> @enderror
</div>

@foreach ([
    'beneficiary' => ['Beneficiary Bank', 'The account the money lands in.'],
    'intermediary' => ['Intermediary Bank', 'Optional — for foreign-currency payments routed through another bank.'],
    'correspondent' => ['Correspondent Bank', 'Optional.'],
] as $field => [$label, $hint])
    <div class="form-group">
        <label class="form-label">{{ $label }}</label>
        <select class="form-control" wire:model="{{ $field }}">
            <option value="">{{ $field === 'beneficiary' ? '— Choose —' : '— None —' }}</option>
            @foreach ($this->banks as $b)
                <option value="{{ $b->id }}">{{ $b->name }}{{ $b->number ? ' (' . $b->number . ')' : '' }}</option>
            @endforeach
        </select>
        @error($field) <div class="form-error">{{ $message }}</div> @enderror
        <div class="form-hint">{{ $hint }}</div>
    </div>
@endforeach

<div style="display:grid;grid-template-columns:1fr 160px;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Further Credit Account</label>
        <input type="text" class="form-control mono" wire:model="further_acc" maxlength="100" autocomplete="off">
        @error('further_acc') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Currency</label>
        <select class="form-control" wire:model="currency_id">
            <option value="">— None —</option>
            @foreach ($this->currencies as $c)
                <option value="{{ $c->id }}">{{ $c->code }}</option>
            @endforeach
        </select>
        @error('currency_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>
