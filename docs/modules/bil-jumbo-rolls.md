# BIL — Jumbo Rolls

The hardroll **reels BPL makes for BIL**, tracked from the moment they arrive at
the Belimpex gate until they are consumed on a machine (or sent back). This is
BIL's view of the paper; BPL's side of the same rolls is
[BPL → Jumbo Rolls](bpl-jumbo-rolls.md).

- **Connection:** `bil`.
- **Gates & stock:** [Warehouses & gates](framework-warehouses-and-gates.md);
  stock is derived from the movement screens.
- **Shift gating:** the floor screens are [shift](framework-shifts.md)-gated.

---

## The flow

```
BPL ships reels ─► Factory Entrance ─► Consumption (on a machine)
                        │
                        └─► Returns (reel sent back to BPL)
                   Stock = entrance − consumption − returns  (live)
```

## Pages

- **Factory Entrance** (`bil.jumbo-rolls.factory-entrance`, backdate,
  **bypass-shift**, **factory gates**) — reels cross into BIL through a factory
  gate. This is the BPL→BIL handshake: the roll BPL released is received here,
  stamping its `received_at`.
- **Consumption** (`bil.jumbo-rolls.consumption`, backdate, bypass-shift) — a
  reel is mounted and consumed on a machine. Picks a **machine**, not a gate, so
  there is no gate checklist.
- **Returns** (`bil.jumbo-rolls.returns`, backdate, bypass-shift) — a reel goes
  back to BPL. Carries its own date of return, so the day the truck left is
  recorded, not the day it was typed.
- **Stock** (`bil.jumbo-rolls.stock`, view/export) — a live snapshot derived from
  the movement tables. Nothing to edit here. Shows what is on hand and **in-transit
  ageing** — reels released by BPL but not yet received at BIL.

## Reports

Three read-outs (`bil.jumbo-rolls.reports.*`, view/export): `factory-entrance`,
`consumption`, `returns`. The screens that write these rows own the corrections,
so the reports never edit or delete.

## Statistics

`bil.jumbo-rolls.statistics` — reel intake, consumption and stock trends.

---

### Traps

- **`customer_id 17`** is the sentinel BPL↔BIL counterparty on these rows; see
  the module notes before filtering by customer.
- **Reel slices** — a jumbo reel can be consumed in parts; a roll is not always
  one atomic unit.
- **In-transit is real stock.** A reel released by BPL but not yet received at
  BIL is neither side's floor stock but must be visible — the Stock screen ages
  it so a lost reel is noticed.
- **`received_at` is the handshake.** It is stamped at Factory Entrance, not when
  BPL ships; an un-received reel has a null `received_at`.
