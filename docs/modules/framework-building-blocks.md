# Framework — Building blocks

Three reusable pieces do most of the UI work. Reach for them before writing a
page by hand — a new grid, report or chart is a subclass, not a rewrite.

---

## DataGrid — CRUD grids

`Modules\Core\Livewire\DataGrid`. A subclass declares `views()` (the columns and
query per view) and gets, for free: search, sort, pagination, switchable views, a
create/edit **modal form**, delete guards, and Excel / CSV / PDF / print export
(the print/PDF carries the company logo).

- **Registered in** [`config/datagrid.php`](../../config/datagrid.php); `php
  artisan gds:sync-data-views` publishes each grid's `views()` into the
  admin-editable **Settings → Data Views** so labels/columns can be tuned without
  a deploy.
- **Fast by design.** Forms are **lazy** (the modal's component loads on open) and
  counts are **join-free**, so every grid loads in well under 75 ms.
- **Trap — `@push`/`extraView`.** Pushing scripts/styles from a grid's extra view
  has a known ordering trap; follow the existing pattern rather than pushing ad
  hoc. And a grid whose rows aren't keyed by a simple `id` must override
  `getKey()` (bit the BPL shipping-doc grids).

## RawMaterialReport — reports

`…\RawMaterials\Reports\RawMaterialReport`. Despite the namespace it is **generic**
— every report in BIL and BPL extends it. A subclass declares its views; it gets:
a date range, searchable filters, summary **and** detail views, sortable headers,
drill-down, a **footer totals** row on figure columns, and export + print.

- **Pagination is range-aware.** `usesSimplePagination()` keeps a bounded range
  (≤ ~92 days) on numbered pages with a full count; a wide range switches to a
  count-free `simplePaginate`. Summary views paginate a fetched collection in PHP
  (no extra count query).
- **Export is capped** at `PDF_MAX_ROWS` so a runaway range can't melt the server.

## Statistics

`StatisticsPage` + **Chart.js** (`public/js/statistics.js`). A page declares its
charts **server-side** as plain specs; an Alpine component (`statChart`) renders
each on an `x-ref` canvas, re-initialising cleanly on Livewire SPA navigation. The
palette is theme-aware (single-hue for magnitude, a validated categorical palette
for identity). Every module has a `…statistics` page built this way.

- **Trap — horizontal bars.** An `hbar`'s value is on the **x** axis; reading `y`
  shows the category index (0, 1, 2…) — the tooltip reads the value axis opposite
  the index axis.

See also [DataViz](../../README.md) conventions when choosing chart types/colours.
