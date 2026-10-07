<?php

namespace Modules\Core\Support\Activity;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Records what signed-in users change, without any page having to ask.
 *
 * HOW A CHANGE IS SEEN. Two sources, because gds writes both ways:
 *
 *   - Eloquent model events (created / updated / deleted) give the full
 *     picture: the record's key, its values before and after.
 *   - QueryExecuted catches everything else — `DB::table()->update()`, raw
 *     `INSERT … ON DUPLICATE KEY`, mass `Model::where()->update()` — from the
 *     SQL and its bindings (see SqlWrite). Less detail, but nothing is missed.
 *
 *   A model write fires BOTH: the query first, then the model event. The
 *   model event replaces the query's item, so each change is logged once, in
 *   its richer form.
 *
 * WHAT ONE ACTIVITY IS. One Livewire method call — a click on Save, Approve,
 * Delete — and every change it made. Livewire's `call` hook brackets the call;
 * changes made outside one (a controller, a page's mount) are grouped per
 * request instead.
 *
 * ONLY COMMITTED CHANGES. Each change is tagged with its connection's
 * transaction depth. A rollback drops the changes above the depth it returns
 * to; a commit folds them down. Only depth-0 changes — committed, or never in
 * a transaction — are written. A failed batch therefore leaves no log entry
 * for rows that are not there. (This is why the MyISAM tables had to go: on
 * those, a "rolled back" row stayed written but would not have been logged.)
 *
 * NEVER FROM THE CONSOLE. Artisan commands, migrations, tinker and the test
 * suite write to the live databases constantly; none of that is a person's
 * work. Tests that exercise this class turn it on with forceEnabled().
 *
 * A failure to log never fails the user's action: the change has already
 * committed by the time the log is written, and errors are reported, not thrown.
 */
class ActivityRecorder
{
    /** @var list<array> changes not yet written */
    private array $pending = [];

    /** connection name => index in $pending of its last query-builder change */
    private array $lastQuery = [];

    /** @var list<array> open Livewire calls, innermost last */
    private array $calls = [];

    private int $ctxSeq = 0;

    /** request object id => the context of changes made outside any call */
    private array $requestCtx = [];

    private bool $busy = false;

    private ?bool $forced = null;

    /** @var array<string,bool>|null */
    private ?array $ignore = null;

    public static function instance(): self
    {
        return app(self::class);
    }

    /** Wire the listeners. Called once from the service provider. */
    public static function register(): void
    {
        app()->singleton(self::class);

        Event::listen(QueryExecuted::class, fn (QueryExecuted $e) => self::instance()->onQuery($e));
        Event::listen(TransactionCommitted::class, fn ($e) => self::instance()->onTransactionEnd($e->connection, true));
        Event::listen(TransactionRolledBack::class, fn ($e) => self::instance()->onTransactionEnd($e->connection, false));

        foreach (['created', 'updated', 'deleted'] as $op) {
            Event::listen("eloquent.{$op}: *", function (string $event, array $payload) use ($op) {
                if (($model = $payload[0] ?? null) instanceof Model) {
                    self::instance()->onModel($model, $op);
                }
            });
        }

        Livewire::listen('call', function ($component, $method) {
            $recorder = self::instance();
            if (! $recorder->enabled()) {
                return null;
            }
            $recorder->beginCall($component, (string) $method);

            return function ($result) use ($recorder) {
                $recorder->endCall();

                return $result;
            };
        });

        Event::listen(Login::class, fn (Login $e) => self::instance()->onLogin($e));
        Event::listen(Failed::class, fn (Failed $e) => self::instance()->onFailedLogin($e));

        app()->terminating(fn () => self::instance()->flush());
    }

    /* ---------------- switches ---------------- */

    public function enabled(): bool
    {
        return $this->forced ?? ! app()->runningInConsole();
    }

    /** Tests: record even though this is the console. Pass null to restore. */
    public function forceEnabled(?bool $on = true): void
    {
        $this->forced = $on;
        $this->ignore = null;
    }

    /** Tests: forget everything pending. */
    public function reset(): void
    {
        $this->pending = [];
        $this->lastQuery = [];
        $this->calls = [];
        $this->requestCtx = [];
    }

    /* ---------------- Livewire calls ---------------- */

    public function beginCall(object $component, string $method): void
    {
        $this->calls[] = [
            'id' => ++$this->ctxSeq,
            'component' => $component::class,
            'method' => $method,
        ] + PageContext::forComponent($component);
    }

    public function endCall(): void
    {
        $call = array_pop($this->calls);
        if ($call) {
            $this->flush($call['id']);
        }
    }

    /* ---------------- capture ---------------- */

    public function onQuery(QueryExecuted $e): void
    {
        if ($this->busy || ! $this->enabled()) {
            return;
        }

        // Cheap gate first: most queries are reads.
        $head = strtolower(substr(ltrim($e->sql), 0, 7));
        if (! in_array(substr($head, 0, 6), ['insert', 'update', 'delete'], true) && $head !== 'replace') {
            return;
        }

        $this->busy = true;
        try {
            $parsed = SqlWrite::parse($e->sql, $e->bindings, $e->connection->getDatabaseName());
            if (! $parsed || $this->ignored($parsed['table']) || ! ($actor = $this->actor())) {
                return;
            }

            // A soft delete through the builder (`Model::whereKey($id)->delete()`)
            // is an UPDATE that only stamps deleted_at. To the person it was a delete.
            $set = array_diff_key($parsed['columns'], array_flip((array) config('activity.ignored_columns', [])));
            if ($parsed['op'] === 'updated' && array_keys($set) === ['deleted_at'] && $set['deleted_at'] !== null) {
                $parsed['op'] = 'deleted';
            }

            $ids = [];
            if ($parsed['op'] === 'created' && $parsed['rows'] === 1) {
                $id = $e->connection->getPdo()->lastInsertId();
                $ids = $id && $id !== '0' ? [$id] : [null];
            } elseif (isset($parsed['where']['id'])) {
                $ids = (array) $parsed['where']['id'];
            }
            $ids = $ids ?: [null];

            $fields = $parsed['op'] === 'created' ? $parsed['columns'] : $parsed['where'];
            $changes = match ($parsed['op']) {
                'created' => ['attributes' => $this->clean($parsed['columns'])],
                'updated' => ['after' => $this->clean($parsed['columns']), 'where' => $this->clean($parsed['where'])],
                'deleted' => ['where' => $this->clean($parsed['where'])],
            };

            foreach ($ids as $n => $id) {
                $this->pending[] = $this->item($e->connectionName, $e->connection->transactionLevel(), $actor, [
                    'op' => $parsed['op'],
                    'subject_type' => $parsed['table'],
                    'subject_id' => $id === null ? null : (string) $id,
                    'ref' => $this->ref($fields, $id),
                    'changes' => array_filter($changes, fn ($v) => $v !== []),
                    'source' => 'query',
                    'rows' => $n === 0 && count($ids) === 1 ? max(1, $parsed['rows']) : 1,
                    'creator' => null,
                ]);
            }
            $this->lastQuery[$e->connectionName] = array_key_last($this->pending);
        } catch (\Throwable $ex) {
            report($ex);
        } finally {
            $this->busy = false;
        }
    }

    public function onModel(Model $model, string $op): void
    {
        if ($this->busy || ! $this->enabled()) {
            return;
        }

        $this->busy = true;
        try {
            $conn = $model->getConnection();
            $name = $conn->getName();
            $type = $this->subjectType($model);

            // The query this event follows was logged as a bare query item;
            // this one says more, so it takes that item's place.
            $replace = null;
            if (isset($this->lastQuery[$name])) {
                $idx = $this->lastQuery[$name];
                if (isset($this->pending[$idx]) && $this->pending[$idx]['subject_type'] === $type) {
                    $replace = $idx;
                }
                unset($this->lastQuery[$name]);
            }

            if ($this->ignored($type) || ! ($actor = $this->actor())) {
                if ($replace !== null) {
                    unset($this->pending[$replace]);
                }

                return;
            }

            $attrs = $model->getAttributes();
            if ($op === 'updated') {
                $after = $this->clean($model->getChanges());
                if ($after === []) {
                    // Only timestamps moved — nothing a person changed.
                    if ($replace !== null) {
                        unset($this->pending[$replace]);
                    }

                    return;
                }
                $before = array_intersect_key($this->clean($model->getOriginal()), $after);
                $changes = ['before' => $before, 'after' => $after];
            } else {
                $changes = ['attributes' => $this->clean($attrs)];
            }

            $item = $this->item($name, $conn->transactionLevel(), $actor, [
                'op' => $op,
                'subject_type' => $type,
                'subject_id' => $model->getKey() === null ? null : (string) $model->getKey(),
                'ref' => $this->ref($attrs, $model->getKey()),
                'changes' => $changes,
                'source' => 'model',
                'rows' => 1,
                'creator' => $op === 'created' ? null : $this->creatorOf($model->getOriginal()),
            ]);

            if ($replace !== null) {
                $item['level'] = $this->pending[$replace]['level'];
                $this->pending[$replace] = $item;
            } else {
                $this->pending[] = $item;
            }
        } catch (\Throwable $ex) {
            report($ex);
        } finally {
            $this->busy = false;
        }
    }

    public function onTransactionEnd($connection, bool $committed): void
    {
        if ($this->pending === []) {
            return;
        }

        $name = $connection->getName();
        $level = $connection->transactionLevel();

        foreach ($this->pending as $i => $item) {
            if ($item['conn'] !== $name || $item['level'] <= $level) {
                continue;
            }
            if ($committed) {
                $this->pending[$i]['level'] = $level;
            } else {
                unset($this->pending[$i]);
            }
        }
    }

    /* ---------------- sign-ins ---------------- */

    public function onLogin(Login $e): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->writeEvent('Signed in', $e->user->getAuthIdentifier(), $e->user->username ?? null);
    }

    public function onFailedLogin(Failed $e): void
    {
        if (! $this->enabled()) {
            return;
        }

        $username = (string) ($e->credentials['username'] ?? '');
        $id = $this->writeEvent('Sign-in failed', $e->user?->getAuthIdentifier(), $username, 'For username "' . Str::limit($username, 60) . '"');

        if ($id) {
            ActivityNotifier::failedLogin($id, $username);
        }
    }

    private function writeEvent(string $verb, $userId, ?string $username, ?string $summary = null): ?int
    {
        $this->busy = true;
        try {
            return DB::connection('core')->table('activities')->insertGetId([
                'user_id' => $userId,
                'username' => $username,
                'action' => 'auth',
                'verb' => $verb,
                'summary' => $summary ?? $verb,
                'refs' => $username,
                'item_count' => 0,
                'ip' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
                'created_at' => now(),
            ]);
        } catch (\Throwable $ex) {
            report($ex);

            return null;
        } finally {
            $this->busy = false;
        }
    }

    /* ---------------- writing ---------------- */

    /**
     * Write every committed change — or only those made inside one call.
     */
    public function flush(?int $ctx = null): void
    {
        if ($this->pending === [] || $this->busy) {
            return;
        }

        $groups = [];
        foreach ($this->pending as $i => $item) {
            if ($item['level'] > 0 || ($ctx !== null && $item['ctx']['id'] !== $ctx)) {
                continue;
            }
            $groups[$item['ctx']['id']][] = $item;
            unset($this->pending[$i]);
        }
        $this->lastQuery = [];

        if ($groups === []) {
            return;
        }

        $this->busy = true;
        try {
            foreach ($groups as $items) {
                $this->persist($items);
            }
        } catch (\Throwable $ex) {
            report($ex);
        } finally {
            $this->busy = false;
        }
    }

    private function persist(array $items): void
    {
        $first = $items[0];
        $ctx = $first['ctx'];
        $actor = $first['actor'];
        $page = PageContext::page($ctx['page_key']);

        $ops = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $counts = [];
        $refs = [];
        $total = 0;
        foreach ($items as $it) {
            $ops[$it['op']] += $it['rows'];
            $counts[$it['op']][$it['subject_type']] = ($counts[$it['op']][$it['subject_type']] ?? 0) + $it['rows'];
            $total += $it['rows'];
            if ($it['ref'] !== null && count($refs) < 60) {
                $refs[$it['ref']] = true;
            }
        }
        $kinds = array_keys(array_filter($ops));

        $core = DB::connection('core');
        $activityId = $core->table('activities')->insertGetId([
            'user_id' => $actor['id'],
            'username' => $actor['username'],
            'page_key' => $ctx['page_key'],
            'module' => $page['module'] ?? null,
            'route' => $ctx['route'],
            'component' => $ctx['component'],
            'method' => $ctx['method'] ? Str::limit($ctx['method'], 80, '') : null,
            'action' => count($kinds) === 1 ? $kinds[0] : 'mixed',
            'verb' => ActivityText::verb($ctx['method'], $ops),
            'summary' => ActivityText::summary($counts),
            'refs' => Str::limit(implode(', ', array_keys($refs)), 990, '…') ?: null,
            'item_count' => $total,
            'ip' => $actor['ip'],
            'user_agent' => $actor['agent'],
            'created_at' => now(),
        ]);

        $rows = [];
        foreach (array_slice($items, 0, (int) config('activity.max_items', 200)) as $it) {
            $rows[] = [
                'activity_id' => $activityId,
                'op' => $it['op'],
                'subject_type' => Str::limit($it['subject_type'], 120, ''),
                'subject_id' => $it['subject_id'] === null ? null : Str::limit($it['subject_id'], 64, ''),
                'ref' => $it['ref'],
                'changes' => $it['changes'] ? json_encode($it['changes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'source' => $it['source'],
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $core->table('activity_items')->insert($chunk);
        }

        ActivityNotifier::forActivity($activityId, $ctx['page_key'], $page['module'] ?? null, $ctx['method'], $actor, $items);
    }

    /* ---------------- helpers ---------------- */

    private function item(string $conn, int $level, array $actor, array $data): array
    {
        return $data + [
            'conn' => $conn,
            'level' => $level,
            'actor' => $actor,
            'ctx' => $this->context(),
        ];
    }

    /** The call this change belongs to, or the request when there is none. */
    private function context(): array
    {
        if ($this->calls !== []) {
            return end($this->calls);
        }

        $key = spl_object_id(request());

        return $this->requestCtx[$key] ??= ['id' => -(++$this->ctxSeq), 'component' => null, 'method' => null]
            + PageContext::forRequest();
    }

    private function actor(): ?array
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'username' => $user->username ?? null,
            'ip' => request()->ip(),
            'agent' => Str::limit((string) request()->userAgent(), 250, ''),
        ];
    }

    private function subjectType(Model $model): string
    {
        $table = $model->getTable();

        return str_contains($table, '.') ? $table : $model->getConnection()->getDatabaseName() . '.' . $table;
    }

    private function ignored(string $qualified): bool
    {
        if ($this->ignore === null) {
            $this->ignore = array_fill_keys((array) config('activity.ignore_tables', []), true);
        }

        $bare = str_contains($qualified, '.') ? substr($qualified, strpos($qualified, '.') + 1) : $qualified;

        return isset($this->ignore[$qualified]) || isset($this->ignore[$bare]);
    }

    /** Drop noise columns, hide secrets, keep values short. */
    private function clean(array $values): array
    {
        $ignored = array_flip((array) config('activity.ignored_columns', []));
        $masked = array_flip((array) config('activity.masked_columns', []));

        $out = [];
        foreach ($values as $k => $v) {
            if (! is_string($k) || isset($ignored[$k])) {
                continue;
            }
            if (isset($masked[$k])) {
                $out[$k] = '••••';

                continue;
            }
            $v = SqlWrite::scalar($v);
            $out[$k] = is_string($v) && strlen($v) > 500 ? Str::limit($v, 500) : $v;
        }

        return $out;
    }

    private function ref(array $values, $id): ?string
    {
        foreach ((array) config('activity.ref_columns', []) as $col) {
            $v = $values[$col] ?? null;
            if (is_array($v)) {
                $v = $v[0] ?? null;
            }
            if ($v !== null && $v !== '' && is_scalar($v)) {
                return Str::limit((string) $v, 180, '…');
            }
        }

        return $id === null || $id === '' ? null : '#' . $id;
    }

    /** Who entered a legacy row, by the username column it carries. */
    private function creatorOf(array $original): ?string
    {
        foreach ((array) config('activity.creator_columns', []) as $col) {
            $v = $original[$col] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
