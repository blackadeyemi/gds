# Launch GDS at Windows startup

A web app **cannot** add itself to OS startup — browsers forbid a page from
registering itself to run at login (there is no manifest field or JavaScript
API for it). So this is configured **on each machine**, once. Pick the option
that matches how your machines are managed.

> Replace `https://gds.test/` with your **production** URL everywhere below.
> `gds.test` is the development address only.

---

## Option A — Per-machine script (no admin, works everywhere)

Best when machines are **not** centrally managed. Run once per Windows user
account after the app is installed:

```powershell
powershell -ExecutionPolicy Bypass -File .\Install-GdsAutostart.ps1 -AppUrl "https://gds.belimpex.ng/"
```

This drops a shortcut in the user's Startup folder that opens GDS in an
app-style (chromeless) window at sign-in. It auto-detects Chrome, then Edge
(override with `-Browser chrome|edge`).

Undo:

```powershell
powershell -ExecutionPolicy Bypass -File .\Install-GdsAutostart.ps1 -Uninstall
```

You can hand this to users, or run it during your machine-setup routine.

---

## Option B — Enterprise policy (managed fleet, the real "any system" answer)

Best when machines are managed with **Group Policy / Intune**, or you can run
an admin script per machine. This **force-installs** the PWA *and* sets it to
**run at OS login** for every user — so any machine that receives the policy
gets the app, installed and auto-starting, with no per-user steps.

1. Edit `policies\chrome.reg` and/or `policies\edge.reg` — replace
   `https://gds.test/` with your production URL in **both** values (the
   `manifest_id` must equal the app's manifest id: origin + `/`).
2. Import as admin on each machine:

   ```powershell
   reg import .\policies\chrome.reg
   reg import .\policies\edge.reg
   ```

   Or push the same keys via GPO / Intune to the whole fleet.
3. Restart the browser and confirm at `chrome://policy` (or `edge://policy`)
   that `WebAppInstallForceList` and `WebAppSettings` are applied, and at
   `chrome://apps` that GDS shows **"Start app when you sign in"**.

Registry locations (for GPO/Intune equivalents):

| Browser | Key |
|---|---|
| Chrome  | `HKLM\SOFTWARE\Policies\Google\Chrome` |
| Edge    | `HKLM\SOFTWARE\Policies\Microsoft\Edge` |

Both take the string values `WebAppInstallForceList` and `WebAppSettings`
shown in the `.reg` files.

---

## Option C — Manual, per user (no scripts)

Anyone can do this themselves after installing the app:

1. Open `chrome://apps` (or `edge://apps`).
2. Right-click **Consumer Tissue Data System**.
3. Tick **"Start app when you sign in to your computer."**

---

## Notes

- **HTTPS is required** for the PWA and its service worker; make sure the
  production URL is served over HTTPS with a certificate the machines trust.
- Option A launches an app window pointed at the URL; Options B/C start the
  **installed** PWA instance. All three give the "opens on login" behaviour.
- macOS: the installed PWA has the same **"Start app when you sign in"** toggle
  under `chrome://apps`; there is no Startup-folder equivalent to Option A, so
  use Option C (or an MDM policy mirroring Option B).
