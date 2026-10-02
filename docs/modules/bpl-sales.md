# BPL — Sales

Belpapyrus's **export** sales desk: take an order for paper, raise its proforma
invoice, build the packing list(s) as containers ship, and track the order end to
end. The physical side (delivery, weighbridge, bill of lading) is
[Logistics](bpl-logistics.md); the money side (payments, receivables) is
[Finance](bpl-finance.md).

- **Connection:** `bpl`.
- **Documents:** the Proforma Invoice and Packing List are **printable** sheets
  with fixed, legally-worded layouts.

---

## The flow

```
Orders ─► Proforma Invoice ─► Packing List(s) ─► (Logistics: delivery → weighbridge → B/L)
  │          one per order       containers, as         (Finance: invoice payments)
  │          per-tonne price      they ship
  └─ Customers (master)          partial shipments → final invoice
```

## Pages

- **Customers** (`bpl.sales.customers`, CRUD) — export buyers; address pickers use
  `core.geo_*`.
- **Orders** (`bpl.sales.orders`, **delete**, backdate) — one screen to place,
  edit and withdraw. Each order has a **stored `orderno`** *and* a **generated
  reference**; once a proforma or packing list exists against it, parts of the
  order **lock**.
- **Proforma Invoices** (`bpl.sales.proformas`, CRUD) — **one per order**.
  Carries **per-tonne prices** and **per-container freight**. The printed sheet
  has fixed wording and a bank block (the beneficiary account the buyer pays to).
- **Packing Lists** (`bpl.sales.packing-lists`, CRUD) — the containers actually
  shipped, stored as a **containers JSON** with tare/gross weights. Supports
  **partial shipments** (an order ships over several lists) and rolls up into the
  final invoice. The list number uses a `:customerid` placeholder.

## Reports

Four read-outs (`bpl.sales.reports.*`, view/export): `orders`, `proformas`,
`shipments`, and **`order-trail`** — one order end to end, findable by **any of
the nine numbers** it carries (order no, reference, proforma, packing list,
B/L, NXP, …). Shipment value is computed **in SQL**; there are **no
cross-currency totals** (orders in different currencies are never summed).

## Statistics

`bpl.sales.statistics` — orders, proformas and shipment volumes.

---

### Traps

- **One proforma per order.** The uniqueness is relied on; don't allow a second.
- **Order locks cascade.** Once a proforma/packing list references an order,
  editing the order is restricted — by design.
- **The reused-customer-id test trap.** Tests that reuse a customer id across
  fixtures collide here; see the module notes (and
  [test fixtures by id](../../README.md#tests)).
- **No cross-currency sums.** Any report total is within one currency; never add
  across currencies.
- **B/L and Invoice Payments live elsewhere.** Bills of Lading are under
  [Logistics](bpl-logistics.md); Invoice Payments under [Finance](bpl-finance.md).
  Both read scanned documents from `BPL_BL_DOCS_PATH`.
