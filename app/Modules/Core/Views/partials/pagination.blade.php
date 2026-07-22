@if ($paginator->hasPages())
    <nav class="pagination" role="navigation">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="disabled"><span>&lsaquo;</span></span>
        @else
            <a href="#" wire:click.prevent="previousPage" rel="prev">&lsaquo;</a>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="disabled"><span>{{ $element }}</span></span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="active"><span>{{ $page }}</span></span>
                    @else
                        <a href="#" wire:click.prevent="gotoPage({{ $page }})">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="#" wire:click.prevent="nextPage" rel="next">&rsaquo;</a>
        @else
            <span class="disabled"><span>&rsaquo;</span></span>
        @endif
    </nav>
@endif
