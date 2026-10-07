<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;

/**
 * The money and the tonnage on a BPL sale, as SQL the reports can group by.
 *
 * ## Why this exists
 *
 * A shipment's value is not stored anywhere. The final invoice is computed at
 * print time (PackingList::finalInvoice) by walking the containers JSON in PHP
 * and pricing each lot against the proforma line that matches its description.
 * That is fine for one sheet and useless for a report: a grid needs to sort by
 * value, total a column, and group a year of shipments by customer, none of
 * which PHP-per-row can do.
 *
 * So the same arithmetic is written once here as SQL, and
 * `tests/Feature/BplSalesReportsPageTest` checks it against the PHP invoice for
 * every packing list in the database — 360 of them, agreeing to the kobo.
 *
 * ## The join is on the DESCRIPTION, because nothing else links them
 *
 * A container's goods carry `{rolls, weight, description}` and no product id.
 * The description was generated from the proforma line when the packing list
 * was written (ProformaInvoice::fullName), so it is the only link back to a
 * price — and it is a FROZEN COPY. Edit a product's specification afterwards
 * and the line no longer matches its own shipment.
 *
 * That has happened: 9 packing lists carry 168,570 kg that no current proforma
 * line prices. They are not hidden. `unpriced_weight` carries the weight that
 * found no price, the Shipments report has a view that lists exactly those
 * rows, and the value shown is what the printed invoice would show — because
 * the printed invoice drops those lines too.
 *
 * ## Charsets
 *
 * The product columns are latin1 and a JSON-extracted string takes the
 * connection's charset (utf8mb4), which MySQL refuses to compare — "Illegal
 * mix of collations". The JSON_TABLE column is therefore DECLARED latin1, so
 * the comparison is like for like. These are ASCII product specs; nothing can
 * be lost in the narrowing.
 */
class SalesFigures
{
    /**
     * The description a packing-list lot carries, rebuilt from the product —
     * the SQL twin of ProformaInvoice::fullName().
     *
     * MySQL renders a FLOAT in CONCAT the same way PHP casts one for that
     * method ('30', '16.5'), which is what lets the two agree; the test pins
     * it rather than trusting that.
     */
    public const PRODUCT_DESCRIPTION = "CONCAT(`p`.`gradetype`, ' ', `p`.`gsm`, 'gsm, ', `p`.`ply`, 'ply, ',"
        . " `p`.`width`, 'cm width, ', `p`.`diameter`, 'cm diameter, ',"
        . " `p`.`slice`, IF(`p`.`slice` = 1, ' slice', ' slices'))";

    /** The price on the proforma line whose description matches a shipped lot. */
    private const LOT_PRICE = 'SELECT `pi`.`price` FROM `bpl_sales_items` `i`'
        . ' JOIN `bpl_proforma_items` `pi` ON `pi`.`order_item_id` = `i`.`id`'
        . ' JOIN `bpl_products_hardroll` `p` ON `p`.`id` = `i`.`productid`'
        . ' WHERE `i`.`order_id` = `pl`.`order_id`'
        . ' AND ' . self::PRODUCT_DESCRIPTION . ' = `jt`.`descr` LIMIT 1';

