<?php

namespace Modules\Core\Support;

/**
 * The company module the current request is operating as — 'bil' (default) or
 * 'bhn'. BHN reuses the entire Bil module; ResolveCompanyModule rebinds the
 * `bil` database connection + `bil` config to BHN's when this is 'bhn', so the
 * shared models/components read and write BHN's schema transparently.
 *
 * This holds the pieces that are NOT the connection: the module key for page
 * keys, route names and nav. Request-scoped (set once per request by the
 * middleware); safe under php-fpm.
 */
class ModuleContext
{
    /** Known company modules that reuse the Bil screens, by URL prefix. */
    public const MODULES = ['bil', 'bhn'];

    protected static string $key = 'bil';

    public static function set(string $key): void
    {
        static::$key = in_array($key, self::MODULES, true) ? $key : 'bil';
    }

    public static function key(): string
    {
        return static::$key;
    }

    public static function isBhn(): bool
    {
        return static::$key === 'bhn';
    }

    /** A page/permission key for the current module: pageKey('sales.orders'). */
    public static function pageKey(string $suffix): string
    {
        return static::$key.'.'.$suffix;
    }

    /** A route name for the current module: routeName('sales.orders'). */
    public static function routeName(string $suffix): string
    {
        return static::$key.'.'.$suffix;
    }

    /** A value from the current module's config file (config/bil.php|bhn.php). */
    public static function config(string $suffix, mixed $default = null): mixed
    {
        return config(static::$key.'.'.$suffix, $default);
    }
}
