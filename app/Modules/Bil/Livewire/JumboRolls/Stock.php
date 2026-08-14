<?php

namespace Modules\Bil\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;

/**
 * Jumbo Rolls → Stock. Every jumbo roll this company owns that has not been
 * used up, and where it is standing right now.
 *
 * Rebuilt from the legacy `report_jumboreel_instore` page, which showed only
 * the last of the three places a reel can be. A reel is BIL's from the moment
 * BPL makes it against the Belimpex customer, so it is BIL stock long before it
 * reaches a BIL gate:
 *
 *   BPL Factory    on the paper machine floor, not yet shipped out of BPL
 *                  (`bpl_production.status IS NULL`)
 *   BPL Warehouse  received into a PM store and not yet released from it
 *                  (`jumboreel_storeentrance.status IS NULL`)
 *   BIL Factory    on a BIL factory floor, whole or part-used — the legacy page
 *                  (`factory_entrance_reel.status IS NULL` or `'mid'`)
 *
 * Each stage is decided by its OWN table's "has not moved on" flag rather than
 * by absence from the next table down. That matters: ~56k reels made before the
 * BIL entrance system went live in 2021 have no entrance row at all, and
 * inferring location from absence would report them as stock forever.
 *
 * The gap between the two systems — reels marked shipped out of BPL that no BIL
 * gate has yet scanned in — is deliberately NOT claimed as stock anywhere. No
 * table records that a reel is in transit, so placing it would be a guess.
 *
 * A live snapshot: no date range, and no row editing (corrections belong on the
 * screens that create these rows).
 */
#[Title('Jumbo Roll Stock')]
class Stock extends RawMaterialReport
{
    public const PAGE_KEY = 'bil.jumbo_rolls.stock';

