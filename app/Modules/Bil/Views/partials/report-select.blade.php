{{--
    BIL report filter select.

    The implementation moved to `core::partials.filter-select` on 2026-09-16 so
    that Core DataGrids could use the same control; this stays as the name every
    BIL report already includes. `@include` passes the parent scope straight
    through, so $name / $label / $options / $width arrive unchanged.
--}}
@include('core::partials.filter-select')
