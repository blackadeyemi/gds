# BIL — Finished Goods

What conversion produces — the pallets and bundles of toilet, napkin, facial and
towel product — from the product's QC spec, through the factory floor, into the
finished-goods store, and on to another depot.

- **Connection:** `bil` (QC specs live in `bil`, **not** the old `bilfg`).
- **Pipeline:** conversion output → factory exit → warehouse entrance → stock.
- **QC photos:** served from `BIL_QC_PICS_PATH` (a shared folder in production —
  see [DEPLOYMENT](../DEPLOYMENT.md)).

---

## The pipeline

```
Conversion Output ─► Factory Exit ─► Warehouse Entrance ─► Warehouse Stock
   (pallets made        (leave floor      (into the FG          (live position)
    on the floor)        via gate)         store via gate)
        ▲
   Conversion Waste (the "run" gates output)          Stock Transfer ─► Receive Transfer
                                                        (depot → depot)    (at the far end)
```

## Products — the QC spec

**Products** (`bil.finished-goods.products`, CRUD) is more than a catalogue: each
product carries its **QC specification** (grade-type composition — how many plies
of which grade, GSM, sheet counts, pack configuration) and a **revision history**,
because a spec changes over time and old pallets were made to the old spec. A
pallet's weights are read *from* the spec, which is why exit rows have no editable
weight. Product photos come from the shared QC image folder.

## Production → store

- **Conversion Output** (`…conversion-output`, backdate, **bypass-shift**) —
  pallets coming off conversion. Gated by the **run** (see Conversion Waste).
- **Conversion Waste** (`…conversion-waste`, **confirm**, **reopen**,
  **bypass-waste-lock**, bypass-shift) — waste for a *run* (line + date + shift +
  product). Confirming a run's waste locks it; *reopen* unlocks it; *bypass-waste-lock*
  lets output continue when a run cannot yet be closed. There is a
  `WASTE_CONFIRMATION_START` cut-over before which old data isn't held to the rule.
- **Factory Exit** (`…factory-exit`, entry, backdate, **factory gates**) — pallets
  leave the factory floor through a gate.
- **Warehouse Entrance** (`…warehouse-entrance`, entry, backdate, **warehouse
  gates**) — pallets are received into the finished-goods store.
- **Warehouse Stock** (`…warehouse-stock`, view/**edit**/export) — the live store
  position; an edit records an adjustment.

## Moving between depots

- **Stock Transfer** (`…stock-transfer`, backdate) — send pallets to another
  depot; the destination decides the kind, there is no second screen.
- **Receive Transfer** (`…stock-transfer.receive`, **approve**, **cancel**) — the
  far end accepts (approve) or rejects (cancel) the incoming transfer.

## Reports

- Full (view/delete/export) — `conversion-output`, `factory-exit`,
  `warehouse-entrance`. **Delete is a stock permission here**: deleting a
  warehouse-entrance receipt also takes its bundles back out of stock.
- Snapshots (view/export) — `factory-floor-stock` (pallets made but not yet sent
  on), `stock-transfer`, `conversion-waste`.

## Statistics

`bil.finished-goods.statistics` — output, waste and stock trends.

---

### Traps

- **`bil`, not `bilfg`.** QC specs moved to the `bil` DB; the old `bilfg`
  database is not where this lives.
- **A pallet's weight is the spec's, not the scan's.** Exit/entrance rows have no
  editable weight — fix the spec or delete-and-remake the row.
- **The run gates output.** Conversion Output for a line/shift/product depends on
  its Conversion Waste run; a missing or locked run changes what can be entered.
- **Deleting a report row can move stock.** On the full reports, *delete* reverses
  the movement, it is not a cosmetic tidy-up.
