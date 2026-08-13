# Deployment notes

Newest release first. Each section is a runbook: what changed, the order to do it
in, what to check afterwards, and how to get back if it goes wrong.

---

## 2026-08-13 (d) — The machine maps now track the hierarchy, and gds is strict

A follow-on from `gds:check-machine-maps`. Two problems it exposed:

**1. The maps were a snapshot.** `machine_map_*` were built once by the split
migration and never refreshed. The triggers RECOMPUTE the ids from the name
columns and win — so a line or staff member added in gds afterwards was unknown
to the trigger, which stored NULL and **discarded the correct id gds had
supplied**. Nothing errored; the record simply belonged to nobody.

Now refreshed automatically whenever a Factory, MachineLine, MachineProject,
Division or Staff is saved, deleted or restored
(`Core\Providers\MachineMapsServiceProvider`), hooked on the MODEL so a change
made anywhere keeps them correct.

```
php artisan gds:rebuild-machine-maps            # all six
php artisan gds:rebuild-machine-maps --kind=staff
```

This is the "re-run the map build" step after a production data refresh — until
now there was no command for it, only the migration.

Safe on a live system: each map is built alongside the old one and swapped in
with an atomic `RENAME TABLE`, so a legacy insert never finds it missing. (A
`DROP` + `CREATE` would fail every concurrent insert whose trigger hit the gap.)

**2. gds could file work against nobody.** Services now reads the row back after
insert and **rolls back** if the trigger left an id null, naming the unknown
value. Historic rows are left alone — 976 of them name staff the hierarchy has
never had — but gds does not add to them.

The rebuild is deliberately not attempted mid-save: it uses `RENAME TABLE`, which
implicitly commits in MySQL and would break out of the transaction.

### After deploying

```
php artisan gds:rebuild-machine-maps
php artisan gds:check-machine-maps      # exit 1 if anything is unresolved
```

### What to check afterwards

Add a staff member under BIL → Machines → Staff, then log a service job against
them. Before this change the job saved with a null `staff_id`; now it saves
attributed. Removing them from `machine_map_staff` by hand makes the save refuse
with the reason.

---

## 2026-08-13 (c) — `gds:check-machine-maps`: a preflight for the data refresh

A refresh loads production data over `bil`/`bpl` while the **machines hierarchy**
is carried across from another environment. The legacy app writes NAMES
(`linename`, `factory`, `project`, `staff`) and triggers resolve them to core ids
through the `machine_map_*` tables — so any name the hierarchy has never seen
resolves to NULL. Silently.

Usually cosmetic. **For Conversion Waste it is not:** a run is keyed on
`factory_conversion.line_id`, and pallets with a null line form no run at all —
they never reach the waste queue, never need confirming, and never block. The
control fails OPEN. This command is how that announces itself.

```
php artisan gds:check-machine-maps          # exit 1 if anything is unresolved
php artisan gds:check-machine-maps --all    # list every unresolved value
php artisan gds:check-machine-maps --strict # count legacy placeholders too
```

Run it **after** loading production data and re-running the split, since the maps
are built from production's names — coverage can only be judged with both halves
in place.

### How it works

The checks are **derived from the triggers at runtime**, not hardcoded, so they
cannot drift from the schema. (Hardcoding missed `staff_id` on the first attempt:
it matches on two columns, `division_nm` + `staff_nm`, not one.)

Resolution is tested **in SQL**, with the same join the trigger does, so MySQL's
collation rules apply. Checking in PHP is stricter than the database and reports
names as missing that in fact resolve — "Aluminium Foil" matches "ALUMINIUM FOIL"
in a case-insensitive collation.

### Reading the output

Legacy placeholders are reported separately and do **not** fail the check — no
amount of editing the hierarchy would fix them:

- `factory_usage_rawmaterials.project` holds the literal `'machine'` on 120,971
  of its 121,043 rows (100%); the column is not holding a project.
- `factory_machine_maintenance.subproject` holds the string `'null'` on 436 rows.

A real gap names something that should exist. On this dataset, two:

| Gap | Rows | What it is |
|---|---|---|
| `factory_machine_maintenance.staff_id` | 976 | **10 people missing from BIL > Machines > Staff** |
| `factory_machine_maintenance.project_id` | 1 | "OMET 6", one job from Feb 2021 on a list that only ever had OMET 1–5 |

### Fixing a gap

Add the missing line / project / staff in **BIL > Machines**, then re-run the map
build. Do **not** edit the legacy rows — the trigger resolves them on the next
write.

---

## 2026-08-13 — Conversion Waste, and the confirmation that gates production

Rebuild of the legacy `factory_production_waste.php`, on a different shape.

### What shipped

- **BIL → Finished Goods → Conversion Waste** (`/bil/finished-goods/conversion-waste`)
- **BIL → Finished Goods → Reports → Conversion Waste**
- **Settings → Waste** (`/settings/waste`) — the causes and origins vocabulary
- A confirmation rule that stops **Conversion Output** starting a new run on a
  line until the previous run's waste is confirmed.

Nothing is migrated: the legacy `bil.factory_waste` table is **empty** (0 rows),
so gds owns all four new tables outright and the legacy screen keeps its own.

### The idea: waste hangs off a RUN

A **run** is one line converting one product, on one date, in one shift.
`(line_id, production_date, shift, productid)` is unique.

Runs are **derived, not declared** — a run exists because pallets were booked
against it in `factory_conversion`. A row in `conversion_waste_runs` is created
only when someone enters waste or confirms, so *no row* means *nobody has looked
at this yet*, which is exactly the state that should block the next run.

