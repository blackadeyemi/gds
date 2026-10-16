<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;

/**
 * What went on a truck, and what the weighbridge made of it.
 *
 * One row per waybill, shared by the Logistics reports and the Logistics
 * dashboard so a figure cannot mean one thing on a list and something else on
 * a chart.
 *
 * ## The three weights
 *
 *   tare   `bpl_delivery`.`truck_weight`          the empty truck
 *   full   `bpl_delivery_barcode`.`full_weight`   the loaded truck
 *   rolls  summed from `bpl_production`           what was scanned onto it
 *
 * `net` is full − tare, and `variance` is net − rolls: what the weighbridge
 * says the load weighs, against what the rolls say it should. The Weighbridge
 * screen refuses a net more than 150 kg SHORT of the rolls and deliberately
 * sets no upper bound, because 22 real loads came out more than 150 kg heavy —
 * one by 13.7 tonnes. Those are exactly the rows the variance views list.
 *
 * ## Every weight is a varchar
 *
 * `truck_weight` and `full_weight` are `varchar(255)`, so they are CAST before
 * any arithmetic. An unweighed waybill stores `'0'` or an empty string rather
 * than NULL, which is why "weighed" is tested as `> 0` and not as NOT NULL.
 *
 * ## And the dates disagree with each other
 *
 * `bpl_delivery`.`date` is the usual legacy `Y/m/d`, but
 * `bpl_delivery_barcode`.`date` is **`yy-mm-dd`** — the waybill number itself
 * is `DD-MM-YY-BPD-NNN`, and the column was written to match the number rather
 * than the rest of the schema. Anything date-ranged therefore goes through the
 * DELIVERY's date; the waybill's own is never compared.
 */
class DeliveryFigures
{
    /** Kilograms the net may fall short of the rolls before it is suspect. */
    public const TOLERANCE = 150;

    /** The rolls on a waybill, weighed as production weighed them. */
    private const ROLLS = 'SELECT `dd`.`delivery_barcode`,'
        . ' COUNT(*) as `rolls`,'
        . ' ROUND(COALESCE(SUM(`r`.`weight`), 0), 2) as `roll_weight`,'
        . ' COUNT(DISTINCT `r`.`product_id`) as `products`'
        . ' FROM `bpl_delivery_details` `dd`'
        . ' LEFT JOIN `bpl_production` `r` ON `r`.`barcode` = `dd`.`barcode` AND `r`.`deleted_at` IS NULL'
        . ' GROUP BY `dd`.`delivery_barcode`';

    /**
     * One row per waybill: the load, the truck, the three weights and the
     * variance between the last two.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function waybills()
    {
        $tare = "CAST(NULLIF(`d`.`truck_weight`, '') AS DECIMAL(14,2))";
        $full = "CAST(NULLIF(`w`.`full_weight`, '') AS DECIMAL(14,2))";
        $net = "({$full} - {$tare})";

        return DB::connection('bpl')->table('bpl_delivery_barcode as w')
            // A waybill reaches its delivery through its LINES, which is the
            // only link the legacy ever wrote — there is no delivery id on the
            // waybill row itself.
            ->leftJoin(DB::connection('bpl')->raw(
                '(SELECT `delivery_barcode`, MIN(`bpl_delivery_id`) as `delivery_id`'
                . ' FROM `bpl_delivery_details` GROUP BY `delivery_barcode`) `link`'
            ), 'link.delivery_barcode', '=', 'w.delivery_barcode')
            ->leftJoin('bpl_delivery as d', 'd.id', '=', 'link.delivery_id')
            ->leftJoin(DB::connection('bpl')->raw('(' . self::ROLLS . ') `rl`'),
                'rl.delivery_barcode', '=', 'w.delivery_barcode')
            ->selectRaw(
                '`w`.`id`, `w`.`delivery_barcode` as `waybill`, `w`.`status`,'
                . ' `d`.`id` as `delivery_id`,'
                // The DELIVERY's date, which is the legacy Y/m/d one; the
                // waybill's own column is yy-mm-dd and is never compared.
                . ' `d`.`date`,'
                . " COALESCE(`d`.`customer_name`, '') as `customer`,"
                . " COALESCE(`d`.`driver`, '') as `driver`,"
                . " COALESCE(`d`.`driver_phone`, '') as `driver_phone`,"
                . " COALESCE(`d`.`vechile_number`, '') as `vehicle`,"
                . " COALESCE(`d`.`container_number`, '') as `container`,"
                . " COALESCE(`d`.`loader`, '') as `loader`,"
                . ' COALESCE(`rl`.`rolls`, 0) as `rolls`,'
                . ' COALESCE(`rl`.`products`, 0) as `products`,'
                . ' ROUND(COALESCE(`rl`.`roll_weight`, 0), 2) as `roll_weight`,'
                . ' ' . $tare . ' as `tare`,'
                . ' ' . $full . ' as `full_weight`,'
                // Only once the loaded truck has been weighed does a net mean
                // anything; an unweighed waybill stores '0' or '', not NULL.
                . " CASE WHEN COALESCE({$full}, 0) > 0 THEN ROUND({$net}, 2) END as `net_weight`,"
                . " CASE WHEN COALESCE({$full}, 0) > 0"
                . " THEN ROUND({$net} - COALESCE(`rl`.`roll_weight`, 0), 2) END as `variance`,"
                . " COALESCE({$full}, 0) > 0 as `weighed`"
            );
    }

    /** Is this an internal move to Belimpex, which nothing weighs? */
    public static function internalCustomer(): string
    {
        return \Modules\Bpl\Models\BplDelivery::INTERNAL_CUSTOMER;
    }
}
