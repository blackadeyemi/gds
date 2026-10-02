# Framework — Legacy refresh

Each month a fresh SQL dump comes from the legacy production server. The refresh
pipeline loads it into the `bil` / `bpl` databases (and the GDS data tables that
derive from them) **without** clobbering the columns GDS has added — so the two
apps stay in step while the rebuild continues.

- **Command:** `php artisan gds:refresh-legacy`
- **Config:** [`config/legacy_refresh.php`](../../config/legacy_refresh.php) — the
  allowlist of which legacy table maps to which `connection.target`, plus defaults
  (e.g. a source tag), nullable-review and allow-empty lists.

---

## How it works

```
dump ─► staging DB ─► (archive target) ─► TRUNCATE target ─► INSERT … SELECT
                                                              (column intersection)
                                             │
                                 BEFORE INSERT triggers re-derive line_id/factory_id…
```

- **Column intersection** — only the columns present in **both** the dump and the
  live table are copied, so GDS-added columns (hierarchy ids, `gate_id`,
  `received_at`, `source`) survive the refresh. Drift-proof in both directions.
- **Triggers re-derive ids.** The same name→id triggers the legacy app relies on
  fill `line_id` / `factory_id` from the name columns on insert.
- **Operational/transactional scope only.** The allowlist is deliberately limited
  to the moving operational tables; masters and GDS-only structures are left
  alone. GDS is still in development, so the refresh never touches GDS-owned data.
- **Flags:** `--dry-run`, `--only=<tables>`, `--force`, `--no-archive`,
  `--keep-staging`.

## The backfill trap

A production **restore wipes data-backfill migrations** (the one-off migrations
that *populate* columns) — but leaves them **marked as run** in the migrations
table. So after a refresh the columns are empty yet Laravel thinks the work is
done. Re-apply them with **`php artisan gds:replay-backfills`**.

**It is not only columns.** `fg-receipts` replays missing ROWS: the production
app still writes the legacy `bil.store_entrance`, and only
`bil:backfill-fg-receipts` copies those into gds's own receipt table, so a
refresh brings in arrivals gds never hears about. Same failure, same fix.

Feature tests fail for this rather than for any regression — after the
2026-10-02 refresh, nine of them: four BPL Proforma cases (a NULL
`bpl_sales.orderno` reaches a typed `string` property and Livewire unsets it),
the BPL Order Trail's nine ways, the BPL invoice-payment dates, and the jumbo
roll fixtures. All pass once the backfills are replayed. See
`dump-refresh-undoes-backfills` in memory. Run `gds:replay-backfills` after
every refresh.

---

### Traps

- **Truncate is real.** The target table is emptied before reload; the archive
  step is your safety net — don't `--no-archive` on production without a separate
  backup.
- **Empty columns after a refresh ≠ a bug.** It usually means the backfills need
  replaying.
