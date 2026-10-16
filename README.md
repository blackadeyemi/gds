# GDS — Consumer Tissue Data System

The modern rebuild of Belimpex's factory systems: a Laravel + Livewire + Alpine
application replacing a long-running flat-PHP app, screen by screen.

The two apps **run side by side against the same MySQL tables** while the rebuild
proceeds. That single fact explains most of what looks unusual in this codebase,
and it is the thing to keep in mind before changing anything near the database.

---

## The companies, and why the vocabulary matters

| Code | Company | What it does |
| --- | --- | --- |
| BIL | Belimpex | **Converts** hardroll into finished goods (toilet, napkin, facial, towel…) |
| BPL | Belpapyrus | **Produces** the paper — hardroll and softroll |
| BHN | BHN (Abuja) | **Converts** like BIL, in Abuja; buys its jumbo rolls from BPL as a customer, like BIL |
| BOU | Boulos | — |

BIL converts; BPL produces. The BIL tables are named `*_conversion` for that
reason, and `bpl_production` keeps its name because it really is production.
Getting this backwards is the most common way to misread the schema.

**BHN runs the exact BIL screens.** Its Abuja system is a clone of the BIL
legacy codebase, so rather than duplicate the module, gds reuses the Bil module
with the `bil` connection rebound to `bhn` per request — see *Databases* and
`ResolveCompanyModule`. Everything below about BIL applies to BHN too.

---

## Databases

Four connections. `core`/`bil`/`bpl` share one MySQL server (the split legacy
`bilfg` DB); `bhn` is its own schema, local in dev and **homed to the Abuja
server in production** (set `BHN_DB_HOST`/`PORT`/… there):

| Connection | Holds |
| --- | --- |
| `core` | The app's own data — users, roles, pages, the Company → Factory → Line → Project machine hierarchy, and warehouses with the gates goods move through |
| `bil` | The legacy BIL schema, still shared live with the old app |
| `bpl` | The legacy BPL schema |
| `bhn` | BHN's schema (company 4, Abuja) — a **mirror of BIL's modern structure**, loaded from BHN's own legacy dump. Its cross-schema compat views read Lagos `bpl`/`core` (BHN is a BPL customer). Provisioning: `database/bhn/README.md` |

Cross-database references (a `bil` row pointing at a `core` factory) are **plain
indexed columns, not foreign keys** — they cannot be, and that is the existing
convention, not an oversight.

**Serving BHN:** a request under `/bhn` is routed by `ResolveCompanyModule`
(global web middleware), which rebinds the `bil` connection + `config('bil')` to
BHN's and sets `ModuleContext`. On a Livewire update (which POSTs to
`/livewire/update`, not `/bhn/…`) it reads the module from the component
snapshot path. The Bil models carry no company column — the connection *is* the
company boundary — so the reused screens operate on BHN's schema transparently.
Page keys stay literal `bil.*`; `User::canDo` normalises them to `bhn.*` under a
BHN request, and `config/pages.php` mirrors every BIL page as a BHN page. The
multi-server design (Lagos + Abuja) is in memory `gds-abuja-multiserver`.

### Working with the legacy schema

- **The legacy app still writes.** Assume any table without a compatibility
  shim is being written to right now by the other app.
- **Renames leave a compatibility VIEW behind.** A one-table `SELECT *` view is
  insertable and updatable, so legacy reads *and* writes keep working; composed
  views (`factory_lines`, `factory_details`) are read-only, which is why the
  legacy screens behind those were retired instead.
- **Triggers resolve names to ids.** Legacy writes names only; BEFORE
  INSERT/UPDATE triggers fill in `line_id`, `factory_id` and friends. Drop them
  when the legacy app dies.
- **Denormalised summary columns are load-bearing.** `products.mach` and
  `products.hardrollsource` are maintained alongside their structured
  replacements because legacy screens and years of archived revisions read them.
- Legacy date columns are often `varchar` in `Y/m/d` or `d/m/y`. Check before
  filtering a range.

---

## Layout

```
app/Modules/{Core,Bil,Bpl,Bhn}/ PSR-4 "Modules\", wired by ModuleServiceProvider
  Livewire/                     full-page Livewire components
  Models/                       each pins its $connection
  Views/                        namespaced core:: bil:: bpl:: bhn::
routes/{core,bil,bpl,bhn}.php   one file per module (bhn.php just requires bil.php)
config/pages.php                the page + ability registry (access control)
config/{bil,bhn}.php            per-company identity (company_code, jumbo customer…)
config/datagrid.php             every DataGrid, for the data-views sync
database/dumps/                 committable DB snapshots (schema + 2025+ data)
database/bhn/                   BHN provisioning SQL + how-to
docs/modules/                   what each module does, screen by screen
docs/DEPLOYMENT.md              release runbooks, newest first
deploy/pwa-autostart/           launch the installed PWA at Windows login
scripts/verify_*.php            standalone checks a release is verified with
scripts/export_db_snapshot.php  regenerate database/dumps/*.sql.gz
```

