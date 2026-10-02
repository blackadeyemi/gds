# BPL — Finance

The money behind the paper: the bank accounts BPL is paid into, what customers
owe against their invoices (**receivables / AR**), and what BPL owes hauliers for
delivery (**waybill payments / AP**). Balances are **derived from receipts**, not
typed.

- **Connection:** `bpl`.
- **Feeds from:** [Sales](bpl-sales.md) (invoices) and [Logistics](bpl-logistics.md)
  (waybills).

---

## Two ledgers

```
Receivable (AR)                         Payable (AP)
customer owes on an invoice             BPL owes a haulier for a waybill
  Invoice Payments (receipts) ──┐         Waybill Payments (receipts) ──┐
  Receivables report (aged)  ◄──┘         Waybill Payments report    ◄──┘
```

## Masters

- **Banks** (`bpl.finance.banks`, CRUD) — the banks.
- **Accounts** (`bpl.finance.accounts`, CRUD) — bank accounts money is paid into.
- **Payment Terms** (`bpl.finance.payment-terms`, CRUD) — the terms offered.

## Receipts

- **Invoice Payments** (`bpl.finance.invoice-payments`, view/edit/delete/export) —
  **one row per shipment**; customer receipts against invoices. Writing a receipt
  **recalculates the invoice balance**, which is why the report is a read-out.
- **Waybill Payments** (`bpl.finance.waybill-payments`, CRUD) — payments to
  hauliers. `paid` / `balance` / `status` are **derived from the receipts**, not
  stored flags.

## Reports

Five read-outs (`bpl.finance.reports.*`, view/export):

- **Receivables** — the ledger, **aged**. Legacy ageing rule: **+1 day, and the
  Bill of Lading starts the clock** (not the invoice date).
- **Invoice Payments** / **Waybill Payments** — the receipt logs. Haulage figures
  come from the receipts, not a stored total.
- **Export Payments** — the legacy `bpl_export_payments` lens: every shipment by
  its **NXP number and bank**. A trade-finance view, not a debt-chasing one.
- **Bank Accounts** — accounts and their activity.

## Statistics

`bpl.finance.statistics` — receivables, receipts and ageing.

---

### Traps

- **Unique-index gotchas.** The bank+currency account key has to cope with **NULL
  account numbers** and **soft-deleted accounts** — a naive unique index breaks on
  both. There is a known **dangling bank #61** referenced by rows with no bank row.
- **The over-payment guard is dead code.** An old guard against paying more than
  owed no longer fires — don't rely on it; the balance is just recomputed.
- **Ageing starts at the B/L.** Don't age from the invoice date; the Bill of
  Lading date (+1 day) is the clock.
- **Balances are derived.** Never write `paid`/`balance`/`status` directly — add
  a receipt and let it recompute.
