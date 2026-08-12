<?php

namespace Modules\Core\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FactoryGate;
use Modules\Core\Models\User;
use Modules\Core\Models\WarehouseGate;

/**
 * Which gates a user may pick from on the scanning screens.
 *
 * Replaces the legacy `switch ($_SESSION['userlevel'])` that hard-coded which
 * store floor or factory gate each user level could see, and the four screens
 * that simply hard-coded a `LOCATION_ID` constant. Grants are rows in
 * `warehouse_gate_user` / `factory_gate_user`, ticked per user in the user
 * editor.
 *
 * This narrows a dropdown; it is NOT access control. Whether a user may open a
 * screen at all is the `page:` middleware's job. Both layers apply: no page
 * permission means no screen, and no gates means a screen with nothing to
 * choose — which the screens say out loud rather than rendering an empty select.
 *
 * **Admin sees every gate**, consistent with `User::isAdmin()` bypassing every
 * page ability — an admin is never locked out of their own configuration.
 *
 * Only USABLE gates are offered: active, and attached to a parent. A warehouse
 * gate with no warehouse has no stock to move; a factory gate with no factory
 * cannot say where goods went.
 */
class GateAccess
{
    /**
     * Warehouse gates for a module and direction.
     *
     * `$module` is a config/warehouses.php key — the caller's own module, so
     * the finished-goods screens never offer a raw-materials gate and vice
     * versa. `$direction` is 'in' or 'out'; a gate marked 'both' matches either.
     */
    public static function warehouseGates(?User $user, string $module, string $direction): Collection
    {
        return self::scope(
            WarehouseGate::query()->with('warehouse')
                ->usable()->forModule($module)->direction($direction)->ordered(),
            $user,
            'warehouse_gate_user'
        );
    }

    /** Factory gates for a direction ('in' = goods arriving, 'out' = leaving). */
    public static function factoryGates(?User $user, string $direction): Collection
    {
        return self::scope(
            FactoryGate::query()->with('factory')->usable()->direction($direction)->ordered(),
            $user,
            'factory_gate_user'
        );
    }

    /**
     * Apply the grant filter unless the user is Admin.
     *
     * A null user (no session) gets nothing rather than everything — failing
     * closed matters more here than convenience.
     */
    protected static function scope($query, ?User $user, string $pivot): Collection
    {
        if (! $user) {
            return collect();
        }

        if ($user->isAdmin()) {
            return $query->get();
        }

        $granted = DB::connection('core')->table($pivot)
            ->where('user_id', $user->userid)->pluck('gate_id');

        return $query->whereIn('id', $granted)->get();
    }
}