Runs are ordered by the last pallet booked (`MAX(factory_conversion.id)`), not by
date and shift alone. That is what makes a **mid-shift product change** work: two
runs can share a line, date and shift and still have an unambiguous order,
because one's pallets were booked before the other's. No changeover log is
consulted — production itself says which came first.

### ⚠️ The confirmation cut-over — set this per environment

```
WASTE_CONFIRMATION_START=2026-08-13
```

**This is the setting that matters.** There are 1.2M historic pallets with no
waste recorded against them. A rule of "the previous run must be confirmed"
applied to all of history would block every line on day one and never let go.

Production before this date is history: visible in the reports, never a blocker.
Set it to the day the feature goes live in that environment. The fallback in
`config/waste.php` is a fixed date rather than `now()`, deliberately — so the
boundary cannot quietly move every time the app boots.

**It is also editable in Settings → Waste**, which overrides the environment
value; "Revert to environment" drops the override and puts `.env` back. Overrides
live in the new `app_settings` table (`Core\Support\Settings`) — a row there
beats the matching `config` key, so a fresh environment behaves exactly as its
`.env` says until somebody deliberately changes it.

Editing it is consequential in **both** directions, so the page prices the change
before it is made and records who made it:

- **later** — open runs drop off the queue and stop blocking. A backlog can be
  made to disappear by editing a date, which is precisely why the preview says
  how many and the change is attributed.
- **earlier** — historic production becomes unconfirmed and every affected line
  blocks at once.

Restrict `settings.waste:edit` accordingly.

### Shift windows now define what "day" and "night" mean

Conversion Output and Conversion Waste are registered as shift contexts
(`gds:sync-shift-contexts`), so both appear in **Settings → Shifts**.

Their windows do more than gate a page. The Day window's START is the boundary a
**production date** rolls over on — 07:00 by default, reproducing the legacy
`functions/production_date.php` — and the window a moment falls in is the shift
a pallet is stamped with. Conversion Output and the waste run read the same
configuration, so they cannot disagree about which run a pallet belongs to.

Both are **inactive by default**: the windows describe the shifts without
enforcing them, exactly as before. Turning a context Active additionally stops
the page being used outside its windows (`bypass-shift` exempts a role).

⚠️ The window **names** matter as well as the times. A window is matched to the
value stored in `factory_conversion.shift` by lowercasing its name, and the
legacy app reads that column — so **keep them named Day and Night**. A renamed
window is ignored and the built-in 07:00/19:00 split is used instead, rather
than writing a shift value nothing else can read.

### New abilities (run `php artisan gds:sync-pages`)

| Page | Ability | Who needs it |
|---|---|---|
| `bil.finished_goods.conversion_waste` | `view` | anyone entering waste |
| | `confirm` | whoever closes a run — the supervisory act |
| | `reopen` | whoever may correct a confirmed run |
| | `bypass-waste-lock` | **production must not halt because a supervisor is away** |
| `settings.waste` | `view`, `edit` | whoever maintains causes/origins |

`bypass-waste-lock` is the escape hatch, and it is a permission rather than a
button anyone can click. Grant it deliberately — a holder can book pallets with
the previous run's waste still unrecorded.

### After deploying

```
php artisan migrate --force            # 5 tables (4 waste + app_settings)
php artisan gds:sync-pages             # 3 new pages, 8 new permissions
php artisan gds:sync-shift-contexts    # 2 new shift contexts, inactive
php artisan optimize:clear
```

Then, in the Roles UI, grant `confirm` (and decide about `bypass-waste-lock`)
to whichever role supervises the lines. **Until someone holds `confirm`, no run
can be closed and every line will block after its first run.**

### What to check afterwards

- Settings → Waste lists **13 causes** and **2 origins** (Jumbo Roll → grade
  types, Raw Materials → groups).
- On Conversion Waste, picking **Jumbo Roll** on a row offers 20 grade types;
  **Raw Materials** offers 8 groups. Origin is per row, not per form.
- Book a pallet, then try to book a different product on the same line: the
  Conversion Output button is disabled with the reason shown, for anyone without
  the bypass.
- Enter the waste, confirm the run, and the line frees immediately.
- Reports → Conversion Waste → **Runs & confirmation** shows open runs. A run
  with no entries and no confirmation is not "no waste" — it is nobody having
  looked, and this is the only view that tells the two apart.

### If it goes wrong

The block is the only thing that touches existing behaviour. To lift it without
rolling anything back, either grant `bypass-waste-lock` broadly, or push
`WASTE_CONFIRMATION_START` into the future — both leave the data intact.

To remove it entirely: `php artisan migrate:rollback --step=1` drops the four
tables. `factory_conversion` is untouched by any of this.

---

## 2026-08-13 (a) — Index audit of the legacy schema

Reports over `factory_conversion` and `factory_exit` took ~4s to open. An audit
of every table in `bil`/`bpl`/`core` against the columns gds filters, joins and
sorts on found the cause: **the legacy schema indexed identity and foreign keys
but never the dates** — and every report opens on a date range.

### What shipped

One migration, `2026_08_13_..._index_legacy_hot_columns`, adding six indexes:

