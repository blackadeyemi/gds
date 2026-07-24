{{-- Prev/next-only pagination for count-free (simplePaginate) report views. --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation">
        @if ($paginator->onFirstPage())
            <span class="disabled"><span>&lsaquo;</span></span>
        @else
            <a href="#" wire:click.prevent="previousPage" rel="prev">&lsaquo;</a>
        @endif

        <span class="active"><span>{{ $paginator->currentPage() }}</span></span>

        @if ($paginator->hasMorePages())
            <a href="#" wire:click.prevent="nextPage" rel="next">&rsaquo;</a>
        @else
            <span class="disabled"><span>&rsaquo;</span></span>
        @endif
    </nav>
@endif
