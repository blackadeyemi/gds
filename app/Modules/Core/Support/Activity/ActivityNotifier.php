<?php

namespace Modules\Core\Support\Activity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;

/**
 * Who should hear about an activity. Notifications are a SHORT list on
 * purpose — the feed already shows everything; the bell is for what a person
 * should act on.
 *
 *   admin        Admins, and only Admins, hear about changes made on an Admin
 *                or Settings page and about a run of failed sign-ins. That is
 *                ALL an Admin is notified of: they see every activity in the
 *                feed, so anything more would just be the feed again.
 *
 *   approval     Something was submitted that someone can approve (rules in
 *                config('activity.approvals')). Goes to holders of that page's
 *                approve ability.
 *
 *   your_record  Someone else updated or deleted a record you entered. The
 *                creator comes from the log, or — for a record from before the
 *                log — the username column the legacy row carries.
 *
 * Never to the person who did it, never about a page the recipient cannot
 * open, and never in a module the recipient has muted.
 */
class ActivityNotifier
{
    public static function forActivity(int $activityId, ?string $pageKey, ?string $module, ?string $method, array $actor, array $items): void
    {
        $out = []; // user id => [reason, note]

        if ($module !== null && in_array($module, (array) config('activity.admin_modules', []), true)) {
            $label = PageContext::page($pageKey)['label'] ?? $module;
            foreach (self::adminIds() as $id) {
                $out[$id] = ['admin', Str::limit($label . ' changed', 180)];
            }
        }

        if ($pageKey !== null) {
            foreach ((array) config('activity.approvals', []) as $rule) {
                if ($rule['page'] !== $pageKey || ! in_array($method, $rule['methods'], true)) {
                    continue;
                }
                foreach (self::holders(($rule['notify_page'] ?? $rule['page']) . ':' . ($rule['ability'] ?? 'approve')) as $id) {
                    $out[$id] ??= ['approval', Str::limit($rule['label'] ?? 'Awaiting your approval', 180)];
                }
            }

            foreach (self::creators($items) as $id => $note) {
                $out[$id] ??= ['your_record', $note];
            }
        }

        unset($out[(int) $actor['id']]);

        self::write($activityId, $out, $pageKey, $module);
    }

    /** Every `threshold`th failure inside the window tells the Admins. */
    public static function failedLogin(int $activityId, string $username): void
    {
        $cfg = (array) config('activity.failed_logins', []);
        $threshold = max(1, (int) ($cfg['threshold'] ?? 5));
        $minutes = max(1, (int) ($cfg['minutes'] ?? 15));

        $n = DB::connection('core')->table('activities')
            ->where('action', 'auth')->where('verb', 'Sign-in failed')
            ->where('refs', $username)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();

        if ($n === 0 || $n % $threshold !== 0) {
            return;
        }

        $note = Str::limit(sprintf('%d failed sign-ins for "%s" in %d minutes', $n, $username, $minutes), 180);
        $out = [];
        foreach (self::adminIds() as $id) {
            $out[$id] = ['admin', $note];
        }

        self::write($activityId, $out, null, null);
    }

    /* ---------------- recipients ---------------- */

