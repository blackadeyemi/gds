<?php

namespace Modules\Core\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FactoryExitLocation;
use Modules\Core\Models\User;
use Modules\Core\Models\WarehouseEntrance;

/**
 * Which gates a user may pick from on the scanning screens.
 *
 * Replaces the legacy `switch ($_SESSION['userlevel'])` that hard-coded which
 * store floor or factory gate each user level could see. Grants are now rows in
 * `warehouse_entrance_user` / `factory_exit_location_user`, ticked per user in
 * the user editor.
 *
 * This narrows a dropdown; it is NOT access control. Whether a user may open a
 * receiving or exit screen at all is the `page:` middleware's job. Both layers
 * apply: no page permission means no screen, and no gates means a screen with
 * nothing to choose.
 *
 * **Admin sees every gate**, consistent with `User::isAdmin()` bypassing every
 * page ability — an admin is never locked out of their own configuration.
 *
 * Only USABLE gates are offered: active, and attached to a parent. An entrance
 * with no warehouse has nowhere to put stock; an exit location with no factory
 * cannot say where goods left from.
 *
 * Lives in Core rather than a module because warehouses and factory gates are
 * company-wide structure — BPL's screens will use exactly this.
 */
class GateAccess
{
    /** Warehouse entrances this user may receive through. */
    public static function entrancesFor(?User $user): Collection
    {
        return self::scope(
            WarehouseEntrance::query()->with('warehouse')->usable()->ordered(),
            $user,
            'warehouse_entrance_user',
            'entrance_id'
        );
    }

    /** Factory exit locations this user may send goods out through. */
    public static function exitLocationsFor(?User $user): Collection
    {
        return self::scope(
            FactoryExitLocation::query()->with('factory')->usable()->ordered(),
            $user,
            'factory_exit_location_user',
            'exit_location_id'
        );
    }

    /**
     * Apply the grant filter unless the user is Admin.
     *
     * A null user (no session) gets nothing rather than everything — failing
     * closed matters more here than convenience.
     */
    protected static function scope($query, ?User $user, string $pivot, string $column): Collection
    {
        if (! $user) {
            return collect();
        }

        if ($user->isAdmin()) {
            return $query->get();
        }

        $granted = DB::connection('core')->table($pivot)
            ->where('user_id', $user->userid)->pluck($column);

        return $query->whereIn('id', $granted)->get();
    }
}