| Table | Index | Why |
|---|---|---|
| `factory_conversion` | `(barcode)` | read on **every scan**; 1.2M rows, near-unique |
| `factory_conversion` | `(dateofproduction, id)` | Conversion Output + Factory Floor Stock |
| `factory_exit` | `(dateofexit, id)` | Factory Exit report |
| `sales_loading` | `(dateofloading)` | stock ledger + movements modal |
| `sales_loading_return` | `(loading_id)` | the unload join in `loadedSinceCutover()` |
| `sales_order` | `(dateoforder)` | 90-day order window + movements modal |

Chosen by measurement: every column added has a worst-case bucket under 1% of
its table. The categorical filters beside them (`factory`, `shift`, `linename`,
`exitlocation`, the RM `status` columns) measured 30–100% and are **deliberately
left alone** — MySQL would ignore such an index and the writes would still pay.
The MyISAM raw-materials tables are untouched: `ADD INDEX` locks them for the
rebuild, which is not worth a maybe on a live shared database.

### Two query-shape fixes that came with it

- `>= x AND <= x` is **not** collapsed to an equality by MySQL; `BETWEEN x AND x`
  is. The reports that hand-rolled the pair could not use the new index for
  their `ORDER BY`. All now route through `applyDate()`.
- Ordering by `id` while filtering on the date forces a choice between two
  indexes, and with **server-side prepared statements** MySQL plans
  `BETWEEN ? AND ?` without knowing the values — so it picked a backwards
  primary-key scan and walked the whole table. The same SQL ran in 5ms with
  literals and 3,200ms with bindings. The listings now order **along** the
  `(date, id)` index. Rows and their order are unchanged.

`applyDate()` also no longer ignores one-sided ranges — asking for "from 1
January" with no end date previously returned all time.

### After deploying

```
php artisan migrate --force      # ~30s; ALGORITHM=INPLACE, LOCK=NONE
php artisan optimize:clear
```

Additive and index-only — the legacy PHP app reading the same tables sees
nothing but faster queries. Writes continue during the build.

### What to check afterwards

Conversion Output and Factory Exit reports open in well under 200ms on any
range. Scanning a barcode at Factory Exit is immediate.

---

## 2026-08-12 (b) — Gates gain direction; warehouses gain a module; raw materials imported

Two further migrations, on top of the same day's rebuild:

```
2026_08_12_120000_consolidate_gates_and_add_warehouse_module
2026_08_12_130000_import_raw_materials_warehouses_and_stock
```

### Direction folded into the gate

The first cut modelled only the two gates finished goods needed. Raw materials
needs the other two — out of a warehouse (Warehouse Exit) and into a factory
(Factory Entrance, Factory Returns). Four gate tables plus four grant pivots is
the same shape four times over, and would put four checklists in the user editor.

A gate is a place; which way goods are going is an attribute of the movement.
`both` is a real case — one elevator or roller door often serves either way.

```
warehouse_entrances        -> warehouse_gates      (+ direction)
factory_exit_locations     -> factory_gates        (+ direction)
warehouse_entrance_user    -> warehouse_gate_user  (entrance_id -> gate_id)
factory_exit_location_user -> factory_gate_user    (exit_location_id -> gate_id)
```

Safe renames: the tables shipped hours earlier, hold only imported gates, and
nothing outside gds reads them.

### `warehouses.module` — what a warehouse stores

Not cosmetic. The module decides which product master a warehouse's stock refers
to and which stock table holds it, which is why the stock tables are per module
in the first place. The list lives in **`config/warehouses.php`**, not a lookup
table, because adding a module needs its stock table, product picker and screens
— a row added in Settings would look like it worked and then have nowhere to put
stock. Modules without a stock table behind them are still offered but labelled
*"not built yet"*.

**A warehouse holds one module.** A building storing two kinds is two
warehouses — separate stock, separate gates, separate staff anyway.

### Raw materials imported onto the same model

| Legacy | Now |
| --- | --- |
| `rawmaterial_store_location` (Ogba, Oregun) | `warehouses` with module `raw-materials`, keeping `legacy_location_id` |
| `rawmaterials_storeexit_details` | warehouse gates, direction `out` |
| `factoryentrance_details` | factory gates, direction `in` |
| `rawmaterials_stock` (keyed by a location NAME) | `raw_materials_warehouse_stock` (keyed by warehouse) |

- `gate_id` added to `rawmaterials_warehouse_entry`, `rawmaterials_warehouse_exit`,
  `factory_entrance_rawmaterials` and `return_approval`.
- **Factory entrances backfilled exactly** — 178,634 rows, because `location_id`
  already pointed at `factoryentrance_details`.
- **Warehouse entries and exits are NOT backfilled.** Their `location_id` is the
  store, not a gate, and the legacy app never recorded which gate was used.
  Inventing one would be fiction; historic rows keep a null gate and report "—".
- RM warehouses got **one receiving gate each** (`Ogba Entrance`,
  `Oregun Entrance`), because the legacy app had no such table — entry booked
  straight against the store. Rename or add to them as needed.
- New RM stock seeded from the **in-store barcodes**, not from
  `rawmaterials_stock` — that aggregate had drifted ~8.5x, which is what its own
  reconcile command exists to fix. Seeded 117 products / 13,551 units at Ogba.
- "Oregun Store" appears in the legacy factory-entrance table but is a store, not
  a factory; imported inactive so history resolves without offering it.

### The raw-materials screens are on the gates

All four now pick a gate instead of hard-coding a location, and move
`raw_materials_warehouse_stock` instead of `rawmaterials_stock`:

