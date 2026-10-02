# Framework — Warehouses & gates

Goods move through **gates** — doors on a warehouse or a factory floor — and a
user may only use the gates they have been granted. Stock is the running total of
those movements, never a typed figure.

---

## The model (all on `core`)

```
Warehouse ──has──► Warehouse Gates (inbound / outbound)
Factory   ──has──► Factory Gates   (inbound / outbound)
User      ──granted──► specific gates   (GateAccess)
```

- **Warehouses** hold goods; BIL sales depots are folded into these same core
  warehouses. Edited under [Admin](core-admin-and-settings.md).
- **Gates** are the inbound/outbound doors. A movement screen picks a gate, not a
  warehouse — the gate implies the warehouse and the direction.
- **Per-user grants.** `GateAccess` decides which gates a user may pick. The
  `'gates' => 'warehouse'|'factory'` tag on a page (see
  [Access control](framework-rbac.md)) only tells the **user editor** to offer the
  checklist; the grant is what actually limits the dropdown.

## Stock is derived

Every stock figure — warehouse stock, factory-floor stock, roll positions — is
computed from the movement rows (entries, exits, transfers, returns, consumption).
Screens labelled *Stock* are read-outs over those movements. To fix a wrong stock
figure, fix the wrong *movement*; adjust-in-place exists only as an audited
exception (`edit` on a stock page), and BIL raw-material stock can be rebuilt from
scratch with `bil:reconcile-warehouse-stock`.

---

### Traps

- **MyISAM has no transactions.** Some legacy tables are MyISAM, so a multi-row
  movement is **not** atomic there — a half-written movement can't be rolled back
  by the engine. Write defensively and verify, don't assume a transaction saved
  you.
- **A gate implies a warehouse + direction.** Don't store the warehouse
  separately from the gate and let them drift; the gate is the source of truth.
- **Factory gates ≠ warehouse gates.** They are separate registries
  (`admin.factory_gates` vs `admin.warehouse_gates`) for separate structures.
