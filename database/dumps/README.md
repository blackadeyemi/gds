# Database snapshots

Committable snapshots of the four gds databases, produced by
`scripts/export_db_snapshot.php`. **Full schema** for every table/view/trigger;
**data trimmed** so the dump stays small:

- Reference/lookup tables (customers, products, suppliers, warehouses, roles,
  geo…) — **all** rows.
- Transactional / "entry" tables (factory exit/conversion, store entrance, sales
  loading/orders, receipts, stock history…) — **only rows from 2025-01-01 on**
  (by each table's unix `timestamp`/`time`, or its `created_at`/date column; the
  one large child table, `sales_order_details`, is trimmed to the orders kept).

Files (gzipped): `core.sql.gz`, `bil.sql.gz`, `bpl.sql.gz`, `bhn.sql.gz`.

## Regenerate

```
php scripts/export_db_snapshot.php
```

Edit the cutoff / thresholds / per-table overrides at the top of that script.

## Restore

The schemas cross-reference each other (bil/bhn compat views read `bpl`/`core`),
so create all four and restore **in this order** so those references resolve:

```sql
CREATE DATABASE core; CREATE DATABASE bpl; CREATE DATABASE bil; CREATE DATABASE bhn;
```
```
gzip -dc core.sql.gz | mysql core
gzip -dc bpl.sql.gz  | mysql bpl
gzip -dc bil.sql.gz  | mysql bil
gzip -dc bhn.sql.gz  | mysql bhn
```

Each dump sets `FOREIGN_KEY_CHECKS=0` for the load. Because transactional data
starts at 2025, some rows reference parents dropped by the cutoff (e.g. a 2025
loading against a 2024 order) — expected for a trimmed snapshot, not a full
backup.

> Contains real operational data. Keep the repository private.
