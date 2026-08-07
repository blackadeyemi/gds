{{-- Pagination for the drill-down modal. The report's own pager is driven by
     WithPagination's `page`; this one has its own cursor (`detailPage`) so
     stepping through a group's records can't move the report underneath it. --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation">
        @if ($paginator->onFirstPage())
            <span class="disabled"><span>&lsaquo;</span></span>
        @else
            <a href="#" wire:click.prevent="detailGotoPage({{ $paginator->currentPage() - 1 }})" rel="prev">&lsaquo;</a>
        @endif

        @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
            @if ($page == $paginator->currentPage())
                <span class="active"><span>{{ $page }}</span></span>
            @else
                <a href="#" wire:click.prevent="detailGotoPage({{ $page }})">{{ $page }}</a>
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="#" wire:click.prevent="detailGotoPage({{ $paginator->currentPage() + 1 }})" rel="next">&rsaquo;</a>
        @else
            <span class="disabled"><span>&rsaquo;</span></span>
        @endif
    </nav>
@endif