| Screen | Was | Now |
| --- | --- | --- |
| Warehouse Entry | `LOCATION_ID = 1` | inbound gate on an RM warehouse |
| Warehouse Exit | `EXIT_LOCATION = 'Rawmaterial Store'` | outbound gate on an RM warehouse |
| Factory Entrance | all rows minus a hard-coded exclusion list | granted inbound factory gates |
| Factory Returns | `LOCATION_ID = 1` | inbound gate — a return lands back in a store |

`location_id` is still written on every row, because the legacy app reads it;
`gate_id` is written alongside. Factory Entrance's legacy exclusions (PM2, PM3,
Oregun Store) are now data rather than a constant — those gates were imported
against BPL factories or, for Oregun Store, inactive because it is not a factory.

The reports gain a **gate filter** (Warehouse Entry, Warehouse Exit, Factory
Entrance). It narrows to movements booked through gds — historic rows have no
gate, deliberately — so the existing **Location** filter remains the one that
covers all of history.

### ⚠️ The raw-materials tables are MyISAM

`rawmaterials_warehouse_entry`, `rawmaterials_warehouse_exit`,
`factory_entrance_rawmaterials` and `return_approval` are all MyISAM, which has
**no transactions**. A `DB::transaction()` around them is a lie — a failure
part-way through leaves the rows written. This is why those screens serialise on
`GET_LOCK` rather than pretending to be atomic.

It is also why deriving stock from the barcodes matters: if a batch
half-completes, the totals can be put right.

```
php artisan bil:reconcile-rm-stock            # report
php artisan bil:reconcile-rm-stock --fix      # repair
```

**Run it after any incident on these screens.** It is also the only safe way to
verify them — a test cannot wrap them in a transaction and roll back, because the
bil-side rows survive.

### Still on the legacy stock table

Three screens still write `rawmaterials_stock` and not the new table: **Stock
Transfer**, **Damaged Goods**, and the **Warehouse Stock report**'s inline edit
and delete. They were not in scope for this pass.

Because stock derives from the barcodes, this is recoverable rather than
corrupting — those screens still update the barcode rows correctly, so
`bil:reconcile-rm-stock` brings the new totals back in line. Run it after using
any of the three until they are migrated too.

### After deploying

```
php artisan migrate
php artisan gds:sync-pages
php artisan gds:sync-data-views
```

Grant the renamed pages: `admin.warehouses`, `admin.warehouse_gates`,
`admin.factory_gates`. Then set a **module on every warehouse** — the two RM ones
are set by the migration, but any finished-goods warehouse you create needs it,
and its gates will not appear on the receiving screen without it.

---

## 2026-08-12 — Warehouses rebuilt as core structure; finished-goods stock replaced

**Two migrations, and a deliberate break with the legacy stock tables.**

```
2026_08_12_100000_create_warehouses_and_gates
2026_08_12_110000_create_finished_goods_warehouse_stock
```

### What was wrong

The legacy model had no warehouse in it. `storebundle` hard-coded a single
`warehousecode = '01'`; `storebundle_floor` hard-coded three floors; which floor
a gate fed was a PHP string comparison against one location name; and who could
use which gate was a `switch` on the legacy user level. Gates themselves were
name pairs in `storeentrance_details` / `factoryexit_details`.

### What replaces it

Warehouses and gates are **core structure, not a finished-goods concern** — a
company owns factories where goods are made and warehouses where they are
stored, and each owns the gates goods move through. BPL stores goods too, so
scoping these per module would mean a `bpl_warehouse` twin of every table.

| Table | Holds |
| --- | --- |
| `warehouses` | a warehouse, owned by a company (sibling of `factories`) |
| `warehouse_entrances` | a gate goods are received through |
| `warehouse_entrance_user` | which entrances a user may pick |
| `factory_exit_locations` | a gate goods leave a factory through |
| `factory_exit_location_user` | which of those a user may pick |
| `finished_goods_warehouse_receipts` | replaces `store_entrance` |
| `finished_goods_warehouse_stock` | replaces `storebundle` + `storebundle_floor` |

Receipts and stock stay **module-specific** because `productid` means a
different thing per module — bil.products here, bpl_products for BPL. A shared
stock table would need a discriminator and would be wrong the first time someone
omitted it. BPL gets its own pair over the same warehouses and entrances.

`warehouses` also supersedes the legacy `storelocations`, a rack-line layout
abandoned in April 2018 (560 rows, last written 2018-04-17, referenced by no
legacy PHP or JS). Left in place untouched as dead data.

### ⚠️ Clean cut: gds stops writing the legacy stock tables

gds no longer writes `store_entrance`, `storebundle` or `storebundle_floor`.
The legacy app still owns them. **From this deploy the two diverge** for anything
received through gds — that is the intended behaviour, not a bug, but it means:

- Legacy stock screens go stale for gds receipts. They stay correct only for
  what the legacy screen itself takes in.
- The **Warehouse Entrance report shows gds receipts only.** The 1.17M legacy
  `store_entrance` rows are not merged in — the schemas differ and blending two
  sources is how a number nobody can reproduce gets created. The legacy report
  remains the place to look at anything received before the cut-over.
- Both apps are still read where it matters: the receiving screen refuses a
  pallet the legacy app already took in, and the delete guards on the Conversion
  Output and Factory Exit reports check **both** receipt tables.

Plan to retire the legacy receiving screen soon after this ships; running both
in parallel means stock lives in two places.

### The gain: stock is now derivable

Every bundle in `finished_goods_warehouse_stock` arrived on a receipt, so the
totals are exactly `SUM(bundles)` per warehouse per product. The legacy totals
were not — nothing recorded which floor a bundle had been counted onto, so drift
was permanent and undetectable.

