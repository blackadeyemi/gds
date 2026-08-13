<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Runtime-editable settings, layered over `config`.
 *
 * A row in `app_settings` overrides the config value of the same key; with no
 * row, the config value (and therefore .env) stands. That ordering is the
 * important part: a fresh environment behaves exactly as its .env says until
 * somebody deliberately changes it in the UI, and clearing the override puts it
 * back rather than leaving it stuck on whatever was last typed.
 *
 * Keep this for the few values operations genuinely need to move between
 * deploys. Everything else belongs in config/, where it is reviewed.
 *
 * Reads are memoised per request — `cutover()` is called on every block check,
 * and that must not become a query per pallet.
 */
class Settings
{
    /** @var array<string,mixed>|null */
    protected static ?array $cache = null;

    /** All overrides, loaded once per request. */
    protected static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            return self::$cache = DB::connection('core')->table('app_settings')
                ->pluck('value', 'key')->all();
        } catch (\Throwable) {
            // Before the migration has run — fall back to config rather than
            // taking the app down.
            return self::$cache = [];
        }
    }

    /** The override if one is set, otherwise the config value. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $overrides = self::all();

        if (array_key_exists($key, $overrides) && $overrides[$key] !== null && $overrides[$key] !== '') {
            return $overrides[$key];
        }

        return config($key, $default);
    }

    /** The config/.env value, ignoring any override — what "revert" restores. */
    public static function configured(string $key, mixed $default = null): mixed
    {
        return config($key, $default);
    }

    public static function isOverridden(string $key): bool
    {
        $o = self::all();

        return array_key_exists($key, $o) && $o[$key] !== null && $o[$key] !== '';
    }

    public static function set(string $key, mixed $value): void
    {
        DB::connection('core')->table('app_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => (string) $value,
                'updated_by' => auth()->id(),
                // The User model's display field is `username`; `name` is null on
                // every row, so taking it alone records nobody.
                'updated_by_name' => auth()->user()?->username ?? auth()->user()?->name,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        self::$cache = null;
    }

    /** Drop the override, falling back to config/.env. */
    public static function forget(string $key): void
    {
        DB::connection('core')->table('app_settings')->where('key', $key)->delete();
        self::$cache = null;
    }

    /** Who last changed a setting, and when — for showing on the page. */
    public static function meta(string $key): ?object
    {
        return DB::connection('core')->table('app_settings')->where('key', $key)->first();
    }

    /** Only for tests that change a setting mid-process. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