    /**
     * One row per packing list: what shipped in it and what it is worth.
     *
     * Split parents (`split = '1'`) are excluded — they are the row the
     * partial shipments were cut from, kept only so the legacy screens know to
     * leave them alone, and counting them would double every figure.
     *
     * @return \Illuminate\Database\Query\Builder a subquery keyed `packing_id`
     */
    public static function shipments()
    {
        $price = '(' . self::LOT_PRICE . ')';

        // Tare is a CONTAINER's, not a lot's, and the nested path repeats its
        // row once per lot inside it — so summing it straight would multiply a
        // two-lot container's tare by two. The nested ORDINALITY restarts at 1
        // inside each container, which makes "the first lot of this container"
        // the one row to take the tare from. A container with no goods emits a
        // single row with a NULL ordinality, hence the COALESCE.
        $tare = 'SUM(IF(COALESCE(`jt`.`lot_ord`, 1) = 1, COALESCE(`jt`.`tare`, 0), 0))';

        return DB::connection('bpl')->table(DB::connection('bpl')->raw(
            '`bpl_packing_list` `pl`,'
            . " JSON_TABLE(`pl`.`containers`, '\$[*]' COLUMNS("
            . " `tare` DOUBLE PATH '\$.weight',"
            . " NESTED PATH '\$.goods[*]' COLUMNS("
            . ' `lot_ord` FOR ORDINALITY,'
            . " `descr` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci PATH '\$.description',"
            . " `rolls` INT PATH '\$.rolls',"
            . " `wt` DOUBLE PATH '\$.weight'))) `jt`"
        ))
            ->whereRaw("`pl`.`split` = '0'")
            ->groupBy('pl.id')
            ->selectRaw(
                '`pl`.`id` as `packing_id`,'
                . ' `pl`.`order_id`,'
                . ' JSON_LENGTH(`pl`.`containers`) as `containers`,'
                . ' COALESCE(SUM(`jt`.`rolls`), 0) as `rolls`,'
                . ' ROUND(COALESCE(SUM(`jt`.`wt`), 0), 2) as `net_weight`,'
                . ' ROUND(COALESCE(SUM(`jt`.`wt`), 0) + ' . $tare . ', 2) as `gross_weight`,'
                . ' ROUND(COALESCE(SUM(`jt`.`wt` / 1000 * COALESCE(' . $price . ', 0)), 0), 2) as `goods_value`,'
                . ' ROUND(COALESCE(SUM(IF(' . $price . ' IS NULL, `jt`.`wt`, 0)), 0), 2) as `unpriced_weight`'
            );
    }

    /**
     * The value of a whole ORDER as the proforma quoted it: every priced line
     * at its per-tonne price. Correlated on `bpl_sales`.`id`, so it drops into
     * any query that has the order aliased as `s`.
     */
    public const ORDER_QUOTED_VALUE = '(SELECT ROUND(COALESCE(SUM(`pi`.`price` * `i`.`weight` / 1000), 0), 2)'
        . ' FROM `bpl_proforma_items` `pi` JOIN `bpl_sales_items` `i` ON `i`.`id` = `pi`.`order_item_id`'
        . ' WHERE `i`.`order_id` = `s`.`id`)';

    /** The tonnage an order asked for, priced or not. */
    public const ORDER_WEIGHT = '(SELECT ROUND(COALESCE(SUM(`i`.`weight`), 0), 2)'
        . ' FROM `bpl_sales_items` `i` WHERE `i`.`order_id` = `s`.`id`)';

    public const ORDER_LINES = '(SELECT COUNT(*) FROM `bpl_sales_items` `i` WHERE `i`.`order_id` = `s`.`id`)';

    public const ORDER_PRICED_LINES = '(SELECT COUNT(*) FROM `bpl_proforma_items` `pi`'
        . ' JOIN `bpl_sales_items` `i` ON `i`.`id` = `pi`.`order_item_id` WHERE `i`.`order_id` = `s`.`id`)';

    /** What has been received against a shipment. */
    public const SHIPMENT_PAID = '(SELECT ROUND(COALESCE(SUM(`ip`.`amount`), 0), 2)'
        . ' FROM `bpl_invoice_payments` `ip` WHERE `ip`.`packing_id` = `pl`.`id`)';

    /**
     * The currencies proformas are actually raised in, for a filter.
     *
     * From `core.currencies` via the `currencies` view on this connection, the
     * way every other BPL screen reads them.
     */
    public static function currencies(): array
    {
        return DB::connection('bpl')->table('currencies')->orderBy('code')->pluck('code', 'id')->all();
    }

    /** An HTML-entity currency symbol as the character it stands for. */
    public static function symbol(?string $raw): string
    {
        return ProformaInvoice::symbol($raw);
    }
}
