# Framework — Installed app (PWA)

GDS is an installable Progressive Web App. It stays a thin client to the central
server (no change to the Livewire/MySQL architecture) but can be installed on
Windows/Mac, shows loading feedback, knows when the connection drops, and can
launch at login.

---

## What's included

- **Install** — `public/manifest.webmanifest` (name, standalone display, theme
  colour, 192/512 + maskable icons) wired into the admin and login `<head>` via
  `core::partials.pwa`. Chrome/Edge offer **Install**; the app opens in its own
  window.
- **Service worker** — `public/sw.js`. Tuned for a live multi-user ERP: it
  **never caches dynamic pages or Livewire POSTs** (no stale/cross-user data),
  caches **only static assets** stale-while-revalidate (the latency win on slow
  links), and serves `public/offline.html` **only** when the network is down.
  Updates ship by bumping `VERSION` in `sw.js` — the new worker takes over and
  reloads clients once.
- **Identity** — the **Datastore** mark (a blue→cyan database cylinder): app
  icons + maskable + favicons under `public/images/pwa/`, the sidebar mark
  (`gds-mark.svg`) and the login lockup (`gds-logo.svg`).
- **Version** — `config('app.version')` (default `2.0`, override `APP_VERSION`)
  shown on the login footer and the sidebar footer.
- **Loading feedback** — a top progress bar for full-page navigations and every
  Livewire round-trip (search, filter, save), so a click always registers.
- **Connection indicator** — a topbar dot that turns into an amber **Offline**
  pill when `navigator.onLine` drops.

## Appearance & display prefs

Per-browser display preferences (see `core::` Appearance): **theme** and **font
size** are stored in `localStorage` and applied before paint; the **date format**
is a server-readable **cookie** (via `Prefs`) so reports/exports can format dates
the same way the UI does. Date *entry* and DB storage are unaffected — only the UI
and report views.

## Launch at login

A web app cannot register itself for OS startup. The OS-level options are shipped
under [`deploy/pwa-autostart/`](../../deploy/pwa-autostart/README.md): a per-user
Startup-folder script, managed-fleet Chrome/Edge policies (force-install +
run-on-login), and the manual per-user toggle.

---

### Production caveats (important)

- **HTTPS is required.** Service workers and install only work in a **secure
  context** — HTTPS (or `localhost`). A plain-HTTP LAN IP (e.g.
  `http://10.50.1.58/gds`) is **not** secure: the SW won't register and the app
  won't install. Serve over trusted HTTPS, or whitelist the origin with the
  Chrome/Edge `OverrideSecurityRestrictionsOnInsecureOrigin` policy.
- **Serve at the host root, not a subpath.** The manifest, `sw.js` (scope `/`) and
  `offline.html` use root-absolute paths. Under a `/gds` subpath they miss —
  give GDS its own vhost/root, or rework those paths to be base-relative.
