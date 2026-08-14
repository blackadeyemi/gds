<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where places come from: country → state/province → city.
 *
 * The single reader of the `core.geo_*` tables (see the migration for why they
 * replaced the legacy `countries`/`states` pair). Everything here answers a
 * question a form asks, and nothing here knows anything about customers,
 * suppliers or sales — it is reference data, shared by whoever needs an address.
 *
 * Lists are memoised per request. They are static reference data, so the same
 * form re-rendering three times in one Livewire round-trip should hit the
 * database once.
 *
 * Data by Countries States Cities Database (ODbL v1.0) —
 * https://github.com/dr5hn/countries-states-cities-database
 */
final class Geography
{
    /** Where the business is, and what a blank country falls back to. */
    public const DEFAULT_COUNTRY = 'Nigeria';

    /** A datalist nobody scrolls past; some states have thousands of cities. */
    public const MAX_CITY_SUGGESTIONS = 500;

    /** @var array<string,mixed> */
    protected static array $memo = [];

    /* ---------------- Countries ---------------- */

    /** Every country name, alphabetical — the closed list a picker offers. */
    public static function countries(): array
    {
        return self::once('countries', fn () => DB::connection('core')
            ->table('geo_countries')->orderBy('name')->pluck('name')->all());
    }

    public static function isCountry(?string $name): bool
    {
        return $name !== null && $name !== ''
            && in_array($name, self::countries(), true);
    }

    /**
     * International dialling code, e.g. "+234" for Nigeria, or null if unknown.
     *
     * For display beside a phone box. It is deliberately not part of a stored
     * phone number anywhere — see the Sales Customers form.
     */
    public static function dialCode(?string $country): ?string
    {
        $code = self::countryColumn($country, 'phonecode');

        return $code ? '+' . ltrim($code, '+') : null;
    }

    /** ISO2 code for a country name, e.g. "NG". The key the geo tables use. */
    public static function iso2(?string $country): ?string
    {
        return self::countryColumn($country, 'iso2');
    }

    protected static function countryColumn(?string $country, string $column): ?string
    {
        $country = trim((string) $country);
        if ($country === '') {
            return null;
        }

        return self::once("country.$column.$country", fn () => DB::connection('core')
            ->table('geo_countries')->where('name', $country)->value($column));
    }

    /* ---------------- States ---------------- */

    /**
     * States/provinces of a country, alphabetical. Empty for the handful of
     * countries the dataset has no subdivisions for — which is a real answer,
     * not a failure: the caller falls back to free text.
     */
    public static function states(?string $country): array
    {
        $iso = self::iso2($country);
        if (! $iso) {
            return [];
        }

        return self::once("states.$iso", fn () => DB::connection('core')
            ->table('geo_states')->where('country_code', $iso)
            ->orderBy('name')->pluck('name')->all());
    }

    /**
     * What this country calls its subdivisions — "state", "province",
     * "region"… Taken from the most common `type` among them so a label can
     * read "Province" for Canada and "State" for Nigeria. Null when mixed or
     * unknown.
     */
    public static function stateNoun(?string $country): ?string
    {
        $iso = self::iso2($country);
        if (! $iso) {
            return null;
        }

        return self::once("stateNoun.$iso", function () use ($iso) {
            $type = DB::connection('core')->table('geo_states')
                ->where('country_code', $iso)->whereNotNull('type')
                ->select('type')->groupBy('type')
                ->orderByRaw('COUNT(*) DESC')->value('type');

            return $type ? ucwords($type) : null;
        });
    }

    /* ---------------- Cities ---------------- */

    /**
     * Cities in a state, by the state's NAME — which is what records store.
     *
     * Two indexed lookups, no joins: name → state_code, then code → cities.
     * A state name the dataset does not recognise ("LAGOS STATE", of which this
     * customer list has 207) simply returns nothing, and the caller falls back
     * to whatever it knows locally.
     */
    public static function cities(?string $country, ?string $state): array
    {
        $iso = self::iso2($country);
        $state = trim((string) $state);

        if (! $iso || $state === '') {
            return [];
        }

        return self::once("cities.$iso.$state", function () use ($iso, $state) {
            $code = DB::connection('core')->table('geo_states')
                ->where('country_code', $iso)->where('name', $state)->value('state_code');

            if ($code === null) {
                return [];
            }

            return DB::connection('core')->table('geo_cities')
                ->where('country_code', $iso)->where('state_code', $code)
                ->orderBy('name')->limit(self::MAX_CITY_SUGGESTIONS)
                ->pluck('name')->all();
        });
    }

    /** Has the reference data been imported? Screens can say so if not. */
    public static function isLoaded(): bool
    {
        return self::once('loaded', fn () => DB::connection('core')
            ->table('geo_countries')->exists());
    }

    /* ---------------- Internals ---------------- */

    protected static function once(string $key, callable $fn)
    {
        return self::$memo[$key] ??= $fn();
    }

    /** Tests and long-running processes; a normal request never needs it. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