The Bhn module is thin: `routes/bhn.php` requires `routes/bil.php`, so every BIL
route is served under `/bhn` too (its screens, models and views are BIL's,
reused on the `bhn` connection — see *Databases*).

Currently 192 registered pages across BIL, BPL, BHN and Core (BHN mirrors BIL's).
**For what each module actually does and how its screens work, see
[docs/modules/](docs/modules/README.md).** This README is the architecture;
that is the feature guide.

### Two base classes do most of the work

- **`Modules\Core\Livewire\DataGrid`** — CRUD grids. A subclass declares
  `views()` (columns + query per view) and gets search, sort, pagination,
  switchable views, a create/edit modal, delete guards and Excel/CSV/PDF/print.
- **`…\RawMaterials\Reports\RawMaterialReport`** — reports. Date range,
  searchable filters, summary views, sortable headers, drill-down, export and
  print. Despite the namespace it is generic; the Machines and Finished Goods
  reports extend it too.

Reach for these before writing a page by hand.

---

## Access control

Per **page**, per **ability**. A page declares the abilities it supports
(`view`, `create`, `edit`, `delete`, `export`, plus specials like `backdate`,
`approve`, `bypass-shift`); a role is granted `{page.key}:{ability}` through a
matrix in the role editor. Admin (role `legacy_level` 1) bypasses.

Page keys mirror route names with `-` → `_`
(`bil.raw-materials.warehouse-entry` ↔ `bil.raw_materials.warehouse_entry`).

**Adding a page:** register it in `config/pages.php`, run `gds:sync-pages`, put
`page:{key}` middleware on the route, gate the nav with `@canPage` /
`@canPrefix`, then grant it. A new page is invisible to everyone but Admin until
someone grants it — this is the single most common "the deploy didn't work".

---

## Getting started

Requires PHP 8.3+, MySQL 8, Composer and Node. Laravel 13.

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# point CORE_/BIL_/BPL_/BHN_DB_DATABASE at your databases (BHN_DB_HOST/PORT too
# in production, where BHN is on the Abuja server), then:
php artisan migrate
php artisan gds:sync-pages
php artisan gds:sync-data-views
php artisan gds:sync-shift-contexts
```

`.env` settings worth knowing: `BIL_QC_PICS_PATH` (where QC product photos live —
see `docs/DEPLOYMENT.md`, it must point at the shared folder in production).

### Commands

| Command | Purpose |
| --- | --- |
| `gds:sync-pages` | Registry → `pages` table + `{key}:{ability}` permissions |
| `gds:sync-data-views` | DataGrid `views()` → admin-editable config |
| `gds:sync-shift-contexts` | Shift windows from `config/shifts.php` |
| `gds:migrate-legacy-auth` | Seed roles from legacy user levels |
| `gds:import-geo` | Country / state / city reference data into `core.geo_*` |
| `gds:refresh-legacy` | Load the monthly legacy dump → staging → operational tables. `--profile=legacy_refresh_bhn` loads BHN's single-dump; default is the per-file bilfg dump |
| `gds:rebuild-machine-maps` | Rebuild the legacy name → id resolver tables (every operational schema in `config/machine_maps.php`) |
| `gds:provision-bhn-rbac` | Mirror BIL roles → BHN, assign BHN users by legacy level, seed gate grants |
| `bil:reconcile-warehouse-stock` | Rebuild the stock aggregate from barcodes (add `--module=bhn` for BHN) |

### Database snapshots

`scripts/export_db_snapshot.php` writes `database/dumps/{core,bil,bpl,bhn}.sql.gz`
— full schema for every table, but transactional ("entry") tables trimmed to
2025-onward so the set stays small (~38 MB vs ~3 GB). Reference tables are kept
whole. Regenerate by running the script; restore order and details are in
`database/dumps/README.md`. These are a committable seed, **not** a full backup,
and they hold real data — keep the repository private.

### Reference data: countries, states, cities

Address pickers read `core.geo_countries` / `geo_states` / `geo_cities`, filled
by `php artisan gds:import-geo --download` (250 countries, 5,308 states,
152,970 cities). It is idempotent — re-run it to take a newer export. The legacy
`countries` and `states` tables are left alone; the old app still reads them.

> Data by [Countries States Cities Database](https://github.com/dr5hn/countries-states-cities-database) — ODbL v1.0.

### Tests

```bash
php artisan test
```

Note `phpunit.xml` points the *default* connection at in-memory SQLite; the
`core`/`bil`/`bpl` connections still resolve to MySQL, so tests that touch module
data need `config(['database.default' => 'core'])`.

---

## Before you ship

Read `docs/DEPLOYMENT.md`. Each release is a runbook — what changed, the order to
apply it in, what to verify, and how to get back. Add an entry when you ship
anything with a migration, a new page, an env var or a shared-base change.
