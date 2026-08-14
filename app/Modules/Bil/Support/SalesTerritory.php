<?php

namespace Modules\Bil\Support;

use Modules\Bil\Models\SalesDesignation;
use Modules\Core\Support\Geography;

/**
 * BIL's sales territories: a REGION divided into DESIGNATIONS.
 *
 *   LAGOS  → LAGOS 1, LAGOS 2
 *   NORTH  → NORTH 1 … NORTH 4
 *   EAST   → EAST 1, EAST 2
 *   SOUTH  → SOUTH
 *   WEST   → WEST
 *
 * ⚠️ **A territory is a division of NIGERIA, not of the world.** These are the
 * areas BIL's own sales force covers; a customer in Ghana is not in "WEST" any
 * more than they are in "LAGOS 2". So the pair applies to Nigerian customers
 * and is null for everyone else — which is also what makes the by-territory
 * sales reports mean anything.
 *
 * Kept out of the Livewire component because three different things need the
 * same answers: the customer form, the customer grid's "unclassified" view, and
 * (next) the sales reports that group by territory.
 *
 * The list lives in the legacy `sales_designation` table. There is no editor for
 * it in either app — ten rows that have not changed in years.
 */
final class SalesTerritory
{
    /** The one country these territories divide. */
    public const COUNTRY = Geography::DEFAULT_COUNTRY;

    /** @var array<string,string[]>|null region => designations */
    protected static ?array $memo = null;

    /** Do territories apply to a customer in this country at all? */
    public static function appliesTo(?string $country): bool
    {
        return strcasecmp(trim((string) $country), self::COUNTRY) === 0;
    }

    /** region => [designation, …] */
    public static function map(): array
    {
        return self::$memo ??= SalesDesignation::byRegion();
    }

    public static function regions(): array
    {
        return array_keys(self::map());
    }

    /** Designations under one region, or [] for no/unknown region. */
    public static function designationsIn(?string $region): array
    {
        return self::map()[trim((string) $region)] ?? [];
    }

    /** Every designation, regardless of region. */
    public static function designations(): array
    {
        return self::map() === [] ? [] : array_merge(...array_values(self::map()));
    }

    public static function isRegion(?string $region): bool
    {
        return $region !== null && $region !== '' && isset(self::map()[$region]);
    }

    /**
     * Is this a designation the territory list has never heard of?
     *
     * Several hundred customers carry a bare region name — "LAGOS", "WEST" —
     * from before the numbered territories existed. Those are history, not
     * mistakes: they are kept on the record that already has one, so an
     * unrelated edit does not silently blank the field. A designation the list
     * DOES know must still sit under its own region.
     */
    public static function isHistoric(?string $designation): bool
    {
        return $designation !== null && $designation !== ''
            && ! in_array($designation, self::designations(), true);
    }

    public static function belongsTo(?string $designation, ?string $region): bool
    {
        return $designation !== null && $designation !== ''
            && in_array($designation, self::designationsIn($region), true);
    }

    /** Tests; the ten rows never change inside a request. */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
