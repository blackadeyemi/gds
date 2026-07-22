<div>
    <div class="page-head">
        <h1>Data Views</h1>
        <p>Choose which views each page exposes, the default view, and the default page size.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif

    <form wire:submit="save">
        @forelse ($pages as $page)
            <div class="card" style="margin-bottom:1rem;">
                <div class="card-head">
                    <h2 class="card-title">{{ $page->label }}</h2>
                    <div class="flex items-center gap-2 text-sm text-muted">
                        <span>Default entries</span>
                        <select class="form-control" style="width:auto;padding:0.35em 0.6em;" wire:model="perPage.{{ $page->id }}">
                            @foreach ([10,25,50,100] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="card-pad">
                    <table class="data" style="width:100%;">
                        <thead>
                            <tr><th>View</th><th style="width:120px;text-align:center;">Enabled</th><th style="width:120px;text-align:center;">Default</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($page->views as $view)
                                <tr wire:key="view-{{ $view->id }}">
                                    <td>{{ $view->label }}</td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" wire:model="enabled.{{ $view->id }}">
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="radio" name="default-{{ $page->id }}" value="{{ $view->id }}" wire:model="defaultView.{{ $page->id }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="card card-pad text-muted">No data pages registered yet. Run <code>php artisan gds:sync-data-views</code>.</div>
        @endforelse

        @if (count($pages))
            <div class="flex" style="justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Save settings</button>
            </div>
        @endif
    </form>
</div>
