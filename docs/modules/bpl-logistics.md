# BPL — Logistics

How paper physically leaves Belpapyrus: load a truck and raise its waybill, weigh
it over the bridge, and (for export) issue the bill of lading. This is the
physical half of a [Sales](bpl-sales.md) shipment; the money half is
[Finance](bpl-finance.md).

- **Connection:** `bpl`.
- **Scanned docs:** bills of lading are read from `BPL_BL_DOCS_PATH` (a shared
  folder in production — see [DEPLOYMENT](../DEPLOYMENT.md)).

---

## The flow

```
Delivery ─► Weighbridge ─► Bills of Lading
  loads the truck,   weigh in / out,      the export document
  raises the waybill  variance check       (B/L number starts the
  (takes rolls off                          Finance ageing clock)
   the bay in the
   same transaction)
```

## Pages

- **Delivery** (`bpl.logistics.delivery`, view/**backdate**) — load a truck for a
  shipment. Raising the delivery **takes the rolls off the bay in the same
  transaction** and raises the waybill. The **150 kg** per-item weight check runs
  here.
- **Weighbridge** (`bpl.logistics.weighbridge`, view/edit/delete/export) —
  complete the waybill with weigh-in/weigh-out; the **variance** between expected
  and bridge weight is surfaced here.
- **Bills of Lading** (`bpl.logistics.bills-of-lading`, CRUD) — the export
  document, with its scanned copy from the shared folder.
- **Transporters** (`bpl.logistics.transporters`, CRUD) — the hauliers. **Moved
  out of Sales into Logistics**, where they belong.

## Reports

Two read-outs (`bpl.logistics.reports.*`, view/export): `deliveries` and
`bills-of-lading`.

## Statistics

`bpl.logistics.statistics` — deliveries, tonnage and weighbridge variance.

---

### Traps

- **Waybill numbering is historical.** The legacy app used **row ids** as waybill
  numbers; don't assume a clean sequence. Waybill dates print **yy-mm-dd**.
- **Delivery moves stock.** Raising a delivery is the transaction that removes
  rolls from the bay — a delete has to reverse that.
- **Weighbridge variance is expected.** Small differences between loaded and
  bridged weight are normal; the report shows the variance rather than hiding it.
- **The 2021 orphan pair.** A known pair of legacy rows is orphaned (delivery
  without its match); don't treat it as a regression.
- **DataGrid `getKey()`** on the shipping-doc grids needs the right key — see the
  module notes before changing the grid definition.
