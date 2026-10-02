# BIL — Sales

The finished-goods sales desk: place an order, load it onto a truck from the
warehouse, deliver it, raise its waybill, and handle anything returned. Stock
leaves BIL here.

- **Connection:** `bil` (sales depots are folded into the `core` warehouses).
- **Gates:** Loading and Delivery pick a **warehouse gate**.

---

## The flow

```
Orders ─► Loading ─► Delivery ─► Waybill
  │         │           │
  │         │           └─ confirms the goods arrived
  │         └─ loads bundles onto a truck from the warehouse (takes stock)
  │
  └─ Returns  (rejected / returned stock comes back)
```

## Masters

- **Customers** (`bil.sales.customers`, CRUD) — who buys. Address pickers read the
  `core.geo_*` reference set.
- **Transporters** (`bil.sales.transporters`, CRUD) — the hauliers.

## Fulfilment screens

- **Orders** (`bil.sales.orders`, **delete**, backdate) — one screen places,
  edits and withdraws an order. There is no separate *create*: viewing *is*
  placing/editing, and *delete* is the withdrawal — **refused outright once
  anything has been loaded** against the order.
- **Loading** (`bil.sales.loading`, create/**modify**/**return**/backdate,
  **warehouse gates**) — load bundles onto a truck. Loading takes the stock. A
  load number is reused per day; a wrong loading is corrected here (which keeps
  the stock and the delivery it feeds straight).
- **Delivery** (`bil.sales.delivery`, **confirm**/delete, warehouse gates) —
  confirm the goods reached the customer. *delete* re-opens the delivery.
- **Returns** (`bil.sales.returns`, create/modify/delete) — stock the customer
  rejected or sent back; it lands where returned/rejected goods are held, not
  straight back into sellable stock.
- **Waybill** (`bil.sales.waybill`, create/modify/**delete**) — the haulage
  document. *delete* is separate from *modify* on purpose: **removing a waybill is
  the only thing that re-opens its delivery for undo.**

## Reports

Seven read-outs (`bil.sales.reports.*`, view/export — a wrong loading is fixed on
the Loading screen, not in a report): `order-trail` (everything that happened to
one order), `orders`, `loading`, `delivery`, `returns`, `waybill`, `damaged-goods`.
They are gated one at a time because they are not equally sensitive — **Waybill
carries haulage cost, which is not a depot's business.**

## Statistics

`bil.sales.statistics` — orders, deliveries and returns trends.

---

### Traps

- **`orderid` collation.** Order-number matching depends on the unique index on
  `orderid`; a collation mismatch silently breaks start-anchored search. The
  Order Trail report searches from the start of the number for this reason.
- **Load numbers repeat per day.** A load number is unique *within a day*, not
  globally — never join on it alone.
- **The double-confirm bug.** Confirming a delivery twice was a known legacy
  hazard; the write contracts here guard against it.
- **Where returned stock goes.** Rejected/returned bundles do not re-enter
  sellable stock automatically — they sit in the returned/rejected location.
- **Three figures the legacy got wrong** are corrected in these reports; don't
  "fix" them back to match the old app.
- **UI:** the customer/product pickers are comboboxes and the tables wrap — see
  the module notes before restyling.
