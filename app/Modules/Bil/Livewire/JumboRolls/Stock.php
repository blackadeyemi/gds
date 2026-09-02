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
 *   BPL Warehouse  received into one of BPL's stores and not yet released
 *                  (`bpl_storeentrance.status IS NULL`) — BPL holds a large
 *                  amount of finished stock for BIL, so this is a real position
 *                  rather than a formality. Note `bpl_storeentrance` is the LIVE
 *                  table; `jumboreel_storeentrance` is the dead 2018-19 route.
 *   BIL Factory    on a BIL factory floor, whole or part-used — the legacy page
 *                  (`factory_entrance_reel.status IS NULL` or `'mid'`)
 *
 * Each place is decided by its OWN table's "has not moved on" flag rather than
 * by absence from the next table down. That matters: ~54k reels made before the
 * BIL entrance system went live in 2021 have no entrance row at all, and
 * inferring location from absence would report them as stock forever.
 *
 * Reels that left BPL and were never scanned in at a BIL gate are therefore NOT
 * shown here — no table says where they are, so no place can honestly hold
 * them. `bpl_factoryexit.received_at` records which exits are still outstanding
 * (stamped by the Factory Entrance screen), so that population can be reported
 * on once it is decided what should happen to it.
 *
 * Every row carries the days it has been standing where it is, so a stale
 * position is visible without reading dates.
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

    /**
     * @param  bool  $withWeight  false skips the per-reel remaining-weight
     *                            rollup for callers that only read the labels
     *                            (the filter dropdowns). The union runs three
     *                            times per render — options, count, page — so
     *                            not doing that work twice for nothing matters.
     */
    /**
     * A location's DISPLAY name, resolved through the core master.
     *
     * The movement tables store legacy strings — a factory code on
     * `factory_entrance_reel.location`, a paper machine on
     * `bpl_production.papermachine`, a `bpl_stock_locations` id on
     * `bpl_storeentrance.location_id`. Reading those straight through means
     * renaming a factory or warehouse in Admin changes nothing here, and it
     * shows "PM2" where the rest of the app says "Paper Machine 2".
     *
     * Written as a scalar subquery rather than a join so it cannot multiply
     * rows, and wrapped in COALESCE so a location with no master row still
     * appears under its legacy name instead of vanishing.
     */
    private function factoryName(string $codeExpr): string
    {
        return "COALESCE((SELECT `name` FROM `core`.`factories`"
            . " WHERE `code` = {$codeExpr} AND `deleted_at` IS NULL LIMIT 1), {$codeExpr})";
    }

    private function warehouseName(string $legacyIdExpr, string $fallbackExpr, int $companyId): string
    {
        return "COALESCE((SELECT `name` FROM `core`.`warehouses`"
            . " WHERE `legacy_location_id` = {$legacyIdExpr} AND `company_id` = {$companyId}"
            . " AND `deleted_at` IS NULL LIMIT 1), {$fallbackExpr})";
    }

    protected function stockSet(bool $withWeight = true)
    {
        $conn = DB::connection('bil');
        $customer = (int) config('bil.jumbo_roll_customer_id');

        // Which company's warehouses to resolve store ids against. `legacy_location_id`
        // is only unique WITHIN a company — the raw-material stores use the same
        // small integers — so this scoping is load-bearing, not tidiness.
        $bplCompanyId = (int) DB::connection('core')->table('companies')
            ->where('code', 'BPL')->value('id');

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
                $this->factoryName('`prod`.`papermachine`'),
                '`prod`.`dateofmanufacture`',
                'ROUND(`prod`.`weight`, 2)'
            ));

        // In one of BPL's own stores, not yet released. BPL holds a lot of
        // finished reels for BIL — this is the largest position after the BIL
        // factory floors, not a rounding error.
        //
        // `bpl_storeentrance` is the LIVE store table. `jumboreel_storeentrance`
        // is the dead 2018-19 route and must not be used here.
        $inStore = $conn->table('bpl_storeentrance as se')
            ->join('bpl_production as prod', 'prod.barcode', '=', 'se.barcode')
            ->leftJoin('bpl_products as pr', 'pr.id', '=', 'prod.product_id')
            ->leftJoin('bpl_stock_locations as store', 'store.id', '=', 'se.location_id')
            ->whereNull('se.status')
            ->whereNull('se.deleted_at')
            ->where('prod.customer_id', $customer)
            ->whereNull('prod.deleted_at')
            ->selectRaw(sprintf(
                $columns,
                $this->literal(self::AT_BPL_WAREHOUSE),
                $this->warehouseName('`se`.`location_id`', '`store`.`location`', $bplCompanyId),
                '`se`.`date`',
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
                $this->factoryName('`f`.`location`'),
                '`f`.`dateofentrance`',
                $withWeight ? 'ROUND(' . self::REMAINING . ', 2)' : '0'
            ));

        return $atMachine->unionAll($inStore)->unionAll($onFloor);
    }

    /**
     * The unfiltered live position, for another screen to aggregate.
     *
     * The Statistics dashboard reads this rather than rebuilding the three legs
     * of its own, so the two can never quietly disagree about what is in stock.
     */
    public function positionQuery()
    {
        return $this->stockSet();
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
     * The filters, outermost first. Each one narrows the options offered by the
     * ones after it: picking Where = BIL Factory leaves Location offering Bil-1
     * and Gambini, not the paper machines.
     *
     * filter key => the column it filters on.
     */
    private const FILTER_CASCADE = [
        'place' => 'place',
        'location' => 'location',
        'gradetype' => 'gradetype',
        'product' => 'productname',
    ];

    /**
     * Filter options come from the stock itself, not from the master tables:
     * offering all 4,300 hardroll products when 30 are in stock makes the
     * dropdown useless — and offering a location that holds none of what you
     * have already selected is the same mistake one level down.
     *
     * The whole position is a few hundred rows, so it is fetched once and the
     * cascade is applied in memory rather than as a query per dropdown.
     */
    protected function options(): array
    {
        if ($this->optCache !== null) {
            return $this->optCache;
        }

        $pool = DB::connection('bil')->query()->fromSub($this->stockSet(false), 's')
            ->select('s.place', 's.location', 's.gradetype', 's.productname')->get();

        $options = [];

        foreach (self::FILTER_CASCADE as $filter => $column) {
            $options[$filter] = $pool->pluck($column)
                ->filter(fn ($v) => (string) $v !== '')
                ->unique()->sort()->values()
                ->mapWithKeys(fn ($v) => [$v => $v])->all();

            // Everything after this dropdown sees only what this choice leaves.
            $chosen = $this->filters[$filter] ?? '';
            if ($chosen !== '' && $chosen !== 'all') {
                $pool = $pool->where($column, $chosen);
            }
        }

        return $this->optCache = $options;
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'place' => ['label' => 'Where', 'options' => $o['place']],
            'location' => ['label' => 'Location', 'options' => $o['location']],
            'gradetype' => ['label' => 'Grade Type', 'options' => $o['gradetype']],
            'product' => ['label' => 'Product', 'options' => $o['product']],
        ];
    }

    /**
     * Changing a filter clears the ones it narrows.
     *
     * Without this, switching Where to BPL Factory while Location still says
     * Bil-1 leaves an impossible pair selected and an empty table with nothing
     * explaining why. The options cache is dropped too, since what each
     * dropdown may offer has just changed.
     */
    public function updatedFilters($value = null, $key = null): void
    {
        $downstream = array_keys(self::FILTER_CASCADE);
        $position = $key === null ? false : array_search($key, $downstream, true);

        if ($position !== false) {
            foreach (array_slice($downstream, $position + 1) as $filter) {
                $this->filters[$filter] = '';
            }
        }

        $this->optCache = null;

        parent::updatedFilters();
    }

    /* ---------------- Views ---------------- */

    /** Days a reel has been standing where it is — `since` is legacy 'Y/m/d' text. */
    private const DAYS = "DATEDIFF(CURDATE(), STR_TO_DATE(s.`since`, '%Y/%m/%d'))";

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
                    ['Days', 'days'],
                    ['Weight (kg)', 'weight'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->select('s.*')->selectRaw(self::DAYS . ' as days')
                    // Oldest first: the rows that need someone's attention are the
                    // ones that have been standing longest, especially in transit.
                    ->orderBy('s.place')->orderBy('s.location')->orderBy('s.since'),
            ],
            'by_location' => [
                'label' => 'Summary (by location)',
                'type' => 'summary',
                'columns' => [
                    ['Where', 'place'],
                    ['Location', 'location'],
                    ['Reels', 'quantity'],
                    ['Weight (kg)', 'weight'],
                    ['Oldest (days)', 'oldest'],
                ],
                'searchable' => $searchable,
                'query' => fn () => $this->base()
                    ->selectRaw('s.place, s.location, COUNT(*) as quantity, ROUND(SUM(s.weight), 2) as weight,'
                        . ' MAX(' . self::DAYS . ') as oldest')
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
            ['Days', 'days'],
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

        $q = $this->base()->select('s.*')->selectRaw(self::DAYS . ' as days');

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where('s.' . $field, $value);
        }

        return $q->orderBy('s.since');
    }
}
