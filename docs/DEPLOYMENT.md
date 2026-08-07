# Deployment notes

Newest release first. Each section is a runbook: what changed, the order to do it
in, what to check afterwards, and how to get back if it goes wrong.

---

## 2026-08-07 — Encoding repair (`2026_08_07_100000_repair_legacy_mojibake`)

Notes pasted from Word showed up as `â€¢` where a bullet belonged. The legacy PHP
connected without setting a charset, so UTF-8 bytes landed in columns declared
`latin1`: right bytes, wrong label. Any client that connects as `utf8mb4` — GDS,
**and the legacy app as it runs today** — then expands each byte into its own
character. This predates GDS; the legacy screens render it identically.

The migration does two things:

- **`bil.factory_machine_maintenance.note`** (2,514 of 43,401 rows) is
  re-declared `utf8mb4` by round-tripping the column through `BLOB`. Not one row
  is rewritten — the bytes were always right. The `BLOB → TEXT` step doubles as
  the guard: MySQL refuses it if any row isn't valid UTF-8, which is exactly when
  a blanket relabel would corrupt data. **Run it on a table nobody is writing
  to**; it takes ~2s on 17.5 MB.
- **Seven rows** in `sales_customers`, `sales_loading` and `bpl_customers` are
  rewritten byte-for-byte from pairs recorded in the migration. Those columns
  keep their charset — they're short varchars that legacy queries group and join
  on, and widening the collation risks *illegal mix of collations*. Each write is
  conditional on the row still holding the damaged bytes, so it is idempotent and
  `down()` is exact.

Before running, take a byte-faithful backup — `mysqldump --default-character-set=binary
--hex-blob` — of `factory_machine_maintenance`, `sales_customers`, `sales_loading`
and `bpl.bpl_customers`. A normal dump re-encodes and is useless as a reference here.

Verify with `scripts/verify_mojibake.php`. Run it once **before** the migration
with `--snapshot` (records the damaged rows' bytes and per-column checksums into
`storage/app/`), then again after to confirm the bytes were preserved, the
mojibake is gone and the untouched rows still hash identically. It passed
locally, including a full rollback-and-reapply.

**Two things left for someone to decide:**

- `bpl_customers` id 131 is still `SociÃ©tÃ© HygiÃ¨ne Plus Gabon`. Repairing it
  collides with the UNIQUE index: id 128 is `SOCIETE HYGIENE PLUS GABON` at the
  same address, and `utf8mb3_general_ci` treats `e` and `é` as equal. They are two
  records for one company, **both in active use** (230 and 42 `bpl_production`
  rows). Merging them is a business decision; the migration reports the skip and
  moves on.
- The 4 `sales_loading.truckdriver` rows lost a decorative siren emoji (U+1F6A8
  has no cp1252 form). They now read `POLICE`, matching the 47 rows that already
  did. `down()` cannot bring the emoji back.

New writes were never at risk: GDS declares `utf8mb4`, so MySQL converts properly
on the way in. Only the historic rows were damaged, and the damage stopped growing
once the legacy app started connecting as `utf8mb4`.

---

## 2026-08-05 — Machines hierarchy, Department → Division → Staff, Services

Large release. It changes the **shape of the shared data**, converts five legacy
`bil` tables into views, and adds columns to tables with up to 1.2M rows. It
spans **two codebases** (`gds` and the legacy `bil` PHP app) and **three
schemas** (`core`, `bil`, `bpl`), so the two deploys have to go out together.

> **Read the two red flags below before scheduling.**

### ⛔ Do before this ships

Not blockers for the data migration, but agreed to land before production:

**1. Make reports' data views configurable.** DataGrid pages read their enabled
views, default view and page size from `data_pages` / `data_views`, managed in
Settings → Data Views. Reports ignore all of it: `RawMaterialReport::mount()`
takes `array_key_first(views())` as the default and always renders every view.
So the choices made in code — Services leading with Summary (by project), page
size starting at 10 — are hardcoded, and changing either needs a deploy.

The fix is to give reports the same treatment as grids: register them where
`gds:sync-data-views` can see them, and have the report base consult `DataPage`
the way `Modules\Core\Livewire\DataGrid::config()` already does (per_page,
default view, enabled views). Roughly an hour, and it removes a standing class
of "can you change the default view / page size" requests. Do it before go-live
so admins can tune reports without a release.

**2. The legacy `bil` repo has no git remote.** It is a local-only repository, so
the legacy-side changes in step 3 of the deploy order below cannot be shipped
with `git pull`. Either add a remote and push it, or confirm how that app reaches
the server, before scheduling.

