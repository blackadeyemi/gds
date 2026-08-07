<div>
    <div class="page-head">
        <h1>{{ $this->title() }}</h1>
        @if ($this->subtitle())
            <p>{{ $this->subtitle() }}</p>
        @endif
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div class="card">
        {{-- Filter bar --}}
        <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
            @if ($this->hasDateRange())
                <div class="form-group" style="margin:0;">
                    <label class="form-label text-sm">From</label>
                    @include('bil::partials.date-field', ['model' => 'dateFrom', 'live' => true, 'compact' => true])
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label text-sm">To</label>
                    @include('bil::partials.date-field', ['model' => 'dateTo', 'live' => true, 'compact' => true])
                </div>
            @endif

            @foreach ($this->filterDefs() as $name => $def)
                @include('bil::partials.report-select', ['name' => $name, 'label' => $def['label'], 'options' => $def['options']])
            @endforeach

            <div class="flex items-center gap-2 ml-auto" style="flex-wrap:wrap;">
                <div class="search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="text" class="form-control" placeholder="Search…" wire:model.live.debounce.300ms="search">
                </div>

                @if (count($viewsList) > 1)
                    <select class="form-control" style="width:auto;min-width:200px;" wire:model.live="view">
                        @foreach ($viewsList as $v)
                            <option value="{{ $v['key'] }}">{{ $v['label'] }}</option>
                        @endforeach
                    </select>
                @endif

                @if ($paginated)
                    <div class="flex items-center gap-2 text-sm text-muted">
                        <span>Show</span>
                        <select class="form-control" style="width:auto;padding-top:0.35em;padding-bottom:0.35em;" wire:model.live="perPage">
                            @foreach ($perPageOptions as $opt)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                            @endforeach
                        </select>
                        <span>entries</span>
                    </div>
                @endif

                {{-- 3-dots: export + print --}}
                @if ($this->mayDo('export'))
                <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                    <button class="btn btn-ghost btn-icon btn-sm" @click="open = !open" title="Export / Print">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                    </button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition @click="open = false">
                        <a class="dropdown-item" href="{{ $this->downloadUrl('xlsx') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                            Export Excel (.xlsx)
                        </a>
                        <a class="dropdown-item" href="{{ $this->downloadUrl('csv') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            Export CSV
                        </a>
                        @if ($pdfBlocked)
                            <span class="dropdown-item" aria-disabled="true"
                                  title="This view has more than {{ $pdfMaxRows }} rows — too many for a PDF. Use Print or Export Excel instead."
                                  style="opacity:.45;cursor:not-allowed;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 18h6M9 12h2"/></svg>
                                Export PDF
                            </span>
                            <div class="dropdown-note" style="padding:0.15rem 0.9rem 0.4rem;font-size:0.72rem;line-height:1.3;color:var(--muted,#8a8a8a);max-width:230px;">
                                Over {{ $pdfMaxRows }} rows — PDF is disabled. Use <strong>Print</strong> or <strong>Export Excel</strong> for the full data.
                            </div>
                        @else
                            <a class="dropdown-item" href="{{ $this->downloadUrl('pdf') }}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 18h6M9 12h2"/></svg>
                                Export PDF
                            </a>
                        @endif
                        <div class="dropdown-sep"></div>
                        <a class="dropdown-item" target="_blank" href="{{ $this->printUrl() }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                            Print
                        </a>
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Only shown for the one combination whose total can't be counted
             cheaply: a joined-column filter (group / sub-group / product) or a
             search, over a multi-year range. Everything else totals instantly. --}}
        @if ($slowCount = $this->slowCountNotice())
            <div class="card-head" style="border-bottom:0;padding-top:0;">
                <span class="text-muted text-sm" style="display:flex;gap:.5rem;align-items:flex-start;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:.15em;"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span>{{ $slowCount }}</span>
                </span>
            </div>
        @endif

        @php
            $showActions = $this->hasActions() && ($gridView['type'] ?? 'table') === 'table';
            // Summary views can drill into the records behind each group.
            $expandBy = ($gridView['type'] ?? 'table') === 'summary' ? $this->expandableBy() : null;
            $spanCount = count($columns) + 1 + ($showActions ? 1 : 0) + ($expandBy ? 1 : 0);
        @endphp

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        @if ($expandBy)
                            <th style="width:44px"></th>
                        @endif
                        <th style="width:60px">#</th>
                        @foreach ($columns as $col)
                            <th>{{ $col[0] }}</th>
                        @endforeach
                        @if ($showActions)
                            <th class="col-actions">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $i => $row)
                        @php $rowKey = $expandBy ? $this->detailKeyFor($row) : null; @endphp
                        <tr wire:key="row-{{ $gridView['key'] }}-{{ $row->id ?? $i }}">
                            @if ($expandBy)
                                <td>
                                    <button type="button" class="btn btn-ghost btn-icon btn-sm"
                                            wire:click="openRowDetails(@js($rowKey))" title="View details">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </td>
                            @endif
                            <td>{{ $paginated ? $rows->firstItem() + $i : $i + 1 }}</td>
                            @foreach ($columns as $col)
                                <td>{!! isset($col[2]) && is_callable($col[2]) ? $col[2]($row) : e(data_get($row, $col[1]) ?? '—') !!}</td>
                            @endforeach
                            @if ($showActions)
                                <td class="col-actions">
                                    @if ($this->canEdit())
                                        @php $editBlock = $this->editGuard($row); @endphp
                                        @if ($editBlock)
                                            <button class="btn btn-ghost btn-icon btn-sm" disabled title="{{ $editBlock }}" style="opacity:.35;cursor:not-allowed;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                        @else
                                            <button class="btn btn-ghost btn-icon btn-sm" wire:click="editRow({{ $row->id }})" title="Edit">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                        @endif
                                    @endif
                                    @if ($this->canDelete())
                                        @php $delBlock = $this->deleteGuard($row); @endphp
                                        @if ($delBlock)
                                            <button class="btn btn-danger btn-icon btn-sm" disabled title="{{ $delBlock }}" style="opacity:.35;cursor:not-allowed;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        @else
                                            <button class="btn btn-danger btn-icon btn-sm" wire:click="$set('confirmingDelete', {{ $row->id }})" title="Delete">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                            </button>
                                        @endif
                                    @endif
                                </td>
                            @endif
                        </tr>

                    @empty
                        <tr><td colspan="{{ $spanCount }}" class="empty-row">No records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($paginated)
            <div class="card-head" style="border-top:1px solid var(--line);border-bottom:0;">
                <span class="text-muted text-sm">
                    @if ($hasTotal)
                        @if ($rows->total())
                            Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}
                        @endif
                    @elseif ($rows->count())
                        Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }}
                    @endif
                </span>
                {{ $rows->links($hasTotal ? 'core::partials.pagination' : 'bil::partials.simple-pagination') }}
            </div>
        @endif
    </div>

    {{-- Drill-down: the records behind one summary row. Read-only — the legacy
         grouped reports listed each group's records under a heading, and a modal
         gives them the width to read without pushing the table around. --}}
    @if ($this->expandableBy() && $detailOpen && $detailKey !== null)
        @php
            $details = $this->detailRows($detailKey);
            $detailCols = $this->detailColumns();
            $detailSub = $this->detailSubtitle($detailKey);
        @endphp
        <div class="modal-backdrop" x-data x-show="$wire.detailOpen" x-cloak
             @keydown.escape.window="$wire.closeRowDetails()" style="display:none;">
            <div class="modal-card modal-card-wide" @click.outside="$wire.closeRowDetails()">
                <div class="modal-head">
                    <div>
                        <h3 class="modal-title">{{ $this->detailTitle($detailKey) }}</h3>
                        @if ($detailSub)
                            <span class="text-muted text-sm">{{ $detailSub }}</span>
                        @endif
                    </div>
                    <button class="modal-close" wire:click="closeRowDetails">&times;</button>
                </div>

                <div class="modal-body">
                    @if (count($details))
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        @foreach ($detailCols as $dc)
                                            <th>{{ $dc[0] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($details as $d)
                                        <tr>
                                            @foreach ($detailCols as $dc)
                                                <td>{!! isset($dc[2]) && is_callable($dc[2]) ? $dc[2]($d) : e(data_get($d, $dc[1]) ?? '—') !!}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">Nothing to show for this row.</p>
                    @endif
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" wire:click="closeRowDetails">Close</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit modal --}}
    @if ($this->canEdit())
        <div class="modal-backdrop" x-data x-show="$wire.showEdit" x-cloak @keydown.escape.window="$wire.showEdit = false" style="display:none;">
            <div class="modal-card" style="max-width:420px;" @click.outside="$wire.showEdit = false">
                <div class="modal-head">
                    <h3 class="modal-title">Edit record</h3>
                    <button class="modal-close" wire:click="$set('showEdit', false)">&times;</button>
                </div>
                <form wire:submit="persistEdit">
                    <div class="modal-body">
                        @foreach ($this->editFields() as $name => $def)
                            <div class="form-group">
                                <label class="form-label">{{ $def['label'] }}</label>
                                <input type="number" step="{{ $def['step'] ?? 'any' }}" class="form-control" wire:model="edit.{{ $name }}">
                            </div>
                        @endforeach
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn btn-ghost" wire:click="$set('showEdit', false)">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($this->canDelete())
        <div class="modal-backdrop" x-data x-show="$wire.confirmingDelete !== null" x-cloak @keydown.escape.window="$wire.confirmingDelete = null" style="display:none;">
            <div class="modal-card" style="max-width:420px;" @click.outside="$wire.confirmingDelete = null">
                <div class="modal-head"><h3 class="modal-title">Confirm delete</h3></div>
                <div class="modal-body"><p class="mb-0">Are you sure? This cannot be undone.</p></div>
                <div class="modal-foot">
                    <button class="btn btn-ghost" wire:click="$set('confirmingDelete', null)">Cancel</button>
                    <button class="btn btn-danger" wire:click="deleteConfirmed">Delete</button>
                </div>
            </div>
        </div>
    @endif
</div>
