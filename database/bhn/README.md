# Provisioning the `bhn` schema

BHN (Abuja, core.companies id 4) runs a clone of the BIL legacy codebase, so its
schema is BIL-shaped. gds mirrors BIL's **modern** structure into a separate
`bhn` schema and loads BHN's own data into it. BHN is homed to the Abuja server
in production; locally it's the same MySQL server as core/bil/bpl.

## One-time build

1. **Create the schema** (utf8mb4):
   ```sql
   CREATE DATABASE `bhn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Mirror BIL's modern structure** (tables + views + triggers, no data):
   ```
   mysqldump --no-data --routines --triggers --skip-add-drop-table bil | mysql bhn
   ```
   BIL's view/trigger bodies reference their own tables by bare name, so they
   self-resolve to `bhn` once loaded. Cross-schema references stay pointed at
   Lagos: `core.*` (shared) and `bpl.*` (BHN is a BPL customer, like BIL, and
   receives its jumbo rolls from BPL — 49 `bpl` compat views come across and
   read Lagos BPL).

3. **Apply BHN schema tweaks** — see `schema_tweaks.sql`:
   ```
   mysql bhn < database/bhn/schema_tweaks.sql
   ```

4. **Load BHN's data** from its legacy dump (single phpMyAdmin `bhn.sql`):
   ```
   php artisan gds:refresh-legacy --profile=legacy_refresh_bhn --dry-run
   php artisan gds:refresh-legacy --profile=legacy_refresh_bhn --force
   ```
   The profile (`config/legacy_refresh_bhn.php`) loads the dump into a staging
   schema, **prunes it to company 2 (BHN)** via `prune_stage_company2.sql` (the
   Abuja DB is shared with BIL ABUJA = company 1), then TRUNCATE + INSERT
   (common columns) each mapped table into `bhn`.

## Monthly refresh

Drop a fresh `bhn.sql` at the configured `dump_file` and re-run step 4's
`--force`. Then the downstream re-derivation (stock reconcile, mojibake repair)
— tracked as TODO for BHN; the BIL reconcile commands are bil-connection-bound
and need a BHN equivalent / `--connection` option.

## Still outstanding (not schema)

- **core entities for BHN** (company 4): machine hierarchy (so the factory/line
  triggers can derive `factory_id`/`line_id` — currently left NULL), warehouses
  + gates, and migrating the 40 legacy `user` rows into `core.users`.
- **Module wiring**: the Bil module serves `/bhn` with the connection swapped to
  `bhn` per company context (reuse, not duplication).
