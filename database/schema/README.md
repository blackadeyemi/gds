# Legacy schema (documentation only)

Structure-only (`--no-data`) dumps of the **legacy** databases this app connects
to, kept as documentation of their shape. **No rows** — nothing sensitive.

| File | Database | Notes |
|------|----------|-------|
| `bil-schema.sql` | `bil` | Legacy BIL ERP. The invalid split-leftover view `bpl_products` is skipped. |
| `bpl-schema.sql` | `bpl` | Legacy BPL data (jumbo rolls, etc.). |

The **`core`** database (this app's own tables — auth, pages, shifts, companies)
is **not** dumped here: it is defined authoritatively by `database/migrations`.
Run `php artisan migrate` to build it.

These files are **not authoritative** and can drift from the live schema — they
are a convenience snapshot, not a source of truth. Do not restore data from here.

## Regenerate

```bash
mysqldump --no-data --skip-comments --skip-lock-tables --no-tablespaces \
  --force --routines --events -u root bil > database/schema/bil-schema.sql
mysqldump --no-data --skip-comments --skip-lock-tables --no-tablespaces \
  --force --routines --events -u root bpl > database/schema/bpl-schema.sql
```

> Full data dumps are **never** committed — they are large (1 GB+) and contain
> real production data. See the database-dump rules in the repo `.gitignore`.
