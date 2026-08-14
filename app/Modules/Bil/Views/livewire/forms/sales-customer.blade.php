{{-- Laid out as the three things a customer record actually is: who they are,
     how sales classifies them, and where they are. See the component. --}}

{{-- ── Identity ─────────────────────────────────────────────────────────── --}}
<div class="form-group">
    <label class="form-label">Customer</label>
    <input type="text" class="form-control" wire:model="customername"
           placeholder="e.g. PRINCE EBANO SUPERMARKET-ALAUSA" autofocus>
    @error('customername') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">Code</label>
    <input type="text" class="form-control" wire:model="customercode"
           placeholder="e.g. 41181366" inputmode="numeric">
    @error('customercode') <div class="form-error">{{ $message }}</div> @enderror
</div>

{{-- ── Location ─────────────────────────────────────────────────────────────
     Country → State → City. Country is a closed list of 250 and drives
     everything below it, including whether a sales territory applies at all —
     so it comes before classification on the form. --}}
<h4 class="form-section">Location</h4>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    @include('bil::partials.combobox', [
        'model' => 'customercountry',
        'labelText' => 'Country',
        'placeholder' => 'Search country…',
        'items' => collect($this->countryOptions)->map(fn ($n) => ['value' => $n, 'label' => $n]),
        'live' => true,
    ])

    <div class="form-group">
        <label class="form-label">Phone number</label>
        {{-- Dial code follows the country. Shown, never stored — the existing
             rows hold national numbers the legacy screens already print. --}}
        <div class="input-affix">
            @if ($this->dialCode)
                <span class="input-affix-prefix">{{ $this->dialCode }}</span>
            @endif
            <input type="tel" class="form-control" wire:model="customerphonenumber"
                   placeholder="{{ $this->dialCode === '+234' ? '08012345678' : 'National number' }}">
        </div>
        @error('customerphonenumber') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

@if ($countryWasBlank)
    <div class="form-hint" style="color:var(--danger);margin-top:-0.5rem;margin-bottom:0.75rem;">
        This customer had no country recorded — defaulted to {{ \Modules\Bil\Models\SalesCustomer::DEFAULT_COUNTRY }}. Change it if that is wrong.
    </div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
    {{-- State and city stay free text with suggestions: 64 spellings are in use
         against 37 real states, and a closed list would either reject those
         customers or quietly rewrite what their records say. --}}
    <div class="form-group">
        <label class="form-label">{{ $this->stateNoun }} <span style="font-weight:400">(optional)</span></label>
        <input type="text" class="form-control" wire:model.live.debounce.400ms="customerstate"
               list="sales-states" autocomplete="off"
               placeholder="{{ $this->stateOptions === [] ? 'Type a ' . strtolower($this->stateNoun) : 'Pick or type…' }}">
        <datalist id="sales-states">
            @foreach ($this->stateOptions as $s)
                <option value="{{ $s }}"></option>
            @endforeach
        </datalist>
        @error('customerstate') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label class="form-label">City <span style="font-weight:400">(optional)</span></label>
        <input type="text" class="form-control" wire:model="customercity"
               list="sales-cities" autocomplete="off"
               placeholder="{{ $this->cityOptions === [] ? 'Type a city' : 'Pick or type…' }}">
        <datalist id="sales-cities">
            @foreach ($this->cityOptions as $c)
                <option value="{{ $c }}"></option>
            @endforeach
        </datalist>
        @error('customercity') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label class="form-label">Address</label>
    <textarea class="form-control" rows="2" wire:model="customeraddress" placeholder="Street, area…"></textarea>
    @error('customeraddress') <div class="form-error">{{ $message }}</div> @enderror
</div>

{{-- ── Classification ───────────────────────────────────────────────────────
     What the sales reports group by. Territory is a division of Nigeria, so
     it is only shown when the country is Nigeria. --}}
<h4 class="form-section">Sales classification</h4>

<div style="display:grid;grid-template-columns:{{ $this->territoryApplies ? '1fr 1fr 1fr' : '1fr' }};gap:0.75rem;">
    @if ($this->territoryApplies)
        <div class="form-group">
            <label class="form-label">Region <span style="font-weight:400">(optional)</span></label>
            <select class="form-control" wire:model.live="customerregion">
                <option value="">— None —</option>
                @foreach ($this->regionOptions as $r)
                    <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>
            @error('customerregion') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label class="form-label">Designation <span style="font-weight:400">(optional)</span></label>
            <select class="form-control" wire:model="customerdesignation" @disabled($this->designationOptions === [])>
                <option value="">— None —</option>
                @foreach ($this->designationOptions as $d)
                    <option value="{{ $d }}">{{ $d }}</option>
                @endforeach
            </select>
            @if ($this->designationOptions === [])
                <div class="form-hint">Choose a region first.</div>
            @endif
            @error('customerdesignation') <div class="form-error">{{ $message }}</div> @enderror
        </div>
    @endif

    <div class="form-group">
        <label class="form-label">Channel <span style="font-weight:400">(optional)</span></label>
        <select class="form-control" wire:model="channel">
            <option value="">— None —</option>
            @foreach ($this->channelOptions as $c)
                <option value="{{ $c }}">{{ $c }}</option>
            @endforeach
        </select>
        @error('channel') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-hint">
    @unless ($this->territoryApplies)
        Sales regions and designations divide <strong>{{ \Modules\Bil\Support\SalesTerritory::COUNTRY }}</strong>,
        so they do not apply to this customer and will be cleared on save.
    @else
        Region, designation and channel are what the sales reports group by. They are optional,
        but a customer without them drops out of those reports — the <em>Unclassified</em> view lists them.
    @endunless
</div>
