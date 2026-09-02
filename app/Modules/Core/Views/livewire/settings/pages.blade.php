<div>
    <div class="page-head">
        <h1>Pages</h1>
        <p>Every gated page and the abilities it offers. New pages declared in code appear here automatically; tick the abilities each page should expose, then Save. These drive what's grantable in the Role editor.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif

    {{--
        Sticky headers, in two layers: the ability columns, and the module the
        rows below belong to. Seventy-five pages over thirteen modules is a lot
        of scrolling, and without them you lose both which column a checkbox is
        in and which module you are in.

        It needs its OWN scroller. `.table-wrap` sets `overflow-x:auto`, which
        makes `overflow-y` compute to `auto` as well — so a sticky cell inside
        it sticks to that box rather than the page, and since the box has no
        height it never scrolls and sticky does nothing. Giving the scroller a
        height makes it the thing that scrolls, and the header sticks to it.

        The thead has an explicit height so the module row's offset is exact —
        derived from padding it would drift by a pixel and show a sliver of the
        scrolled rows between the two layers.
    --}}
    <style>
        .pages-scroll { overflow: auto; max-height: calc(100vh - 19rem); }
        .pages-scroll table.data thead th {
            position: sticky; top: 0; z-index: 3;
            height: 42px; padding-top: 0; padding-bottom: 0;
            background: var(--surface);
        }
        .pages-scroll tr.module-row td {
            position: sticky; top: 42px; z-index: 2;
            background: var(--hover, #f6f7f9);
            font-weight: 700; font-size: 0.7rem; text-transform: uppercase;
            letter-spacing: .05em; color: var(--muted);
        }
        /* Both layers are opaque, so a border rather than a shadow is enough to
           part them from the rows sliding underneath. */
        .pages-scroll table.data thead th { border-bottom: 1px solid var(--line); }
    </style>

    <form wire:submit="save">
        <div class="card">
            <div class="pages-scroll">
                <table class="data" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Page</th>
                            @foreach ($columns as $ability => $label)
                                <th style="text-align:center;font-size:0.72rem;white-space:nowrap;">{{ $label }}</th>
                            @endforeach
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $module => $pages)
                            <tr class="module-row">
                                <td colspan="{{ count($columns) + 2 }}">{{ $module }}</td>
                            </tr>
                            @foreach ($pages as $page)
                                <tr wire:key="page-{{ $page->id }}">
                                    <td style="white-space:nowrap;">
                                        {{ $page->label }}
                                        <div class="text-muted" style="font-family:monospace;font-size:0.72rem;">{{ $page->key }}</div>
                                    </td>
                                    @foreach ($columns as $ability => $label)
                                        <td style="text-align:center;">
                                            <input type="checkbox" value="{{ $ability }}" wire:model="abilities.{{ $page->id }}"
                                                   @if ($ability === 'view') title="Access — required to reach the page" @endif>
                                        </td>
                                    @endforeach
                                    <td style="text-align:center;">
                                        <button type="button" class="btn btn-ghost btn-icon btn-sm" wire:click="resetPage({{ $page->id }})" title="Reset to code defaults">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td colspan="{{ count($columns) + 2 }}" class="empty-row text-muted">No pages registered. Run <code>php artisan gds:sync-pages</code>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-head" style="border-top:1px solid var(--line);border-bottom:0;justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Save abilities</button>
            </div>
        </div>
    </form>
</div>
