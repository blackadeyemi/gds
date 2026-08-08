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
| BOU | Boulos | — |

BIL converts; BPL produces. The BIL tables are named `*_conversion` for that
reason, and `bpl_production` keeps its name because it really is production.
Getting this backwards is the most common way to misread the schema.

---

## Databases

Three connections, one MySQL server:

| Connection | Holds |
| --- | --- |
| `core` | The app's own data — users, roles, pages, and the Company → Factory → Line → Project machine hierarchy |
| `bil` | The legacy BIL schema, still shared live with the old app |
| `bpl` | The legacy BPL schema |

Cross-database references (a `bil` row pointing at a `core` factory) are **plain
indexed columns, not foreign keys** — they cannot be, and that is the existing
convention, not an oversight.

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
app/Modules/{Core,Bil,Bpl}/     PSR-4 "Modules\", wired by ModuleServiceProvider
  Livewire/                     full-page Livewire components
  Models/                       each pins its $connection
  Views/                        namespaced core:: bil:: bpl::
routes/{core,bil,bpl}.php       one file per module
config/pages.php                the page + ability registry (access control)
config/datagrid.php             every DataGrid, for the data-views sync
docs/DEPLOYMENT.md              release runbooks, newest first
scripts/verify_*.php            standalone checks a release is verified with
```

Currently 49 registered pages and 14 data grids.

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
# point CORE_/BIL_/BPL_DB_DATABASE at your databases, then:
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
| `bil:reconcile-warehouse-stock` | Rebuild the stock aggregate from barcodes |

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
