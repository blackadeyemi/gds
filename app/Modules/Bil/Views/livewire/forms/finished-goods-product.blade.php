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
    $maxPlies = \Modules\Bil\Livewire\FinishedGoods\Products::MAX_PLIES;
    $groupable = \Modules\Bil\Support\GradeType::groupable($plies);
    $externalSource = \Modules\Bil\Livewire\FinishedGoods\Products::HARDROLL_EXTERNAL;
    $isExternalSource = ($form['hardroll_company_id'] ?? '') === $externalSource;
    $sourceFactories = $isExternalSource ? [] : $this->hardrollFactoriesFor($form['hardroll_company_id'] ?? '');
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
<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Number of Ply</label>
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <input type="number" min="0" max="{{ $maxPlies }}" step="1" class="form-control"
                   style="width:6.5rem;" wire:model.live="form.ply">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="addPly"
                    @disabled($plies >= $maxPlies) title="Add another ply">+ Add ply</button>
        </div>
        @error('form.ply') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Embossing</label>
        <input type="text" class="form-control" wire:model="form.embossing" placeholder="e.g. Macro-Macro">
        @error('form.embossing') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Lam / Edge <span class="text-muted">(optional)</span></label>
        <select class="form-control" wire:model="form.lamedge">
            <option value="">— None —</option>
            @foreach (\Modules\Bil\Livewire\FinishedGoods\Products::LAM_EDGE as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>
        @error('form.lamedge') <div class="form-error">{{ $message }}</div> @enderror
    </div>
</div>

{{-- Production: Factory → Line → Project, repeatable ---------------------- --}}
<div class="form-label" style="margin:0.5rem 0 0.35rem;padding-top:0.75rem;border-top:1px solid var(--line);">Production</div>
<p class="text-muted text-sm" style="margin:0 0 0.75rem;">
    Where this product is made, as <strong>Factory → Line → Project</strong>. Line and project are
    optional — pin it only as far down as matters. Add a row for each machine the product runs on;
    a cleaner structure than a single typed-in machine name, and it survives a machine being renamed.
</p>

@if ($legacyMachine !== '')
    <div class="card" style="border-color:var(--warning);color:var(--warning);margin-bottom:0.75rem;padding:0.6rem 1rem;">
        Currently recorded as free text: <strong>{{ $legacyMachine }}</strong> — it matched no machine in
        the hierarchy. Add a row below to replace it.
    </div>
@endif

@forelse ($machineRows as $i => $row)
    @php
        $lines = $this->linesFor($row['factory_id'] ?? '');
        $projects = $this->projectsFor($row['line_id'] ?? '');
    @endphp
    <div wire:key="machine-row-{{ $i }}"
         style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:0.75rem;align-items:start;margin-bottom:0.5rem;">
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Factory</label>
            <select class="form-control" wire:model.live="machineRows.{{ $i }}.factory_id">
                <option value="">— Select factory —</option>
                @foreach ($this->factories as $f)
                    <option value="{{ $f['id'] }}">{{ $f['label'] }}</option>
                @endforeach
            </select>
            @error('machineRows.' . $i . '.factory_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Line <span class="text-muted">(optional)</span></label>
            <select class="form-control" wire:model.live="machineRows.{{ $i }}.line_id"
                    @disabled(($row['factory_id'] ?? '') === '')>
                <option value="">{{ ($row['factory_id'] ?? '') === '' ? 'Pick a factory first' : '— Whole factory —' }}</option>
                @foreach ($lines as $l)
                    <option value="{{ $l['id'] }}">{{ $l['label'] }}</option>
                @endforeach
            </select>
            @error('machineRows.' . $i . '.line_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Project (Machine) <span class="text-muted">(optional)</span></label>
            <select class="form-control" wire:model="machineRows.{{ $i }}.project_id"
                    @disabled(($row['line_id'] ?? '') === '' || $projects === [])>
                <option value="">
                    @if (($row['line_id'] ?? '') === '')
                        Pick a line first
                    @elseif ($projects === [])
                        No projects on this line
                    @else
                        — Whole line —
                    @endif
                </option>
                @foreach ($projects as $p)
                    <option value="{{ $p['id'] }}">{{ $p['label'] }}</option>
                @endforeach
            </select>
            @error('machineRows.' . $i . '.project_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <button type="button" class="btn btn-ghost btn-icon btn-sm" style="margin-top:1.75rem;"
                wire:click="removeMachineRow({{ $i }})" title="Remove this machine">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>
@empty
    <p class="text-muted text-sm" style="margin:0 0 0.5rem;">No machine assigned yet.</p>
@endforelse

<button type="button" class="btn btn-ghost btn-sm" wire:click="addMachineRow" style="margin-bottom:0.5rem;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    Add more
</button>

{{-- Hardroll ------------------------------------------------------------ --}}
<div class="form-label" style="margin:0.5rem 0 0.6rem;padding-top:0.75rem;border-top:1px solid var(--line);">Hardroll</div>

@if ($plies > 0)
    <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.75rem;">
        @for ($i = 0; $i < $plies; $i++)
            <div class="form-group" wire:key="ply-{{ $i }}">
                <label class="form-label">
                    Ply {{ $i + 1 }} Grade Type
                    <button type="button" wire:click="removePly({{ $i }})" title="Remove ply {{ $i + 1 }}"
                            style="background:none;border:0;padding:0 0 0 .35em;color:var(--danger);cursor:pointer;font:inherit;">&times;</button>
                </label>
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
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:0.75rem;">
        <div class="form-group">
            <label class="form-label">Grouping</label>
            <select class="form-control" wire:model.live="gradeGrouping" @disabled(! $groupable)>
                <option value="none">None (separate hardrolls)</option>
                @if ($groupable)
                    <option value="1-2">1-2</option>
                    @if ($plies > 2)
                        <option value="2-3">2-3</option>
                        <option value="1-2-3">1-2-3</option>
                    @endif
                @endif
            </select>
            @if (! $groupable && $plies > 1)
                <div class="text-muted text-sm" style="margin-top:0.35em;">Not available above 3 ply.</div>
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
    </div>
@else
    <p class="text-muted text-sm">Set a ply count (or press “Add ply”) to specify the hardroll grade type.</p>
@endif

<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Hardroll Source</label>
        <select class="form-control" wire:model.live="form.hardroll_company_id">
            <option value="">— Not specified —</option>
            @foreach ($this->companies as $c)
                <option value="{{ $c['id'] }}">{{ $c['label'] }}</option>
            @endforeach
            <option value="{{ $externalSource }}">Outside mill (type the name)</option>
        </select>
        @error('form.hardroll_company_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    @if ($isExternalSource)
        {{-- Outside mills aren't in the hierarchy, so the name is the record. --}}
        <div class="form-group">
            <label class="form-label">Mill Name</label>
            <input type="text" class="form-control" maxlength="50" wire:model="form.hardroll_source_text"
                   placeholder="e.g. PT Pindo, Imported">
            @error('form.hardroll_source_text') <div class="form-error">{{ $message }}</div> @enderror
        </div>
    @else
        <div class="form-group">
            <label class="form-label">Source Factory <span class="text-muted">(optional)</span></label>
            <select class="form-control" wire:model="form.hardroll_factory_id"
                    @disabled(($form['hardroll_company_id'] ?? '') === '' || $sourceFactories === [])>
                <option value="">
                    @if (($form['hardroll_company_id'] ?? '') === '')
                        Pick a source first
                    @elseif ($sourceFactories === [])
                        No factories on record
                    @else
                        — Any factory —
                    @endif
                </option>
                @foreach ($sourceFactories as $f)
                    <option value="{{ $f['id'] }}">{{ $f['label'] }}</option>
                @endforeach
            </select>
            @error('form.hardroll_factory_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>
    @endif
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
        <label class="form-label">Expected Production Waste (%)</label>
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

{{-- Drop area: click or drag a file onto it. The real <input> is hidden but
     still does the work, so keyboard and file-picker behaviour are unchanged. --}}
<div style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:start;">
    <div class="form-group" style="margin-bottom:0;"
         x-data="{
            over: false,
            drop(e) {
                this.over = false;
                const file = e.dataTransfer.files[0];
                if (file) { $refs.input.files = e.dataTransfer.files; $refs.input.dispatchEvent(new Event('change')); }
            }
         }"
         @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="drop($event)">

        <label class="fg-drop" :class="{ 'fg-drop-over': over }"
               style="display:flex;flex-direction:column;align-items:center;gap:0.4rem;padding:1.5rem 1rem;
                      border:2px dashed var(--line);border-radius:12px;background:var(--surface-2);
                      cursor:pointer;text-align:center;transition:border-color .15s,background .15s;"
               :style="over ? 'border-color:var(--brand);background:var(--brand-ring);' : ''">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted);">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v13"/>
            </svg>
            <span style="font-weight:600;">Drop an image here, or <span style="color:var(--brand);">browse</span></span>
            <span class="text-muted text-sm">PNG or JPG, up to 2 MB — leaving this empty keeps the current picture.</span>
            <input type="file" x-ref="input" wire:model="image" accept="image/*" class="sr-only"
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
        </label>

        <div wire:loading wire:target="image" class="text-muted text-sm" style="margin-top:0.5em;">
            Uploading…
        </div>
        @error('image') <div class="form-error">{{ $message }}</div> @enderror
    </div>

    <div style="min-width:150px;">
        @if ($image)
            <img src="{{ $image->temporaryUrl() }}" alt="New picture"
                 style="max-height:130px;border-radius:10px;border:1px solid var(--line);display:block;">
            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.4rem;" wire:click="$set('image', null)">
                Remove
            </button>
        @elseif ($this->currentImageUri)
            <img src="{{ $this->currentImageUri }}" alt="Current picture"
                 style="max-height:130px;border-radius:10px;border:1px solid var(--line);display:block;">
            <span class="text-muted text-sm">Current picture</span>
        @elseif ($currentImage !== '')
            {{-- Recorded but unreachable — say so, so it isn't mistaken for "no picture". --}}
            <p class="text-muted text-sm" style="max-width:220px;margin:0;">
                Current picture ({{ $currentImage }}) isn't available in this environment.
                Uploading a new one replaces it.
            </p>
        @endif
    </div>
</div>

@if ($editingId)
    <p class="text-muted text-sm" style="margin-top:0.5rem;">
        Saving records this as a new revision — the current specification is archived first.
    </p>
@endif
