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

## Core entities (shared `core` schema, company 4)

Run after the data load (they populate `core`, then rebuild the resolver maps):

```
mysql core < database/bhn/seed_core_hierarchy.sql   # 1 factory + 14 lines
mysql core < database/bhn/seed_core_users.sql        # 30 BHN-specific staff
php artisan gds:rebuild-machine-maps                 # bil + bhn (config/machine_maps.php)
# then re-derive ids on the loaded rows (fires the BEFORE UPDATE triggers):
#   UPDATE bhn.factory_conversion        SET linename = linename;
#   UPDATE bhn.factory_usage_rawmaterials SET linename = linename;
#   UPDATE bhn.factory_usage_reel        SET linename = linename;
```

- **Hierarchy** — DONE. factory_id/line_id now resolve on all conversion/usage
  rows. Machine-level **projects** are NOT seeded (3 BHN machine names collide
  with core.machine_projects' globally-unique name); so `project_id` on
  factory_usage_rawmaterials stays NULL until that's disambiguated.
- **Users** — DONE. 30 BHN-only staff added (company 4). 10 usernames already
  existed in core.user (shared admin/management, mostly company NULL) and were
  left untouched — reconcile individually if any is a different BHN person.

## Still outstanding

- **Warehouses + gates** for company 4 (sales/store/FG screens group by them).
- **Stock reconcile + mojibake** for the bhn connection.
- **Module wiring**: the Bil module serves `/bhn` with the connection swapped to
  `bhn` per company context (reuse, not duplication).
