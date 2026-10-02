# Framework — Shifts

Factory-floor entries belong to a **shift** — a Day or Night window — not just a
date. A roll entered at 2 a.m. belongs to the night shift that began the evening
before. The shift framework makes those windows configurable per area and gates
the entry screens to the current one.

---

## How it works

- **Windows are per area, and dynamic.** Each shift-aware area (a factory floor,
  a paper machine) has its own Day/Night start times, configured on
  **Settings → Shift Settings** (`settings.shifts`), seeded from
  `config/shifts.php` by `php artisan gds:sync-shift-contexts`.
- **An entry is stamped with the shift** it falls in, derived from the window and
  the entry's timestamp — so a window that crosses midnight is handled correctly.
- **The screen is gated to the open shift.** You record against the shift that is
  currently open for that area; recording into a different shift needs the
  **`bypass-shift`** ability (held by supervisors), which also covers entering
  last night's run the next morning alongside `backdate`.

## Which screens are shift-gated

Any entry screen whose page lists `bypass-shift` among its abilities, e.g.
BIL Raw Materials **Factory Entrance** / **Consumption**, BIL Jumbo Rolls
**Factory Entrance** / **Consumption** / **Returns**, BIL Finished Goods
**Conversion Output** / **Conversion Waste**. (BPL Production carries `backdate`
but records against its own machine run rather than a floor shift.)

## Gating a new page

Make the page shift-aware, give it the `bypass-shift` ability in
[`config/pages.php`](../../config/pages.php), and resolve the current shift for
its area when it writes. Configure the area's windows under Shift Settings.

See [Access control](framework-rbac.md) for how `bypass-shift` is granted.
