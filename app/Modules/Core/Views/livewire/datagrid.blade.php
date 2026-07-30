<div>
    <div class="page-head">
        <h1>{{ $this->pageLabel() }}</h1>
        @if ($this->pageSubtitle())
            <p>{{ $this->pageSubtitle() }}</p>
        @endif
    </div>

    @if ($this->headerView())
        @include($this->headerView())
    @endif

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <div class="card">
        <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;">
            <div class="flex items-center gap-2" style="flex-wrap:wrap;">
                {{-- View switcher (only if >1 enabled view) --}}
                @if (count($viewsList) > 1)
                    <select class="form-control" style="width:auto;min-width:200px;" wire:model.live="view">
                        @foreach ($viewsList as $v)
                            <option value="{{ $v['key'] }}">{{ $v['label'] }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Page size --}}
                <div class="flex items-center gap-2 text-sm text-muted">
                    <span>Show</span>
                    <select class="form-control" style="width:auto;padding-top:0.35em;padding-bottom:0.35em;" wire:model.live="perPage">
                        @foreach ($perPageOptions as $opt)
                            <option value="{{ $opt }}">{{ $opt }}</option>
                        @endforeach
                    </select>
                    <span>entries</span>
                </div>
            </div>

            <div class="flex items-center gap-2 ml-auto">
                <div class="search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="text" class="form-control" placeholder="Search…" wire:model.live.debounce.300ms="search">
                </div>

                @if ($this->editable() && $this->mayDo('create'))
                    <button class="btn btn-primary btn-sm" wire:click="create">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        New
                    </button>
                @endif

                {{-- 3-dots: export + print --}}
                <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
                    <button class="btn btn-ghost btn-icon btn-sm" @click="open = !open" title="More">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                    </button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition @click="open = false">
                        <button class="dropdown-item" wire:click="export('xlsx')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                            Export Excel (.xlsx)
                        </button>
                        <button class="dropdown-item" wire:click="export('csv')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            Export CSV
                        </button>
                        <button class="dropdown-item" wire:click="export('pdf')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 18h6M9 12h2"/></svg>
                            Export PDF
                        </button>
                        <div class="dropdown-sep"></div>
                        <a class="dropdown-item" target="_blank"
                           href="{{ url('/admin/grid/' . $this->pageKey() . '/print') }}?view={{ $gridView['key'] }}&search={{ urlencode($this->search) }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                            Print
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:60px">#</th>
                        @foreach ($columns as $col)
                            @php $sortable = ($gridView['type'] ?? 'table') === 'table' && in_array($col[1], $gridView['sortable'] ?? []); @endphp
                            <th @class(['sortable' => $sortable]) @if($sortable) wire:click="sortBy('{{ $col[1] }}')" @endif>
                                {{ $col[0] }}
                                @if ($sortable) @include('core::partials.sort-caret', ['field' => $col[1]]) @endif
                            </th>
                        @endforeach
                        @if ($this->rowActionsVisible() && ($gridView['type'] ?? 'table') === 'table')
                            <th class="col-actions">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $i => $row)
                        <tr wire:key="row-{{ $gridView['key'] }}-{{ $row->id ?? $i }}">
                            <td>{{ $rows->firstItem() + $i }}</td>
                            @foreach ($columns as $col)
                                <td>{!! isset($col[2]) && is_callable($col[2]) ? $col[2]($row) : e(data_get($row, $col[1]) ?? '—') !!}</td>
                            @endforeach
                            @if ($this->rowActionsVisible() && ($gridView['type'] ?? 'table') === 'table')
                                <td class="col-actions">
                                    @if ($this->mayDo('edit'))
                                    <button class="btn btn-ghost btn-icon btn-sm" wire:click="edit({{ $row->getKey() }})" title="Edit">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    @endif
                                    @if ($this->mayDo('delete'))
                                    @php $blockReason = $this->deleteGuard($row); @endphp
                                    @if ($blockReason)
                                        <button class="btn btn-danger btn-icon btn-sm" disabled title="{{ $blockReason }}" style="opacity:.35;cursor:not-allowed;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                        </button>
                                    @else
                                        <button class="btn btn-danger btn-icon btn-sm" wire:click="$set('confirmingDelete', {{ $row->getKey() }})" title="Delete">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                        </button>
                                    @endif
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) + ($this->rowActionsVisible() ? 2 : 1) }}" class="empty-row">No records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-head" style="border-top:1px solid var(--line);border-bottom:0;">
            <span class="text-muted text-sm">
                @if ($rows->total())
                    Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}
                @endif
            </span>
            {{ $rows->links('core::partials.pagination') }}
        </div>
    </div>

    {{-- Create / Edit modal (editable grids) --}}
    @if ($this->editable() && $this->formView())
        <div class="modal-backdrop" x-data x-show="$wire.showModal" x-cloak @keydown.escape.window="$wire.showModal = false" style="display:none;">
            <div class="modal-card" style="max-width:{{ $this->modalSize() }}" @click.outside="$wire.showModal = false">
                <div class="modal-head">
                    <h3 class="modal-title">{{ $editingId ? 'Edit' : 'New' }} {{ \Illuminate\Support\Str::singular($this->pageLabel()) }}</h3>
                    <button class="modal-close" wire:click="$set('showModal', false)">&times;</button>
                </div>
                <form wire:submit="save">
                    <div class="modal-body">
                        @include($this->formView())
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn btn-ghost" wire:click="$set('showModal', false)">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingId ? 'Save changes' : 'Create' }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop" x-data x-show="$wire.confirmingDelete !== null" x-cloak @keydown.escape.window="$wire.confirmingDelete = null" style="display:none;">
            <div class="modal-card" style="max-width:400px;" @click.outside="$wire.confirmingDelete = null">
                <div class="modal-head"><h3 class="modal-title">Confirm delete</h3></div>
                <div class="modal-body"><p class="mb-0">Are you sure? This cannot be undone.</p></div>
                <div class="modal-foot">
                    <button class="btn btn-ghost" wire:click="$set('confirmingDelete', null)">Cancel</button>
                    <button class="btn btn-danger" wire:click="deleteConfirmed">Delete</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Page-specific extra markup (e.g. the Roles permissions modal) --}}
    @if ($this->extraView())
        @include($this->extraView())
    @endif

    {{-- Shift window guard (blocks the page outside its active shift) --}}
    @include('core::partials.shift-guard')
</div>
