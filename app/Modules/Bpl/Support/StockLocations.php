<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;

/**
 * The BPL places a roll can be: the two paper machine floors (`type` 0) and
 * the three stores (`type` 1), as `bpl_stock_locations` records them.
 *
 * A movement row carries only `location_id`, so every screen and every report
 * has to turn that integer into a name. The name now lives in TWO places — the
 * core `warehouses` master (which the gate model is built on) and the legacy
 * `bpl_stock_locations` — so the rule for resolving one is written here once
 * rather than copied into each query that needs it.
 */
class StockLocations
{
    /** Legacy `type` values: 0 = a paper machine floor, 1 = a store. */
    public const FLOOR = 0;
    public const STORE = 1;

    /**
     * SQL that resolves a `location_id` expression to a display name: the core
     * warehouse name first, then the legacy location name, then the bare id.
     *
     * A scalar subquery rather than a join, so it cannot multiply rows, and
     * scoped by company: `legacy_location_id` is only unique WITHIN a company —
     * the BIL raw-material stores use the same small integers — so the scoping
     * is load-bearing.
     */
    public static function nameExpr(string $legacyIdExpr): string
    {
        static $companyId = null;

        $companyId ??= (int) DB::connection('core')->table('companies')->where('code', 'BPL')->value('id');

        return 'COALESCE('
            . "(SELECT `name` FROM `core`.`warehouses` WHERE `legacy_location_id` = {$legacyIdExpr}"
            . " AND `company_id` = {$companyId} AND `deleted_at` IS NULL LIMIT 1),"
            . "(SELECT `location` FROM `bpl_stock_locations` WHERE `id` = {$legacyIdExpr} LIMIT 1),"
            . "CONCAT('Location #', {$legacyIdExpr}))";
    }

    /**
     * `location_id` => display name, for the places of a given type (or every
     * place when `$type` is null).
     *
     * Keyed by the id rather than the name so a filter built from this matches
     * on the indexed `location_id` column, not on the resolved name — which is
     * a pair of scalar subqueries and could only be matched with a HAVING.
     */
    public static function options(?int $type = null): array
    {
        $companyId = (int) DB::connection('core')->table('companies')->where('code', 'BPL')->value('id');

        $names = DB::connection('core')->table('warehouses')
            ->whereNull('deleted_at')
            ->whereNotNull('legacy_location_id')
            ->where('company_id', $companyId)
            ->pluck('name', 'legacy_location_id');

        $out = [];

        foreach (DB::connection('bpl')->table('bpl_stock_locations')
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderBy('id')->get(['id', 'location']) as $row) {
            $out[(int) $row->id] = (string) ($names[$row->id] ?? $row->location);
        }

        asort($out);

        return $out;
    }
}
