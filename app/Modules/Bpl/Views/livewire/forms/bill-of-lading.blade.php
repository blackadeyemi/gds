@php $docs = \Modules\Bpl\Support\BillOfLadingDocs::class; @endphp

<div class="form-group">
    <label class="form-label">Packing List</label>
    @if ($editingId)
        <input type="text" class="form-control mono" readonly
               value="{{ $this->openPackingLists->firstWhere('id', $packing_id)->label ?? ('packing list ' . $packing_id) }}"
               style="background:var(--surface-2);color:var(--muted);cursor:default;">
        <div class="form-hint">A bill of lading belongs to the shipment it was issued for.</div>
    @else
        @include('bil::partials.combobox', [
            'model' => 'packing_id',
            'labelText' => 'Packing List',
            'bare' => true,
            'placeholder' => 'Search packing list…',
            'items' => $this->openPackingLists->map(fn ($p) => [
                'value' => $p->id,
                'label' => $p->label . ' · ' . ($p->customer ?: '—') . ' · ' . $p->date,
            ]),
        ])
        <div class="form-hint">Shipments with no bill of lading yet. Raising one freezes the packing list.</div>
    @endif
    @error('packing_id') <div class="form-error">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label class="form-label">B/L Number</label>
    <input type="text" class="form-control mono" wire:model="number" maxlength="100"
           placeholder="e.g. MEDULG558371" autocomplete="off" style="text-transform:uppercase;">
    @error('number') <div class="form-error">{{ $message }}</div> @enderror
    <div class="form-hint">The carrier's number, as printed on the document.</div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
    <div class="form-group">
        <label class="form-label">Shipped</label>
        @include('bil::partials.date-field', ['model' => 'shippedIso', 'compact' => true])
        @error('shippedIso') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Issued</label>
        @include('bil::partials.date-field', ['model' => 'issuedIso', 'compact' => true])
        @error('issuedIso') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Arrived</label>
        @include('bil::partials.date-field', ['model' => 'arrivalIso', 'compact' => true])
        @error('arrivalIso') <div class="form-error">{{ $message }}</div> @enderror
        <div class="form-hint">Leave empty while in transit.</div>
    </div>
</div>

<div class="form-group">
    <label class="form-label">Scanned Document</label>

    @if ($editingId && $currentDoc)
        <div class="card card-pad text-sm" style="background:var(--surface-2);margin-bottom:.5rem;">
            @if ($docs::exists($currentDoc))
                <a href="{{ route('bpl.logistics.bills-of-lading.document', $editingId) }}" target="_blank" rel="noopener" class="mono">
                    {{ $currentDoc }}
                </a>
                <span class="text-muted"> — on file. Choose a new one only to replace it.</span>
            @else
                <span class="badge badge-danger">missing</span>
                <span class="mono">{{ $currentDoc }}</span>
                <span class="text-muted"> is not in the documents folder — upload it again.</span>
            @endif
        </div>
    @endif

    <input type="file" class="form-control" wire:model="document"
           accept=".{{ implode(',.', $docs::EXTENSIONS) }}">
    @error('document') <div class="form-error">{{ $message }}</div> @enderror

    <div wire:loading wire:target="document" class="text-muted text-sm" style="margin-top:.35rem;">Uploading…</div>

    <div class="form-hint">
        {{ strtoupper(implode(', ', $docs::EXTENSIONS)) }}, up to {{ (int) ($docs::MAX_KB / 1024) }} MB.
        Stored in the folder shared with the legacy app, named after the packing list.
    </div>

    @unless ($docs::available())
        <div class="form-error" style="margin-top:.35rem;">
            The documents folder is not reachable — set BPL_BL_DOCS_PATH. Uploads will be attempted anyway.
        </div>
    @endunless
</div>