```
php artisan bil:reconcile-fg-stock            # report
php artisan bil:reconcile-fg-stock --fix      # repair
```

Verified before shipping: corrupting a total is detected and repaired exactly,
and a receive/un-receive round trip balances to zero.

### The pipeline is now a strict chain

Conversion Output → Factory Exit → Warehouse Entrance. Factory Exit validates
against `factory_conversion`; **Warehouse Entrance now validates against
`factory_exit`**. A pallet that never left the factory cannot be received.

This is a change in behaviour: the legacy screen validated against production
and let the warehouse receive a pallet whose gate scan was missed. Missed exits
must now be scanned at the gate first — the screen says so rather than just
refusing. Tell the warehouse team before this goes live.

`date_of_entrance` keeps the legacy rule (the pallet's exit date, so a
next-morning scan still lands on the right day); with the exit now mandatory
that date always exists.

### Per-user gates replace the user-level switch

Which gates appear in a dropdown is granted per user, ticked in Admin → Users.
This is **not** access control — the `page:` middleware still decides who may
open a screen — it narrows the list once they are there. Admin sees every gate.

**The checklists only appear when the selected role reaches a page that uses
that kind of gate.** Granting gates to a role with no scanning screens is dead
configuration, and showing the list implies otherwise; the editor says so
instead. Pages declare what they pick from via a `gates` field in
`config/pages.php` (`warehouse` or `factory`), so tagging a new scanning screen
there is all that is needed — no list to remember to extend. `PageSyncer`
ignores the field, so it costs nothing at sync time.

Changing a role does **not** clear existing grants. Gates are not access
control, so a stale grant cannot let anyone in, and keeping them means moving a
user out of a role and back does not silently lose their configuration.

**Nothing is granted by this migration.** After deploying, every operator needs
their gates ticked or their dropdown will be empty; the screens say so plainly
rather than failing silently.

### Migration notes

- The 1.2M-row `exit_location_id` backfill runs as one `CASE` pass (~5 min);
  `exitlocation` is an unindexed varchar, so an update per gate would be a full
  scan each. **All 1,201,223 rows resolve**, including four spellings from
  March–April 2017 that `factoryexit_details` never held.
- One of those is not a variant: **Bil-2 really had a gate**, later dropped from
  the legacy table but still named by 16 pallets whose barcodes carry B2. It is
  recreated inactive so history resolves without offering it to anyone.
- `exitlocation` itself is left alone — the legacy app reads it, and the old
  spellings are what actually happened.

### After deploying

```
php artisan migrate
php artisan gds:sync-pages
php artisan gds:sync-data-views
```

Then, in order:

1. **Create the warehouses** (Admin → Warehouses). Nothing is seeded — the
   legacy data had no warehouse concept to derive them from.
2. **Attach each entrance to one** (Admin → Warehouse Entrances). The three
   imported gates arrive unassigned and **cannot receive until attached**.
3. **Grant gates per user** (Admin → Users).
4. Grant the new pages: `admin.warehouses`, `admin.warehouse_entrances`,
   `admin.factory_exit_locations`.

### What to check afterwards

- `bil:reconcile-fg-stock` reports no drift.
- A receive moves `finished_goods_warehouse_stock` by exactly the pallet's
  bundle count, and deleting the receipt puts it back.
- An operator sees only their granted gates; an admin sees all.
- The legacy Item Send / Item Receive screens still save — nothing they use
  changed.

### If it goes wrong

`php artisan migrate:rollback --step=2` drops the new tables and the
`exit_location_id` column. **Check for gds receipts first** — rolling back
discards them, and the legacy tables have no record of those pallets.

---

## 2026-08-08 — Finished Goods: Warehouse Entrance + its report (moves stock)

Code only — **no migration, no schema change**. `store_entrance`,
`storeentrance_details`, `storebundle`, `storebundle_floor` and `store_floors`
are used exactly as the legacy app leaves them.

### What shipped

**BIL → Finished Goods → Warehouse Entrance** — a rebuild of legacy
`store_entrance_beta.php` (*"Item Receive"* in the legacy nav), and
**→ Reports → Warehouse Entrance**, a rebuild of `report_store_entrance.php`
(*"Item Received"*), keeping the **first three** of its five views
(= `Report\Store\Entrance::option1/2/5`). Calendar Y/X left behind as before.

