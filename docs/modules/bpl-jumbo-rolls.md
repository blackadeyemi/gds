# BPL — Jumbo Rolls

The heart of Belpapyrus: making paper on the machines and tracking every roll
from the reel-up, through the stores, until it leaves BPL — either **to BIL**
(the reels BPL makes for Belimpex) or **to Sales/export**. Paper comes in two
streams, **hardroll** and **softroll**, which are kept apart throughout.

- **Connection:** `bpl`.
- **Barcodes:** hardroll and softroll numbering used to collide; a softroll gets
  an **`S` prefix** and `RollResolver` disambiguates a scanned code (behind
  Factory Exit and Warehouse Entry). See Traps.
- **Gates & stock:** [Warehouses & gates](framework-warehouses-and-gates.md);
  stock is derived from the movement screens.

---

## The flow

```
Grades / Products / Weight Allowances        (masters)
        │
Production (hardroll · softroll) ─► Factory Exit ─► Warehouse Entry ─► (store)
   roll off the paper machine        leave the PM floor    into a BPL store
                                                              │
                                      Warehouse Transfer ◄────┤ (store → store)
                                      Waiting Area       ◄────┤ (quarantine, both ways)
                                                              ▼
                                                        Warehouse Exit ─► leaves BPL
                                                                            ├─► BIL
                                                                            └─► Sales / export
```

## Masters

- **Grades** (`bpl.jumbo-rolls.grades`, CRUD) — paper grades.
- **Products (Hardroll)** / **Products (Softroll)** (`…products.hardroll` /
  `…products.softroll`, CRUD) — the product catalogue, split by stream.
- **Weight Allowances** (`…weight-allowances`, CRUD) — the hardroll **core +
  wrapper deduction** rule: how much to subtract from a gross roll to get net
  paper. It is a *rule*, edited rarely and **audited** (history kept), so it sits
  with the masters, above the screen that consumes it.

## Production

- **Production (Hardroll)** / **Production (Softroll)** (`…production.hardroll` /
  `…production.softroll`, CRUD + **backdate**) — record a roll coming off the
  paper machine. CRUD because rows are corrected here until Factory Exit takes
  ownership; backdate because a shift that ran past midnight is entered the next
  morning. **The roll-label print routes ride on these keys** — a reprint needs
  the entry to be right.

## Store movements

- **Factory Exit** (`…factory-exit`, entry, **factory gates**) — rolls leave the
  paper-machine floor through an outbound gate.
- **Warehouse Entry** (`…warehouse-entry`, entry, **warehouse gates**) — received
  into a BPL store.
- **Warehouse Transfer** (`…warehouse-transfer`, entry, warehouse gates) — move
  stock between stores; the destination's inbound gate decides where it lands.
- **Waiting Area** (`…waiting-area`, view/backdate, warehouse gates) — the legacy
  "quarantine", **after** store exit: a holding area that can move stock **both
  ways** (out to the bay, or back into the store).
- **Warehouse Exit** (`…warehouse-exit`, entry, warehouse gates) — released from
  the store; this is a roll leaving BPL.
- **Warehouse Stock** (`…warehouse-stock`, view/export) — the live position,
  derived from the two movement screens.

## Reports

Seven read-outs + a trail (`bpl.jumbo-rolls.reports.*`, view/export):
`production`, `factory-exit`, `warehouse-entry`, `warehouse-exit`,
`warehouse-transfer`, `waiting-area`, and **`roll-trail`** — one row per roll
with every date in its life and the breaks in the chain (the legacy
`report_hardroll_movement`, generalised). A roll can leave BPL by **two doors**
(to BIL, or to Sales), and the reports account for both.

## Statistics

`bpl.jumbo-rolls.statistics` — production, exits and stock by stream.

---

### Traps

- **The `S`-prefix rule.** Softroll barcodes carry an `S`; `RollResolver` uses it
  to tell the streams apart when a code is scanned. Don't match a bare number
  across both streams.
- **Weight allowances: live vs code.** The deduction figures still have a
  live-vs-code reconciliation outstanding — confirm against the audited screen
  before trusting a computed net.
- **Warehouse Stock's fast definition** is only safe because an **anomalies
  view** exists to catch the cases it skips; don't "optimise" the stock query
  without understanding what the anomalies view covers.
- **latin1 barcodes bite performance.** A barcode column in `latin1` once made a
  report take 77 seconds — mind collation when joining on barcodes.
- **Waiting Area moves stock both ways** — it is not a one-way exit; a delete or
  edit there can add stock back.