### 🔴 Red flag 1 — production backups may be silently broken

`scripts/db_backup.php` (in the `bil` repo) aborts if **any** view in
`bil`/`bpl`/`core` is invalid, and it deletes its own partial file on failure.
On dev, `bil.bpl_products` had been pointing at a table renamed by the
2026-07-29 hardroll/softroll split, which meant **no backup had been written for
16 days** and nothing reported it.

**Before anything else**, on production:

```sql
-- Any row returned here will break mysqldump and therefore your backups.
SELECT CONCAT(table_schema,'.',table_name) AS invalid_view
FROM information_schema.views v
WHERE table_schema IN ('bil','bpl','core')
  AND NOT EXISTS (SELECT 1 FROM information_schema.columns c
                  WHERE c.table_schema=v.table_schema AND c.table_name=v.table_name);
```

If `bil.bpl_products` appears, apply `bil/_migration/fix_bil_bpl_products_view.sql`.
Then **run a real backup and confirm the `.sql.gz` exists and is non-trivial in
size** — do not proceed on the assumption that a backup exists.

Worth fixing separately: whatever schedules `db_backup.php` is not surfacing its
non-zero exit code.

### 🔴 Red flag 2 — this needs a maintenance window

`2026_08_05_140000_add_hierarchy_ids_to_consumer_tables` adds and backfills
columns on `factory_production` (**1.2M rows**) and `factory_usage_reel` (285k).
On dev it took **8m32s**, and MySQL holds the table during the ALTER. The legacy
app writes to these tables continuously, so run this with the legacy app down.

Observed dev timings (total ≈ **10 minutes**):

| Migration | Time |
|---|---|
| `140000_add_hierarchy_ids_to_consumer_tables` | **8m 32s** |
| `150000_add_factory_id_to_bpl_production` (276k rows) | 31s |
| `200000_add_division_staff_ids_to_maintenance` (43k rows) | 37s |
| `230000_index_warehouse_exit_date_barcode` (310k rows) | allow a few minutes |
| all others combined | < 3s |

`230000` builds a covering index on `rawmaterials_warehouse_exit`; it is
idempotent (skips if the index exists) but locks the table while it builds, so it
belongs inside the same window.

That migration is written to be **resumable** — it skips columns that already
exist — so if it dies part-way you can re-run `php artisan migrate` rather than
restoring.

### What's in it

**New in `core`:** `factories`, `machine_lines`, `machine_projects`, `divisions`,
`staff`, `service_types`; `companies.code` (BIL/BPL/BOU); `user.division_id`.

**Now views over `core` (were real tables in `bil`):** `factory_lines`,
`factory_sublines`, `factory_projects`, `factory_subprojects`, `factory_details`,
`factory_staff` — definitions live in one place,
`app/Modules/Core/Support/LegacyFactoryViews.php`. The `bpl` mirrors of these are
recreated on top.

**New id columns + name→id triggers** on `factory_usage_rawmaterials`,
`factory_usage_reel`, `factory_production`, `factory_machine_maintenance`,
`factory_preproduction`, `factory_waste`, `bpl_production`,
`bpl_softroll_production`. The legacy app keeps writing names only; BEFORE
INSERT/UPDATE triggers resolve them to ids via the `bil.machine_map_*` lookup
tables. **Do not drop those lookup tables** — the triggers read them at runtime.

**New pages:** Admin → Factories / Divisions / Staff, Settings → Service Types,
BIL → Machines → Lines / Projects / Services, BIL → Machines → Reports → Services.
Users gain a Company → Department → Division cascade.