This completes the pallet pipeline: **Conversion Output → Factory Exit →
Warehouse Entrance**. After this point stock is counted in bundles, not
barcodes, so nothing downstream references a receipt row (`sales_loading`
carries its own load barcode, not the pallet's — verified against live data).

### ⚠️ This page moves stock — the important difference

Unlike the other two scanning screens, receiving a pallet updates two running
totals that are **shared live with the legacy app**:

| Table | Holds |
| --- | --- |
| `storebundle` | bundles per product across warehouse `01`, plus a `modifications` JSON audit trail |
| `storebundle_floor` | bundles per product per floor |

**Nothing recomputes these from the receipts.** They are only ever incremented
and decremented, so a receive that skips them, or a delete that fails to reverse
them, is permanent drift that no reconciliation job will find. Both directions
therefore go through one class — `Modules\Bil\Support\FinishedGoodsStock` — so
they cannot fall out of step, and both run inside the same transaction as the
receipt itself.

Two consequences worth knowing before granting anything:

- **`delete` on the report is a stock permission, not a tidy-up one.** Deleting
  a receipt takes its bundles back out of both totals.
- The reversal uses the **receipt's own** product, bundles and gate, not the
  pallet's current values — so it is an exact mirror of what that receipt added
  even if the pallet has since changed.

**The gate → floor mapping is a shared contract.** `FG Store FB Elevator 1`
feeds `floor b`; every other gate feeds `floor c`. That hard-coded string is
copied verbatim from the legacy trait: if the two apps ever disagree, a
product's floor totals silently split between them. Verified identical for all
three gates. **If a gate is ever added, change both apps together.**

If a gate somehow has no floor, the save now **fails loudly** rather than
writing a receipt with no stock movement — a blocked scan is recoverable, silent
drift is not.

### Other contracts reproduced from the legacy app

- A barcode is accepted if it is in `factory_conversion` and not already in
  `store_entrance` (UNIQUE — received once). Note it is checked against
  **conversion, not factory exit**: the warehouse can receive a pallet whose
  gate scan was missed or comes later, which is exactly what
  `factory_exit.status` exists to record.
- **`dateofentrance` is the pallet's EXIT date when it has one**, falling back to
  the date on the form. A pallet scanned in the next morning still lands on the
  day it actually left the factory. The date field on the form only applies to
  pallets with no exit record, and the page says so.
- Both `factory_exit.status` and `factory_conversion.status` are flipped to
  `'yes'`; deleting a receipt clears `factory_exit.status` back to NULL (and,
  as in the legacy `_delete`, leaves the conversion status alone).

One deliberate difference: the legacy trait seeded `modifications` with a bare
JSON object, which `JSON_ARRAY_APPEND` auto-wraps on the next write (2 of 371
live rows are still bare objects). gds seeds a one-element array instead — same
result from the first write, consistent shape. Nothing reads the column; it is a
write-only audit trail.

### After deploying

```
php artisan gds:sync-pages
```

Then grant the two new pages — invisible to everyone but Admin until then:

| Page key | Where |
| --- | --- |
| `bil.finished_goods.warehouse_entrance` | Finished Goods → Warehouse Entrance |
| `bil.finished_goods.reports.warehouse_entrance` | Finished Goods → Reports → Warehouse Entrance |

The legacy screen restricted each store user to their own floor's gates by user
level (16 → Store FB, 18 → FC 1, 19 → FC 2). gds gates access **per page, not
per floor**, so every gate is offered to anyone who can open the page. If
per-floor separation is wanted, it needs a new mechanism — do not assume the old
restriction carried over.

### What to check afterwards

- Legacy **Item Receive** still saves, and its stock totals still move.
- Receive a pallet in gds, then check `storebundle` and `storebundle_floor` for
  that product moved by exactly its bundle count — and that deleting the receipt
  puts both back. Verified before shipping, including the round trip.
- Report totals match the legacy report for the same day. Verified against
  2026/08/06: 376 pallets, 20,160 bundles, identical row count.

### If it goes wrong

Revert the code; there is nothing to roll back. But **check the stock totals
first** if any receipts were saved or deleted through gds — those are the only
writes that are not self-correcting.

---

## 2026-08-07 — Finished Goods: Factory Exit + its report; delete fixed on the Conversion Output report

Code only — **no migration, no schema change**. `factory_exit` and
`factoryexit_details` are used exactly as the legacy app leaves them.

### What shipped

**BIL → Finished Goods → Factory Exit** — a rebuild of legacy
`factory_exit_beta.php`, which the legacy nav calls *"Item Send (to Warehouse)"*.
Pallets are scanned as they pass the gate. This is the middle step of a pallet's
life: Conversion Output mints it, Factory Exit sends it, the store receives it.

**BIL → Finished Goods → Reports → Factory Exit** — a rebuild of
`report_factory_exit.php`, keeping the **first three** of its five views
(Default, Summary by location/product, Summary by product = legacy
`Report\Factory\Out::option1/2/5`). Calendar Y and Calendar X were left behind,
as on the Conversion Output report.

**Bug fix — delete on the Conversion Output report never worked.** The report
declared the `delete` ability and rendered the button, but never implemented the
`findRow`/`performDelete` hooks, so `deleteConfirmed()` always fell into its
"Row not found." branch and flashed that error. Nothing was ever deleted and
nothing was ever corrupted — it simply did nothing. Both hooks are now
implemented. An audit of the other 11 reports found no second instance: every
other non-`readOnly()` report implements both.

### Contracts reproduced from the legacy app

- A barcode is accepted only if it exists in `factory_conversion` **and** is not
  already in `factory_exit` (the column is UNIQUE — a pallet leaves once).
  Product and bundle count are read off the pallet, never typed.
- Barcodes are stored **upper-cased**, as `Factory\Out::insertScanning` did.
- Saving flips `factory_conversion.status` to `'yes'` so the production screens
  stop showing the pallet as still on the floor.
- **`factory_exit.status` is not the exit's own state** — it mirrors whether the
  *store* has received the pallet. Exit sets it only on an out-of-order scan
  (barcode already in `store_entrance`); the legacy store-entrance screen is what
  normally sets and clears it.
- Date follows the legacy production date: a scan before 07:00 books against the
  previous day. `backdate` holders may pick any date.
- Deleting an exit puts the pallet back on the factory floor
  (`factory_conversion.status` = NULL), mirroring `Factory\Out::_delete`.

### Two guards the legacy app did not have

Both refuse a delete that would orphan a downstream row, and both name the step
to undo first, so nothing is a dead end:

- **Factory Exit report** refuses to delete an exit once the store has received
  the pallet — "delete the store entry first".
- **Conversion Output report** already refused a received pallet; its delete now
  also removes any `factory_exit` row for the barcode, so a re-created pallet can
  be scanned out again rather than colliding with the UNIQUE index.

The receipt itself is the authority for both, not the denormalised
`factory_exit.status` copy, because two different screens write that column.

### After deploying

```
php artisan gds:sync-pages
```

Then grant the two new pages — like every new page they start with no access for
anyone but Admin:

| Page key | Where |
| --- | --- |
| `bil.finished_goods.factory_exit` | Finished Goods → Factory Exit |
| `bil.finished_goods.reports.factory_exit` | Finished Goods → Reports → Factory Exit |

`backdate` on the entry page is the ability that unlocks the date field; without
it the operator always books against the shift date.

### What to check afterwards

- Legacy **Item Send (to Warehouse)** still saves — nothing about the schema
  changed, so it should be untouched.
- A pallet scanned out in gds disappears from the legacy factory-production
  "still on the floor" listings.
- Report totals match the legacy report for the same day. Verified before
  shipping against 2026/08/06: 409 pallets, 22,364 bundles, identical row count.
- Delete on **Reports → Conversion Output** now actually deletes.

### If it goes wrong

Revert the code; there is nothing to roll back. The two pages only write rows the
legacy app already writes, in the same shape.

---

## 2026-08-07 — Finished Goods module; BIL "production" renamed to "conversion"

Three migrations, in this order:

```
2026_08_05_170000_create_product_machines_and_null_lamedge
2026_08_05_180000_add_hardroll_source_to_products
2026_08_07_100000_rename_bil_production_to_conversion
```

This entry covers the whole **BIL → Finished Goods** module, which had not been
written up yet, as well as the rename.

### Before you start

Set **`BIL_QC_PICS_PATH=E:/QC/Pics`** in the production `.env`. Quality-control
product photos live outside both apps, on the share the legacy app writes to
(`<STORAGE_PATH>/QC/Pics`, and production `STORAGE_PATH` is `E:/`); 223 of 296
products have one and `products.imagepath` stores only the bare filename. The
config falls back to `storage/app/qc-pics` so a dev box works, and **leaving that
default in production silently diverges the two apps** — gds writes new photos
where the legacy app cannot see them and shows blanks for the existing 223. The
path must be readable *and* writable by the web user. A wrong path fails quietly:
images simply do not render.

### The rename

BIL does not produce paper — it *converts* hardroll made by BPL into finished
goods. The BIL tables said production, which reads as if BIL and BPL do the same
job:

```
factory_production            -> factory_conversion          (1.2M rows)
factory_preproduction         -> conversion_setup            (1 row per line)
factory_preproduction_history -> conversion_setup_history    (~25k rows)
```

`bpl_production` and `bpl_softroll_production` are deliberately **not** renamed;
they record actual paper manufacture.

`RENAME TABLE` is a metadata operation, so the 1.2M-row table renames instantly.
Each old name is left behind as a compatibility **VIEW**.

**Why the views are writable here when the `factory_lines` ones are not:** those
are composed views and therefore read-only, but a straight one-table `SELECT *`
view is insertable and updatable. The 30+ legacy pages keep both reading and
writing through the old names. Verified live before shipping: the changeover
`UPDATE factory_preproduction … WHERE linename`, an `INSERT` into
`factory_preproduction_history`, and an `INSERT` into `factory_production` all
succeeded, and the BEFORE INSERT/UPDATE name→id triggers travelled with the base
tables and still fired (resolved `line_id`, `factory_id`).

MySQL expands `SELECT *` when the view is created, so a view is frozen to today's
columns. That is intentional — a gds-only column does not leak into the legacy
app — but **a view must be recreated if the legacy app ever needs a new column**.

⚠️ **Duplicate timestamp.** `2026_08_07_100000_rename_bil_production_to_conversion`
shares its prefix with `2026_08_07_100000_repair_legacy_mojibake`. Laravel breaks
the tie on filename, so `rename…` runs first. They touch disjoint tables (the
mojibake repair only ALTERs `factory_machine_maintenance`), so the order is safe
— but do not add a third migration at that timestamp that touches these tables.
The rename is idempotent: it skips any name that is already a view.

### Schema added to `products`

`2026_08_05_170000` creates **`product_machines`** — one row per Factory → Line →
Project path, so a product can name several machines instead of the single
free-text `mach`. Backfilled 217 assignments by matching the existing text
(**case-insensitively** — the stored `Rotomac` has to find `ROTOMAC`); 19
products keep unmatched legacy text like `RW 9/10/GAMBINI`, which the form shows
and preserves until someone replaces it. The same migration folds `lamedge`
values `"N/A"` and `""` to NULL (171 rows).

`2026_08_05_180000` adds `hardroll_company_id` / `hardroll_factory_id`, turning
`"BPL PM 3"` into company BPL + factory PM3. The backfill is conservative on
purpose: the value must *be* a company code, or a code followed by one of that
company's factories. `PT Pindo/BPL` (24 rows) mentions BPL but is a combined
external source, so it is left as text — external mills are typed in, not
structured.

`products.mach` and `products.hardrollsource` both stay, maintained as readable
summaries: the legacy QC screens read them and the revision archive has years of
history in them.

### After deploying

```
php artisan migrate
php artisan gds:sync-pages
php artisan gds:sync-data-views
```

Then grant the new pages in the role matrix — like every new page they start with
no access for anyone but Admin:

| Page key | Where |
| --- | --- |
| `bil.finished_goods.products` | Finished Goods → Products |
| `bil.finished_goods.conversion_output` | Finished Goods → Conversion Output |
| `bil.finished_goods.reports.conversion_output` | Finished Goods → Reports |
| `bil.machines.conversion_setup` | Machines → Conversion Setup |
| `bil.machines.reports.conversion_history` | Machines → Reports |

**Tell users to hard-refresh once.** `public/js/searchable-select.js` is a new
file. Its Alpine component used to be pushed from the partial itself, which only
emitted when an instance rendered — so a page whose only searchable-selects sit
inside a modal loaded without the registration and every one of them threw
`searchableSelect is not defined` the moment Livewire rendered it in. It is now
loaded by the layout.

### What to check afterwards

- Legacy **Quality Control**, **factory production** and the pre-production
  changeover screen still save. They write through the compatibility views.
- **Finished Goods → Conversion Output** lists only lines active in Conversion
  Setup, and a generated barcode continues the day's sequence. The sequence is
  **global per day across factories**, not per factory — the live data confirms
  it (one day's 78 pallets ran 001–078 with B1 and GB interleaved).
- A QC spec sheet shows its product photo. If every photo is missing,
  `BIL_QC_PICS_PATH` is wrong.

### If it goes wrong

`php artisan migrate:rollback` reverses the rename (drops each view, renames the
base table back) and drops `product_machines` and the two hardroll columns.
Two things it does **not** undo, both deliberate: `lamedge` stays normalised
(`"N/A"` carried no information NULL does not, and restoring it would mean
guessing which blanks were which), and `hardrollsource` keeps its normalised
spelling (`"BPL PM3"`). Neither blocks the legacy app.

---

## 2026-08-07 — Exports carry their filters; drill-down modal gets search, paging and export

Code only — no migration, no schema change.

**Exports and printouts now state what produced them.** Every xlsx, csv, pdf and
print page leads with a context block: the view, the date range (in the user's
display format, not ISO), each active filter *by its display label rather than
the id behind it*, and the search term. A spreadsheet outlives the screen that
made it, and "1,204 rows" is unreadable a week later without that. In xlsx/csv
the block sits above a blank line, so the sheet still sorts and filters cleanly
from the heading row.

This covers all three export paths — `RawMaterialReport` (the nine Raw Materials
reports plus Machines → Services), `DataGrid` (every admin/module grid) and
`StatisticsPage` (section, period and the Rounded/Exact toggle, which the export
URL now carries so the file agrees with the screen).

**The drill-down modal is now a working table**, not a read-out: its own search
box, page-size selector and pager, plus an Export menu (Excel / CSV / PDF /
Print) for that group alone. Two things worth knowing:

- The modal pages on `detailPage`, **not** Livewire's `page` — stepping through a
  group's records must not move the report underneath it.
- **Exports take the whole group, not the visible page.** A drill-down export of
  a 2,410-job project writes 2,410 rows, led by the group's identity and then the
  report's own filters.

A report opts in by implementing `detailQuery()` (returning a builder) and
`detailSearchable()`; the base does search, paging and export. The old
`detailRows()` contract — return every row — is gone, and `detailRows()` now
returns a paginator. **Only Machines → Services implements the drill-down**, so
nothing else changes, but any report adding one gets the whole thing free.

Verify with `scripts/verify_export_context.php`: filters reach every format,
the blank-line separator is where it should be, the modal pages/searches, and a
drill-down export holds every record. It passed locally, as did the Services,
drill-down and pagination suites.

---

## 2026-08-07 — Machines Statistics (`2026_08_07_120000_add_duration_minutes_to_maintenance`)

BIL → Machines → **Statistics**, the counterpart to Raw Materials → Statistics:
five tabs (Overview, Machines, Service Jobs, Downtime, Workforce) over the
machine hierarchy and `factory_machine_maintenance`. Same base class, same
export/print, and it offers **All time** — Raw Materials caps at 12 months
because its tables are 310k rows; maintenance is 43k.

The migration adds `factory_machine_maintenance.duration_minutes` and swaps the
five single-column id indexes for covering ones `(id, date, duration_minutes)`.
Takes ~13s locally on 43k rows; safe to run with the app up, but the ALTERs lock
the table briefly, so prefer the same window as anything else.

**Why the column:** `duration` is the legacy `{"d":_,"h":_,"m":_}` JSON, so every
stop-time total meant three `JSON_EXTRACT`s per row — ~500ms a query and ~10s for
the all-time Downtime tab. With the column and the covering indexes that tab is
~250ms. `duration` stays authoritative; this is a cache of it.

**It is maintained by the existing BEFORE INSERT/UPDATE triggers, not a
generated column** — deliberately. A `STORED GENERATED` column makes MySQL
reject any INSERT whose duration isn't valid JSON, and the legacy app still
writes this table; a trigger degrades to NULL instead of failing the write.
Those two triggers are **rewritten whole** by this migration (MySQL can't append
to a trigger), so the id-resolution statements are repeated from
`2026_08_05_200000` — change one and you must change the other.

After deploying: `php artisan gds:sync-pages`, then grant
**`bil.machines.statistics`** to the roles that need it — like every new page it
starts with no access for everyone except Admin.

Verify with `scripts/verify_machine_stats.php` (every section × range renders,
figures reconcile against SQL derived from the JSON rather than the new column)
and `scripts/verify_duration_trigger.php` (both apps' write paths keep it in step). Both
passed locally, as did the existing Services report and pagination suites.

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