    /** @return list<int> */
    private static function adminIds(): array
    {
        return User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))
            ->pluck('userid')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Non-admin holders of a "{page}:{ability}" permission.
     *
     * @return list<int>
     */
    private static function holders(string $permission): array
    {
        // Straight off the permission tables on `core`, not Spatie's
        // User::permission() scope: that resolves the name through the
        // Permission model's DEFAULT connection, which is not `core` everywhere
        // (the test suite points it at SQLite).
        $core = DB::connection('core');
        $pid = $core->table('permissions')->where('name', $permission)->value('id');
        if (! $pid) {
            return []; // permission not materialised (page not synced)
        }

        $type = (new User)->getMorphClass();
        $roles = $core->table('role_has_permissions')->where('permission_id', $pid)->pluck('role_id');
        $ids = $core->table('model_has_roles')->where('model_type', $type)->whereIn('role_id', $roles)->pluck('model_id')
            ->merge($core->table('model_has_permissions')->where('model_type', $type)->where('permission_id', $pid)->pluck('model_id'))
            ->map(fn ($id) => (int) $id)->unique()->all();

        return array_values(array_diff($ids, self::adminIds()));
    }

    /**
     * Creators of the records this activity updated or deleted.
     *
     * @return array<int,string> user id => note
     */
    private static function creators(array $items): array
    {
        $touched = array_values(array_filter($items, fn ($it) => $it['op'] !== 'created'));
        if ($touched === []) {
            return [];
        }

        // 1. The log: whoever created the record, if it was created since.
        $byLog = [];
        $keyed = array_values(array_filter($touched, fn ($it) => $it['subject_id'] !== null));
        foreach (array_chunk($keyed, 100) as $chunk) {
            $q = DB::connection('core')->table('activity_items as i')
                ->join('activities as a', 'a.id', '=', 'i.activity_id')
                ->where('i.op', 'created')
                ->where(function ($w) use ($chunk) {
                    foreach ($chunk as $it) {
                        $w->orWhere(fn ($x) => $x->where('i.subject_type', $it['subject_type'])->where('i.subject_id', $it['subject_id']));
                    }
                })
                ->get(['i.subject_type', 'i.subject_id', 'a.user_id']);
            foreach ($q as $r) {
                $byLog[$r->subject_type . '#' . $r->subject_id] = (int) $r->user_id;
            }
        }

        // 2. The legacy row's own username column.
        $names = array_unique(array_filter(array_column($touched, 'creator')));
        $byName = $names
            ? User::whereIn('username', $names)->pluck('userid', 'username')->map(fn ($id) => (int) $id)->all()
            : [];

        $mine = []; // user id => [op => n, sample]
        foreach ($touched as $it) {
            $uid = $byLog[$it['subject_type'] . '#' . $it['subject_id']] ?? ($byName[$it['creator'] ?? ''] ?? null);
            if (! $uid) {
                continue;
            }
            $mine[$uid]['n'] = ($mine[$uid]['n'] ?? 0) + 1;
            $mine[$uid]['op'] ??= $it['op'];
            $mine[$uid]['sample'] ??= $it;
        }

        $admins = array_flip(self::adminIds());
        $out = [];
        foreach ($mine as $uid => $m) {
            if (isset($admins[$uid])) {
                continue;
            }
            $s = $m['sample'];
            $out[$uid] = Str::limit($m['n'] === 1
                ? sprintf('Your %s %s was %s', ActivityText::subject($s['subject_type']), $s['ref'] ?? '', $s['op'])
                : sprintf('%d of your entries were changed', $m['n']), 180);
        }

        return $out;
    }

    /* ---------------- writing ---------------- */

    /** @param array<int,array{0:string,1:string}> $out */
    private static function write(int $activityId, array $out, ?string $pageKey, ?string $module): void
    {
        if ($out === []) {
            return;
        }

        $users = User::with('roles')->whereIn('userid', array_keys($out))->get()->keyBy('userid');
        $muted = $module === null ? [] : DB::connection('core')->table('activity_mutes')
            ->where('module', $module)->whereIn('user_id', array_keys($out))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->flip()->all();

        $rows = [];
        foreach ($out as $uid => [$reason, $note]) {
            $user = $users->get($uid);
            if (! $user || isset($muted[$uid])) {
                continue;
            }
            // Never about a page they cannot open. Admin notices about sign-ins
            // have no page; the recipients are Admins by construction.
            if ($pageKey !== null && ! $user->canAccessPage($pageKey)) {
                continue;
            }
            $rows[] = [
                'user_id' => $uid,
                'activity_id' => $activityId,
                'reason' => $reason,
                'note' => $note,
                'created_at' => now(),
            ];
        }

        if ($rows) {
            DB::connection('core')->table('activity_notifications')->insertOrIgnore($rows);
        }
    }
}