    /* Where a reel can be standing. Values are stored in the `place` column of
       the union below, so they are also what the filter posts back. */
    public const AT_BPL_FACTORY = 'BPL Factory';
    public const AT_BPL_WAREHOUSE = 'BPL Warehouse';
    public const AT_BIL_FACTORY = 'BIL Factory';

    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Jumbo Roll Stock';
    }

    public function printKey(): string
    {
        return 'stock';
    }

    public function subtitle(): string
    {
        return 'Jumbo rolls not yet used up — on a BIL factory floor, in a BPL warehouse, or still at the paper machine.';
    }

    public function hasDateRange(): bool
    {
        return false; // live snapshot
    }

    public function readOnly(): bool
    {
        return true;
    }

    protected function printRouteName(): string
    {
        return 'bil.jumbo-rolls.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.jumbo-rolls.reports.download';
    }

    /* ---------------- The stock position ---------------- */

    /**
     * How much of a part-used reel is left: what BPL made, less everything
     * consumed on a machine and everything returned, across all of its slices.
     *
     * The same formula the Consumption screen uses to decide whether a reel is
     * still 'mid' — so a reel this page shows with weight left is exactly a reel
     * that page still accepts. The legacy report computed it two different ways
     * depending on whether the product was sliced, and ignored returns.
     *
     * Both rollups match on `reel_barcode`, the stored generated column that
     * holds a slice's parent code, so each is a covering index lookup. Matching
     * with `barcode LIKE CONCAT(f.barcode, '%')` cannot use an index and took
     * 64 seconds over this set.
     */
    private const REMAINING = "prod.`weight`"
        . " - COALESCE((SELECT SUM(u.`weight`) FROM `factory_usage_reel` u"
        . "   WHERE u.`reel_barcode` = f.`barcode` AND u.`is_deleted` = 0), 0)"
        . " - COALESCE((SELECT SUM(e.`weight`) FROM `factory_event` e"
        . "   WHERE e.`reel_barcode` = f.`barcode` AND e.`event` = 'return'), 0)";

    /**
     * The three legs, unioned into one set of reels so a single query can
     * filter, search, sort, page and export the whole position.
     *
     * Everything resolves on the `bil` connection: `bpl_production`,
     * `bpl_products` and `jumboreel_storeentrance` are all compatibility views
     * onto the other schemas.
     */
    /**
     * A SQL string literal for the `place` column. The values are the class
     * constants above, never user input; this keeps them out of the binding
     * list, which a UNION of three selects would otherwise reorder.
     */
    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function stockSet()
    {
        $conn = DB::connection('bil');
        $customer = (int) config('bil.jumbo_roll_customer_id');

        $columns = "%s as `place`, %s as `location`, %s as `since`, %s as `weight`,"
            . " `prod`.`barcode`, `prod`.`hardrollnumber`, `pr`.`productname`, `pr`.`gradetype`";

        // Still on the paper machine floor.
        $atMachine = $conn->table('bpl_production as prod')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->where('prod.customer_id', $customer)
            ->whereNull('prod.deleted_at')
            ->whereNull('prod.status')
            ->selectRaw(sprintf(
                $columns,
                $this->literal(self::AT_BPL_FACTORY),
                '`prod`.`papermachine`',
                '`prod`.`dateofmanufacture`',
                'ROUND(`prod`.`weight`, 2)'
            ));

        // In a PM store, not yet released.
        $inStore = $conn->table('jumboreel_storeentrance as se')
            ->join('bpl_production as prod', 'prod.barcode', '=', 'se.barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->whereNull('se.status')
            ->where('prod.customer_id', $customer)
            ->whereNull('prod.deleted_at')
            ->selectRaw(sprintf(
                $columns,
                $this->literal(self::AT_BPL_WAREHOUSE),
                '`se`.`entrancelocation`',
                '`se`.`dateofentrance`',
                'ROUND(`prod`.`weight`, 2)'
            ));

        // On a BIL factory floor, at whatever weight is left. Joined from the
        // entrance row (not production) so a reel whose production record is
        // missing still shows as stock rather than vanishing.
        $onFloor = $conn->table('factory_entrance_reel as f')
            ->leftJoin('bpl_production as prod', 'prod.barcode', '=', 'f.barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->where('f.is_deleted', 0)
            ->where(fn ($w) => $w->whereNull('f.status')->orWhere('f.status', 'mid'))
            ->selectRaw(sprintf(
                str_replace('`prod`.`barcode`', '`f`.`barcode`', $columns),
                $this->literal(self::AT_BIL_FACTORY),
                '`f`.`location`',
                '`f`.`dateofentrance`',
                'ROUND(' . self::REMAINING . ', 2)'
            ));

        return $atMachine->unionAll($inStore)->unionAll($onFloor);
    }

    protected function base()
    {
        $q = DB::connection('bil')->query()->fromSub($this->stockSet(), 's');

        $this->applyFilters($q, [
            'place' => 's.place',
            'location' => 's.location',
            'gradetype' => 's.gradetype',
            'product' => 's.productname',
        ]);

        return $q;
    }

    /* ---------------- Filters ---------------- */

    /**
     * Filter options come from the stock itself, not from the master tables:
     * offering all 4,300 hardroll products when 30 are in stock makes the
     * dropdown useless. One query, cached for the render.
     */
    protected function options(): array
    {
        if ($this->optCache !== null) {
            return $this->optCache;
        }

        $rows = DB::connection('bil')->query()->fromSub($this->stockSet(), 's')
            ->select('s.place', 's.location', 's.gradetype', 's.productname')->get();

        $distinct = fn (string $field) => $rows->pluck($field)->filter(fn ($v) => (string) $v !== '')
            ->unique()->sort()->values()->mapWithKeys(fn ($v) => [$v => $v])->all();

        return $this->optCache = [
            'places' => $distinct('place'),
            'locations' => $distinct('location'),
            'gradetypes' => $distinct('gradetype'),
            'products' => $distinct('productname'),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'place' => ['label' => 'Where', 'options' => $o['places']],
            'location' => ['label' => 'Location', 'options' => $o['locations']],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['gradetypes']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /* ---------------- Views ---------------- */

    public function views(): array
    {
        $searchable = ['s.barcode', 's.hardrollnumber', 's.productname', 's.gradetype', 's.location'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Barcode', 'barcode'],
                    ['Hardroll Number', 'hardrollnumber'],
                    ['Product', 'productname'],
                    ['Grade', 'gradetype'],
                    ['Where', 'place'],
                    ['Location', 'location'],
                    $this->dateCol('Since', 'since'),
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->select('s.*')
                    ->orderBy('s.place')->orderBy('s.location')->orderByDesc('s.since'),
            ],
            'by_location' => [
                'label' => 'Summary (by location)',
                'type' => 'summary',
                'columns' => [
                    ['Where', 'place'],
                    ['Location', 'location'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('s.place, s.location, COUNT(*) as quantity, ROUND(SUM(s.weight), 2) as weight')
                    ->groupBy('s.place', 's.location')
                    ->orderBy('s.place')->orderBy('s.location'),
            ],
            'by_grade' => [
                'label' => 'Summary (by grade)',
                'type' => 'summary',
                'columns' => [
                    ['Grade', 'gradetype'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('s.gradetype, COUNT(*) as quantity, ROUND(SUM(s.weight), 2) as weight')
                    ->groupBy('s.gradetype')
                    ->orderBy('s.gradetype'),
            ],
            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Grade', 'gradetype'],
                    ['Product', 'productname'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('s.gradetype, s.productname, COUNT(*) as quantity, ROUND(SUM(s.weight), 2) as weight')
                    ->groupBy('s.gradetype', 's.productname')
                    ->orderBy('s.gradetype')->orderBy('s.productname'),
            ],
        ];
    }

    /* ---------------- Drill-down from a summary row ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_location' => ['place', 'location'],
            'by_grade' => ['gradetype'],
            'by_product' => ['gradetype', 'productname'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['Barcode', 'barcode'],
            ['Hardroll Number', 'hardrollnumber'],
            ['Product', 'productname'],
            ['Where', 'place'],
            ['Location', 'location'],
            $this->dateCol('Since', 'since'),
            ['Weight (kg)', 'weight'],
        ];
    }

    public function detailSearchable(): array
    {
        return ['s.barcode', 's.hardrollnumber', 's.productname', 's.location'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->base()->select('s.*');

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where('s.' . $field, $value);
        }

        return $q->orderByDesc('s.since');
    }
}
