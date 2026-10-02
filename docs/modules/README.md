# GDS modules — the feature guide

What each module does and how its screens work. The root [README](../../README.md)
explains the *architecture* (databases, base classes, access control, setup);
this explains the *features*. [docs/DEPLOYMENT.md](../DEPLOYMENT.md) is the
release-by-release runbook.

Every page named here is gated — it has a key in [config/pages.php](../../config/pages.php)
and is invisible until a role is granted it. Page keys mirror route names with
`-` → `_` (e.g. `bil.raw-materials.warehouse-entry` ↔
`bil.raw_materials.warehouse_entry`).

---

## The business in one picture

```
   BPL (Belpapyrus)                         BIL (Belimpex)
   produces paper                           converts paper into finished goods
   ──────────────────                       ──────────────────────────────────
   paper machines                           jumbo rolls in  ─┐
     │ Production (hardroll / softroll)                      │ Factory Entrance
     │ Factory Exit                                          │ Consumption
     ▼                                                       ▼
   Warehouse (entry / exit / transfer)      Conversion  →  Finished Goods
     │                                         │             (pallets, bundles)
     ├─► BIL  (jumbo rolls BPL makes for BIL)  │ Factory Exit → Warehouse
     │                                         ▼
     └─► Sales / Export                      Sales  (orders → loading →
          Orders → Proforma → Packing →             delivery → waybill)
          Delivery → Weighbridge → Bill of Lading
          Finance (receivables, payments)
```

BIL **converts**; BPL **produces**. That is why BIL's tables are `*_conversion`
and BPL keeps `*_production`. Getting it backwards is the usual way to misread
the schema.

---

## BIL — Belimpex (converting)

| Module | What it covers |
| --- | --- |
| [Raw Materials](bil-raw-materials.md) | Products, suppliers and deliveries; warehouse in/out, stock transfer, factory entrance, consumption, returns, damaged goods; 9 reports + stats |
| [Jumbo Rolls](bil-jumbo-rolls.md) | The reels BPL makes for BIL — factory entrance, consumption, returns, live stock; the BPL→BIL handshake |
| [Finished Goods](bil-finished-goods.md) | Product QC specs; conversion output + waste; factory exit → warehouse entrance → stock; stock transfer between depots |
| [Sales](bil-sales.md) | Customers, transporters, orders; loading → delivery → waybill → returns; 7 reports + stats |
| [Machines](bil-machines.md) | The Company → Factory → Line → Project spine; conversion setup; services; Departments → Divisions → Staff |

## BPL — Belpapyrus (producing)

| Module | What it covers |
| --- | --- |
| [Jumbo Rolls](bpl-jumbo-rolls.md) | Grades, hardroll/softroll products, weight allowances; production → factory exit → warehouse (entry/exit/transfer/waiting area) → stock; the S-prefix barcode streams; 7 reports + roll trail |
| [Sales](bpl-sales.md) | Orders, proforma invoices, packing lists, customers; 4 reports + stats — the export sales desk |
| [Finance](bpl-finance.md) | Banks, accounts, payment terms; invoice & waybill payments; receivables and export-payment reports |
| [Logistics](bpl-logistics.md) | Delivery, weighbridge, bills of lading, transporters; deliveries + BoL reports — how paper leaves the gate |

## Core — the platform

| Area | What it covers |
| --- | --- |
| [Admin & Settings](core-admin-and-settings.md) | Users, roles, departments, companies, factories, warehouses, gates, divisions, staff; Pages/Data Views/Shifts/Service Types/Waste settings |

---

## Cross-cutting frameworks

The machinery every module is built on. Read these once; they explain patterns
you will meet in every module doc.

| Framework | What it is |
| --- | --- |
| [Access control (RBAC)](framework-rbac.md) | Per-page, per-ability permissions; the pages registry, `page:` middleware, `@canPage`/`@canPrefix`, `gds:sync-pages` |
| [Shifts](framework-shifts.md) | Dynamic per-area Day/Night windows that gate entry screens; bypass-shift |
| [Warehouses & gates](framework-warehouses-and-gates.md) | The core warehouse/gate model, per-user gate grants, and why stock is derived from movements |
| [Building blocks](framework-building-blocks.md) | The `DataGrid` and `RawMaterialReport` base classes and the Statistics dashboard — what you get for free |
| [Legacy refresh](framework-legacy-refresh.md) | The monthly production-dump pipeline that refreshes the legacy + GDS data tables |
| [Installed app (PWA)](framework-pwa.md) | Install, offline behaviour, the service worker, version, and launch-at-login deployment |

---

## Conventions used in these docs

- **Page** — a gated screen. Listed as **Label** (`route.name`) with its key
  abilities (view/create/edit/delete/export plus specials like backdate,
  approve, confirm, bypass-shift).
- **Connection** — which database a table lives on: `core`, `bil` or `bpl`.
- **Trap** — a non-obvious rule or legacy quirk that has bitten before; worth
  reading before you change code near it.
- **Reports** extend `RawMaterialReport`; **grids** extend `DataGrid`; both are
  described in [Building blocks](framework-building-blocks.md).
