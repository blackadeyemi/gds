# Core — Admin & Settings

The platform layer every module sits on: who the users are, what they may do, the
organisational structure, and the configuration that drives the app. These pages
sit **last** in the nav because they are set up once and rarely touched, while BIL
and BPL pages are the day-to-day work.

- **Connection:** `core` (the app's own data).
- **Access:** these are gated pages like any other; Admin (role `legacy_level` 1)
  sees them all. See [Access control](framework-rbac.md).

---

## Admin

The organisational structure — the two spines plus storage.

| Page | What it is |
| --- | --- |
| **Users** (`admin.users`) | Accounts; assign a role, company and department. |
| **Roles** (`admin.roles`) | The **page × ability matrix** — this is where access is actually granted. |
| **Departments** (`admin.departments`) | Top of the people spine. |
| **Divisions** (`admin.divisions`) | Within a department. |
| **Staff** (`admin.staff`) | People, under a division. |
| **Companies** (`admin.companies`) | Top of the machine spine (BIL, BPL, BOU). |
| **Factories** (`admin.factories`) | Within a company. |
| **Warehouses** (`admin.warehouses`) | Stores goods move through. |
| **Warehouse Gates** (`admin.warehouse_gates`) | Inbound/outbound doors on a warehouse. |
| **Factory Gates** (`admin.factory_gates`) | Doors onto/off a factory floor. |

Lines and Projects (the rest of the machine spine) are edited under
[BIL → Machines](bil-machines.md); warehouses and gates are explained in
[Warehouses & gates](framework-warehouses-and-gates.md).

## Settings

Configuration that drives behaviour elsewhere.

| Page | What it configures |
| --- | --- |
| **Pages** (`settings.pages`) | Read-out of the page registry; where `gds:sync-pages` lands. |
| **Data Views** (`settings.data-views`) | Admin-editable column/label overrides for every [DataGrid](framework-building-blocks.md); fed by `gds:sync-data-views`. |
| **Shift Settings** (`settings.shifts`) | The Day/Night windows per area — see [Shifts](framework-shifts.md). |
| **Service Types** (`settings.service-types`, CRUD) | The machine-service catalogue behind BIL → Machines → Services. |
| **Waste Settings** (`settings.waste`, view/edit) | Two lists (waste **causes** + **origins**) edited inline, used by Conversion Waste. |

---

### Traps

- **A new page is invisible until granted.** Registering a page and running
  `gds:sync-pages` does not make it appear — someone must grant it in the Role
  matrix. This is the single most common "the deploy didn't work".
- **Roles are the access surface.** Don't look for permissions anywhere else; the
  matrix on the Roles page is where `{page.key}:{ability}` grants are made.
