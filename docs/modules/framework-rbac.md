# Framework — Access control (RBAC)

Access is **per page, per ability**. A *page* is the unit of access (a gated
screen); its *abilities* are the actions on it. There are no ad-hoc permission
checks scattered through the code — everything routes through this one model.

---

## How it fits together

```
config/pages.php          declares pages + the abilities each supports
      │  gds:sync-pages
      ▼
core.pages + permissions  one permission per "{page.key}:{ability}"
      │  granted in the Roles matrix (page × ability)
      ▼
page: middleware          guards the route  (view ability)
@canPage / @canPrefix     guards the nav + in-page controls
```

- **`view`** is access — it drives the `page:` route middleware and whether the
  nav item shows. The rest (`create`, `edit`, `delete`, `export`) are the usual
  actions; **special abilities** live only on the pages that support them, so they
  never appear where they don't apply: `backdate`, `approve`, `confirm`, `cancel`,
  `modify`, `return`, `reopen`, `bypass-shift`, `bypass-waste-lock`.
- **Admin bypasses.** A role with `legacy_level` 1 is Admin and sees everything.
- **Keys mirror routes** with `-` → `_`:
  `bil.raw-materials.warehouse-entry` ↔ `bil.raw_materials.warehouse_entry`.
- **`gates` hint.** A page tagged `'gates' => 'warehouse'|'factory'` tells the
  **user editor** to offer that gate checklist; it is a UI hint, not access
  control (`GateAccess` decides what a user may pick — see
  [Warehouses & gates](framework-warehouses-and-gates.md)).

## Adding a page — the checklist

1. Register it in [`config/pages.php`](../../config/pages.php) (key, label,
   module, route, abilities, and `gates` if it has a gate dropdown).
2. `php artisan gds:sync-pages` — materialises the page + `{key}:{ability}`
   permissions.
3. Put `page:{key}` middleware on the route.
4. Gate the nav with `@canPage('{key}')` / `@canPrefix('{prefix}.')`, and gate
   in-page actions with the matching ability check.
5. **Grant it** in the Roles matrix — until then it is invisible to everyone but
   Admin. *This step is the one people forget.*

See the live registry and rationale inline in `config/pages.php`, and the Admin
side in [Admin & Settings](core-admin-and-settings.md).