**Legacy screens retired** (views can't be written to). These become "moved to
GDS" stubs and their API routes are removed:
`lineitem.php`, `sublineitems.php`, `projects.php`, `subprojects.php`,
`factory_staff.php`. Legacy **reads** are untouched — `factory_machines.php`,
`includes/form.inc.php`, `js/machine.js` and the ten remaining
`Machinecontroller` read endpoints all still work.

### Deploy order

1. **Backup** — see red flag 1. Verify the file exists.
2. **Take the legacy `bil` app down** (maintenance page). It writes to the tables
   being altered.
3. **Deploy the `bil` repo** — retired page stubs, `includes/moved_to_gds.php`,
   the trimmed `app/private/routes/api.yaml`, `Machinecontroller`.
   Optionally set `GDS_URL` in `connections/.env` so the stubs link straight
   through to GDS.
4. **Deploy `gds`**, then `php artisan migrate --force`. Expect ~10 minutes.
5. `php artisan gds:sync-pages` and `php artisan gds:sync-data-views`.
6. **Grant the new pages** to the roles that need them (Role editor → page ×
   ability matrix). Admin bypasses; **every other role starts with no access** to
   the seven new pages.
7. Clear caches / reset OPcache per the environment's usual step.
8. Bring the legacy app back up.

### Verify afterwards

```sql
-- 1. The rebuilt views return the expected row counts.
SELECT (SELECT COUNT(*) FROM bil.factory_lines)       AS lines_,      -- 15
       (SELECT COUNT(*) FROM bil.factory_sublines)    AS sublines,    -- 35
       (SELECT COUNT(*) FROM bil.factory_projects)    AS projects,    -- 93
       (SELECT COUNT(*) FROM bil.factory_subprojects) AS subprojects, -- 30
       (SELECT COUNT(*) FROM bil.factory_details)     AS details,     -- 17
       (SELECT COUNT(*) FROM bil.factory_staff)       AS staff;       -- 70

-- 2. Backfill coverage (line_id should be 100%).
SELECT 'usage_rm' t, COUNT(*) n, SUM(line_id IS NOT NULL) ok FROM bil.factory_usage_rawmaterials
UNION ALL SELECT 'usage_reel', COUNT(*), SUM(line_id IS NOT NULL) FROM bil.factory_usage_reel
UNION ALL SELECT 'production', COUNT(*), SUM(line_id IS NOT NULL) FROM bil.factory_production
UNION ALL SELECT 'maintenance', COUNT(*), SUM(line_id IS NOT NULL) FROM bil.factory_machine_maintenance;

-- 3. Triggers fire. Should come back with every id populated; rolls back.
START TRANSACTION;
INSERT INTO bil.factory_machine_maintenance
  (jobtitle,jobid,linename,project,subproject,division,staff,user,date,starttime,endtime,note,duration)
VALUES ('DEPLOY CHECK','DC-1','REW 11','GAMBINI REWINDER 01','R11 UNW',
        'MAINTENANCE ELECTRICAL','IYERE EDWARD','deploy','2026/01/01','a','b','x','{}');
SELECT line_id, project_id, subproject_id, division_id, staff_id
FROM bil.factory_machine_maintenance ORDER BY id DESC LIMIT 1;
ROLLBACK;
```

Then in the app: open **BIL → Machines → Lines** (tree renders, sub-lines
indented), log one job on **Services** and confirm it appears in **Reports →
Services**; and in the legacy app open a machine report and
`report_factory_usage_rawmaterials.php` to confirm the figures are unchanged.

### Rollback

Every migration has a working `down()`, including rebuilding the five views back
into real tables from the `core` data. `php artisan migrate:rollback` in reverse
batch order will do it. **But** the pre-migration snapshots in
`bil/_migration/machines_baseline/*.tsv` are the authoritative reference for what
those tables held, and restoring the backup is the safer path if anything about
the data looks wrong rather than just the schema.

### Known gaps (deliberate, not defects)

- `factory_machine_maintenance.service_type_id` is **NULL for all 43,401
  historic rows**. The legacy screen never captured it, so classifying them would
  be a guess; they report as "Unclassified" until someone categorises them.
- `staff_id` resolves on 42,425 of 43,401 rows. The other 976 name 9 people who
  were deleted from `factory_staff` long ago; the `staff` text column still
  displays correctly.
- `factory_usage_rawmaterials.project` is the literal string `'machine'` in
  120,205 of 120,277 rows — a dead column. Only the 72 real values got a
  `project_id`.
- 186 `bpl_production` rows have a blank `papermachine` and so no `factory_id`.
- `bil.factory_hod` (4 rows) is untouched. It was the legacy head-of-department
  check that let a HOD edit entries in their own division; GDS gates by page
  ability instead. Now that users carry a division, mapping HOD onto a role is a
  sensible follow-up.

### Notes for whoever maintains this next

- **Staff resolve on `(division, name)`, never name alone.** `OTHERS` is a
  per-division placeholder that exists four times — a name-only join returns
  43,709 rows for a 43,401-row table. `core.staff.name` is deliberately not
  unique for this reason.
- **Don't collation-cast the compatibility views.** An explicit `COLLATE` makes
  the expression EXPLICIT (coercibility 0), and MySQL then refuses to join it
  against the `utf8mb3` consumer tables. The reasoning is written into
  `LegacyFactoryViews`.
- **Names in `machine_lines` / `machine_projects` are globally unique on
  purpose** — the views project them back to the legacy app, which still joins on
  them.
