# BIL — Machines

The structural spine the rest of BIL hangs on — **Company → Factory → Line →
Project** — plus the people side (**Department → Division → Staff**), the
per-line conversion setup, and machine servicing.

- **Connection:** the hierarchy lives in `core` (companies, factories, lines,
  projects); legacy `bil` `factory_*` tables are now **views** over it with
  name→id triggers, so the old app keeps reading/writing by name.
- **Related admin:** Companies, Factories, Divisions, Staff and Departments are
  edited under [Admin](core-admin-and-settings.md); this module is the BIL-facing
  slice (Lines, Projects, Conversion Setup, Services).

---

## The hierarchy

```
Company ─► Factory ─► Line ─► Project          (the machine spine)
Department ─► Division ─► Staff                 (the people spine)
```

A **Line** is a production line within a factory; a **Project** is work scoped to
a line. Almost every entry screen elsewhere resolves to a line (and through it a
factory and company), which is why getting this right matters everywhere.

## Pages

- **Lines** (`bil.machines.lines`, CRUD) — production lines per factory.
- **Projects** (`bil.machines.projects`, CRUD) — projects per line.
- **Conversion Setup** (`bil.machines.conversion-setup`, CRUD) — configures a
  line's conversion. This is where the BIL **production → conversion** rename
  lives: compat views stay writable so the legacy app is unaffected. The setup
  page owns the conversion lifecycle that the Finished Goods pipeline
  (conversion → exit → store) reads.
- **Services** (`bil.machines.services`, view only) — machine service jobs. An
  entry form with **no backdate**: a service job's start and end dates *are* the
  record, so there is nothing to lock to "today".

## Reports

- **Services** (`bil.machines.reports.services`, view/edit/delete/export) — the
  service log, editable.
- **Conversion History** (`bil.machines.reports.conversion-history`, view/export)
  — a read-out of the conversion lifecycle; the setup page owns the lifecycle,
  this is just the log.

## Statistics

`bil.machines.statistics` — service and line activity.

---

### Traps

- **`factory_*` are views now.** The legacy `factory_lines` / `factory_details`
  are composed, read-only views; the screens behind those were retired. A
  one-table `factory_*` view is insertable (triggers fill the ids), a composed
  one is not.
- **Drop the triggers when the legacy app dies.** The name→id triggers exist only
  to serve legacy writes.
- **Service dates are the record.** No backdate on Services — don't add one.
