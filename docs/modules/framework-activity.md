# Activity & notifications

**Activity** (`/activity`, sidebar under Dashboard) is the record of who changed
what in gds and when. The **bell** in the top bar holds the short list of
changes a person should act on.

## What gets recorded

Every change a signed-in user makes through a gds page. **No page calls
anything.** `Modules\Core\Support\Activity\ActivityRecorder` sees each write two ways:

| source | catches | detail |
|---|---|---|
| Eloquent model events | `Model::create()`, `$model->update()`, `$model->delete()` | record id, values before and after, the whole row on delete |
| `QueryExecuted` | `DB::table()->…`, raw `INSERT … ON DUPLICATE KEY`, `Model::where()->update()` | table, the values written, the `WHERE` — the old values are not available |

A model write fires both. The model event replaces the query's entry, so each
change is logged once.

**One activity = one click.** Livewire's `call` hook brackets each component
method (`save`, `approve`, `deleteConfirmed` …), so a Save that writes 40 pallets is
one line in the feed (*"Saved · Created 40 conversion pallets"*) with 40 items
behind it. The first 200 items keep their details (`activity.max_items`), and
`item_count` holds the real total.

**Only committed changes.** Each change is tagged with its connection's
transaction depth. A rollback drops it; a commit keeps it. A batch that fails
leaves no log entry for rows that are not in the database. This works only on
InnoDB, which is why every MyISAM table was converted
(`2026_10_07_100000`).

**The page** comes from the router: each gated route already names its page key
in `page:{key}` middleware, and a full-page Livewire component *is* its route's
action. A nested component falls back to `Livewire::originalPath()`. A new page
is covered as soon as it has a route.

**Not recorded:**
- Artisan commands, migrations, tinker and the test suite (anything in the console).
- Writes made with no signed-in user.
- The tables in `activity.ignore_tables`. These include the stock caches, which
  are rewritten as a side effect of every movement and can be rebuilt from it.
- **The legacy bil app.** It writes to the same databases but runs none of this
  code. Database triggers cannot tell which user made a change, so this gap stays
  until each screen moves to gds.

Sign-ins and failed sign-ins are recorded too (`action = auth`). Only Admins can see them.

## Who sees what

| | sees |
|---|---|
| Admin | everything, including sign-ins |
| everyone else | activity on pages they can open (`Activity::scopeVisibleTo`) |

The same rule applies to the **History** panel at the foot of every editable
grid's Edit modal (`DataGrid::recordHistory()`). The grid finds its table from
the first view's query; override `historySubject()` if the first view is not the
table being edited.

## Notifications (the bell)

| reason | who gets it | when |
|---|---|---|
| `admin` | **Admins only** | a change on an Admin or Settings page by someone else; every 5th failed sign-in for one username within 15 minutes |
| `approval` | non-admin holders of the approving ability | a submit listed in `activity.approvals`: Factory Returns and Damaged Goods `save`, Receive Transfer `receive` |
| `your_record` | the non-admin who entered the record | someone else updated or deleted it. The creator comes from the log, or from the legacy row's `username` / `user` / `user_name` column if it predates the log |

Admins get **admin notices only**. They can already see everything in the feed.

A notification is never sent to the person who made the change, never about a
page the recipient cannot open, and never in a module they have muted (Activity →
**Notification settings**). The bell checks for new items every 60 seconds
(`wire:poll.60s.visible`): one indexed COUNT, with no push server.

To add an approval flow, add a rule to `config/activity.php → approvals`:
the page, the method that submits, and the ability that approves.

## Tables (core, InnoDB)

`activities` (one per action) → `activity_items` (one per record changed) →
`activity_notifications` (one per recipient); `activity_mutes`. Items and
notifications cascade with their activity.

Nobody can edit or delete the log from the app, Admin included: the Activity page
overrides `mayDo()` so only export (Admin) is offered.
**`gds:prune-activity --apply`** (scheduled daily, 03:00) removes entries older
than the retention period. Nothing else deletes from the log.

## Retention: Settings → Activity Log (Admin only)

The number of days to keep, **730 by default** (`ACTIVITY_RETENTION_DAYS`). An
Admin can change it at `/settings/activity-log` to any value from 30 to 3,650 days.
The value is stored in `app_settings` under `activity.retention_days`, layered
over config as described in `Support\Settings`. **Use default** removes the
override. Before saving, the page shows how many entries the next clean-up would
delete. The change is logged, and the other Admins are notified.

**Admin only, and no role can be given it.** The route sits behind the
`admin` middleware (`EnsureAdmin`) and is deliberately not in
`config/pages.php`, so the Role matrix has nothing to grant. The component also
checks for Admin on every request, because a Livewire update does not pass back
through the route middleware. `PageContext` records changes made on any
`admin`-middleware route under its route name in the **Settings** module, so they
are Admin-only to read and reach the other Admins' bells. Use the same pattern
for any future page that must never be given to a role.

`Support\Activity\Retention` is what the page and the command both read. It
also clamps a stored value that falls outside the bounds.

## Testing it

The recorder is off in the console, so the suite's writes are not logged. A test
of the log itself calls `ActivityRecorder::instance()->forceEnabled(true)` and
writes only to a scratch table. See `tests/Feature/ActivityLogTest.php`, which
creates `core.activity_probe`, deletes the log rows it caused **by id**, and drops
the table.
