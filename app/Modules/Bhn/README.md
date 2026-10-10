# BHN module

BHN is a third company (core.companies id 4, code `BHN`), based in **Abuja**,
that operates like BIL — it **converts** hardroll into finished goods and
**receives jumbo rolls from BPL**.

- **Connection:** `bhn` (its own schema, separate from the `bilfg` split that
  produced `core`/`bil`/`bpl`). Modelled from BHN's own legacy dump.
- **Views namespace:** `bhn::` · **Routes:** `routes/bhn.php` (`/bhn`, `bhn.*`).
- **Structure:** mirrors `app/Modules/Bil` — `Livewire/`, `Models/`, `Views/`.
- **Production:** homed to the Abuja server; see memory `gds-abuja-multiserver`
  and the Abuja Replica Plan for the two-server / routing-page design.

This is the foundation scaffold; screens are built out from here.
