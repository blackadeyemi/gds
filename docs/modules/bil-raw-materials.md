# BIL — Raw Materials

The consumables BIL uses to convert paper into finished goods — cores, wrappers,
glue, packaging and the like (the **hardroll reels** are a separate module,
[Jumbo Rolls](bil-jumbo-rolls.md)). This module tracks each material from the
supplier, through the warehouse, onto the factory floor, and into consumption —
with stock derived from those movements, never typed directly.

- **Connection:** `bil` (shared live with the legacy app).
- **Base classes:** masters are [DataGrids](framework-building-blocks.md);
  reports extend `RawMaterialReport`; movement screens are bespoke Livewire.
- **Gates & stock:** see [Warehouses & gates](framework-warehouses-and-gates.md).
- **Shift gating:** factory-floor screens are [shift](framework-shifts.md)-gated.

---

## The flow

```
Suppliers ─► Supplier Deliveries ─► Warehouse Entry ─► (store)
                                         │  Stock Transfer (store → store)
                                         ▼
                                    Warehouse Exit ─► Factory Entrance ─► Consumption
                                                          ▲                    │
                                        Factory Returns ──┘        Damaged Goods (write-off)
```

Each step is one screen; stock at the warehouse and on the factory floor is the
running total of these movements.

## Masters

- **Products** (`bil.raw-materials.products`) — the material catalogue. CRUD grid.
- **Suppliers** (`bil.raw-materials.suppliers`) — who supplies them. CRUD grid.

## Movement screens

All are entry forms (access *is* the create action) and most carry **backdate**
so a delivery or a floor movement can be entered the morning after.

- **Supplier Deliveries** (`…supplier-deliveries`, backdate) — a delivery lands
  at the company. Records what arrived against a supplier, before it is put away.
- **Warehouse Entry** (`…warehouse-entry`, backdate, **warehouse gates**) — puts
  received material into a store through an inbound gate.
- **Warehouse Exit** (`…warehouse-exit`, backdate, **warehouse gates**) — releases
  material from a store.
- **Stock Transfer** (`…stock-transfer`, backdate) — moves material between
  stores; the destination decides where it lands.
- **Factory Entrance** (`…factory-entrance`, backdate, **bypass-shift**,
  **factory gates**) — material crosses onto the factory floor. Shift-gated: the
  entry belongs to the Day or Night window it was made in, unless a user with
  *bypass-shift* overrides.
- **Consumption** (`…consumption`, backdate, bypass-shift) — material is used up
  in conversion, off the floor.
- **Factory Returns** (`…factory-returns`, backdate, **approve**, warehouse
  gates) — unused material goes back to a store. *Approve* is a second pair of
  eyes before the stock is credited back.
- **Damaged Goods** (`…damaged-goods`, backdate, approve) — a write-off; material
  that can no longer be used, approved before it leaves the books.

## Stock (derived)

- **Warehouse Stock** — what each store holds, computed from entries, exits,
  transfers and returns. View + edit (an edit records an *adjustment*, it does
  not rewrite history) + export. Rebuilt by `bil:reconcile-warehouse-stock`.
- **Factory Floor Stock** — material on the floor but not yet consumed. Read-only
  (a view over the movement tables), view + export.

## Reports

Nine reports (`bil.raw-materials.reports.*`), all extending `RawMaterialReport`
(date range, searchable filters, summary + detail views, totals footer, export
and print — see [Building blocks](framework-building-blocks.md)):

`supplier-deliveries`, `warehouse-entry`, `warehouse-exit`, `factory-entrance`,
`consumption`, `factory-returns`, `damaged-goods` (full reports: view/edit/delete/
export), and `warehouse-stock`, `factory-floor-stock` (snapshots: view/export).

## Statistics

`bil.raw-materials.statistics` — the analytics dashboard
([Statistics framework](framework-building-blocks.md#statistics)) for this module:
deliveries, consumption and stock trends, rendered with Chart.js.

---

### Traps

- **Stock is never typed.** Every stock figure is the sum of movements. If a
  figure looks wrong, the movement that is wrong is the thing to fix — correct it
  on its own screen; don't "adjust" the stock to paper over it.
- **Legacy dates are strings.** Some legacy date columns are `varchar` in
  `Y/m/d` or `d/m/y`; check a column's type before filtering a range.
- **The legacy app still writes these tables.** Don't assume GDS is the only
  writer.
