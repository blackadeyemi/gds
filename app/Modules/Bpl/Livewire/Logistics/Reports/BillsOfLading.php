<?php

namespace Modules\Bpl\Livewire\Logistics\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\PackingList;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Logistics → Reports → Bills of Lading. The carrier's receipt for each
 * shipment: when it was issued, when the vessel sailed, when it arrived, and
 * what was on it.
 *
 * The Bills of Lading screen maintains them one at a time; nothing has ever
 * read a period of them back.
 *
 * ## Arrival is recorded on 3 of 279
 *
 * The column exists and is almost never filled, so this report CANNOT answer
 * "what is still at sea" — the honest thing is to say so rather than present
 * 276 shipments as overdue at sea when nobody ever recorded them arriving. The
 * **Arrival not recorded** view is that list, and the transit figures are
 * drawn from the three that have both dates, which is too few to mean
 * anything. If arrival starts being captured, both become useful with no
 * change here.
 *
 * ## 21 bills say they sailed after they were issued
 *
 * A bill of lading is issued once the goods are aboard, so `shipped_date`
 * should not be later than `issued_date` — the rule the current screen
 * enforces. Twenty-one legacy rows break it, which the old form never checked.
 * The **Dates out of order** view lists them.
 *
 * ## The scanned document is not checked here
 *
 * Only the file NAME is stored; the file lives in a folder shared with the
 * legacy app (`BPL_BL_DOCS_PATH`). Checking 279 files on disk is a filesystem
 * call per row, and on an environment where that path is not configured every
 * row would read as missing. The Bills of Lading screen flags a missing scan
 * on the page you are looking at, which is where that check belongs.
 */
#[Title('BPL Bills of Lading Report')]
class BillsOfLading extends LogisticsReport
{
    public function title(): string
    {
        return 'Bills of Lading Report';
    }

    public function printKey(): string
    {
        return 'bills-of-lading';
    }

    protected function headline(): string
    {
        return 'The carrier\'s receipt for each shipment — issued, sailed, arrived.';
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'customers' => $this->shared('customers'),
            'countries' => $this->shared('countries'),
            'currencies' => $this->shared('currencies'),
            'arrival' => ['yes' => 'Arrival recorded', 'no' => 'Arrival not recorded'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'customer' => ['label' => 'Customer', 'options' => $o['customers'], 'width' => 250],
            'country' => ['label' => 'Country', 'options' => $o['countries'], 'width' => 180],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'arrival' => ['label' => 'Arrival', 'options' => $o['arrival'], 'width' => 200],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['customer', 'country', 'currency'];
    }

    /* ---------------- The query ---------------- */

    /** Days at sea, or NULL while either end is missing. */
    private const TRANSIT = "DATEDIFF(STR_TO_DATE(NULLIF(`bl`.`arrival_date`, ''), '%Y/%m/%d'),"
        . " STR_TO_DATE(NULLIF(`bl`.`shipped_date`, ''), '%Y/%m/%d'))";

    protected function base()
    {
        $q = $this->db()->table('bpl_bill_of_lading as bl')
            ->leftJoin('bpl_packing_list as pl', 'pl.id', '=', 'bl.packing_id')
            ->leftJoinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id');

        // The issue date is the bill's own date and the one a period means.
        $this->applyDate($q, 'bl.issued_date', true);
        $this->applyFilters($q, [
            'customer' => 's.customerid',
            'country' => 'c.customercountry',
            'currency' => 'pf.currency_id',
        ]);

        return match ((string) ($this->filters['arrival'] ?? '')) {
            'yes' => $q->whereRaw("COALESCE(`bl`.`arrival_date`, '') <> ''"),
            'no' => $q->whereRaw("COALESCE(`bl`.`arrival_date`, '') = ''"),
            default => $q,
        };
    }

    /** The bills alone, for a cheap total when no joined filter is set. */
    protected function countQuery()
    {
        $q = $this->db()->table('bpl_bill_of_lading as bl');
        $this->applyDate($q, 'bl.issued_date', true);

        return match ((string) ($this->filters['arrival'] ?? '')) {
            'yes' => $q->whereRaw("COALESCE(`bl`.`arrival_date`, '') <> ''"),
            'no' => $q->whereRaw("COALESCE(`bl`.`arrival_date`, '') = ''"),
            default => $q,
        };
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`bl`.`id`, `bl`.`number`, `bl`.`doc`,'
            . ' `bl`.`issued_date`, `bl`.`shipped_date`,'
            . " COALESCE(`bl`.`arrival_date`, '') as `arrival_date`,"
            . ' ' . self::TRANSIT . ' as `transit_days`,'
            . ' `pl`.`id` as `packing_id`, `pl`.`number` as `raw_number`, `pl`.`date` as `packed_on`,'
            . ' `c`.`id` as `customer_id`, `c`.`customerlabel`,'
            . " COALESCE(`c`.`customername`, '—') as `customer`,"
            . " COALESCE(`c`.`customercountry`, '') as `country`,"
            . " COALESCE(`c`.`port`, '') as `port`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . " COALESCE(`pf`.`nxp`, '') as `nxp`,"
            . ' COALESCE(`f`.`containers`, 0) as `containers`,'
            . ' ROUND(COALESCE(`f`.`net_weight`, 0), 2) as `net_weight`,'
            . ' ROUND(COALESCE(`f`.`goods_value`, 0)'
            . ' + COALESCE(`f`.`containers`, 0) * COALESCE(`pf`.`freight_price`, 0), 2) as `invoice_total`'
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['bl.number', 'pl.number', 'c.customername', 'c.customerlabel',
        'pf.nxp', 's.orderno'];

    public function views(): array
    {
        $number = fn ($r) => '<span class="mono">'
            . e(PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel))
            . '</span>';

        $columns = [
            ['B/L Number', 'number', fn ($r) => '<span class="mono">' . e((string) $r->number) . '</span>'],
            ['Shipment', 'raw_number', $number],
            ['Customer', 'customer'],
            ['Destination', 'port'],
            $this->dateCol('Issued', 'issued_date'),
            $this->dateCol('Sailed', 'shipped_date'),
            $this->dateCol('Arrived', 'arrival_date'),
            ['Containers', 'containers'],
            ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
            ['Value', 'invoice_total', $this->moneyCell('invoice_total')],
        ];

        $sortable = ['number', 'customer', 'port', 'issued_date', 'shipped_date',
            'arrival_date', 'containers', 'net_weight', 'invoice_total'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('bl.issued_date')->orderByDesc('bl.id'),
                'totals' => $this->moneyTotals(['invoice_total'], ['containers', 'net_weight']),
                'sortable' => $sortable,
            ],

            'no_arrival' => [
                'label' => 'Arrival not recorded',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // 276 of 279. This is what the column looks like when nobody
                // fills it in, not a list of shipments lost at sea.
                'query' => fn () => $this->select()
                    ->whereRaw("COALESCE(`bl`.`arrival_date`, '') = ''")
                    ->orderByDesc('bl.issued_date'),
                'totals' => $this->moneyTotals(['invoice_total'], ['containers', 'net_weight']),
                'sortable' => $sortable,
            ],

            'date_order' => [
                'label' => 'Dates out of order',
                'type' => 'table',
                'columns' => [
                    ['B/L Number', 'number', fn ($r) => '<span class="mono">' . e((string) $r->number) . '</span>'],
                    ['Shipment', 'raw_number', $number],
                    ['Customer', 'customer'],
                    $this->dateCol('Issued', 'issued_date'),
                    $this->dateCol('Sailed', 'shipped_date'),
                    $this->dateCol('Arrived', 'arrival_date'),
                    ['Problem', 'problem', fn ($r) => $this->problemCell($r)],
                ],
                'searchable' => self::SEARCHABLE,
                // A bill is issued once the goods are aboard, so it cannot
                // have sailed afterwards; and it cannot arrive before it
                // sailed. The current form refuses both — these are what the
                // old one let through.
                'query' => fn () => $this->select()
                    ->selectRaw(
                        "CASE WHEN COALESCE(`bl`.`shipped_date`, '') <> ''"
                        . " AND `bl`.`shipped_date` > `bl`.`issued_date` THEN 'Sailed after it was issued'"
                        . " WHEN COALESCE(`bl`.`arrival_date`, '') <> ''"
                        . " AND `bl`.`arrival_date` < `bl`.`shipped_date` THEN 'Arrived before it sailed'"
                        . " ELSE '' END as `problem`"
                    )
                    ->where(fn ($w) => $w
                        ->whereRaw("COALESCE(`bl`.`shipped_date`, '') <> '' AND `bl`.`shipped_date` > `bl`.`issued_date`")
                        ->orWhereRaw("COALESCE(`bl`.`arrival_date`, '') <> '' AND `bl`.`arrival_date` < `bl`.`shipped_date`"))
                    ->orderByDesc('bl.issued_date'),
                'totals' => [],
                'sortable' => ['number', 'customer', 'issued_date', 'shipped_date'],
            ],

            'by_customer' => [
                'label' => 'Summary (by customer)',
                'type' => 'summary',
                'columns' => [
                    ['Customer', 'customer'],
                    ['Country', 'country'],
                    ['Currency', 'currency'],
                    ['Bills', 'bills'],
                    ['Containers', 'containers'],
                    ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
                    ['Value', 'invoice_total', $this->moneyCell('invoice_total')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`c`.`customername`, '—') as `customer`,"
                    . " COALESCE(`c`.`customercountry`, '') as `country`,"
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `bills`,'
                    . ' SUM(COALESCE(`f`.`containers`, 0)) as `containers`,'
                    . ' ROUND(SUM(COALESCE(`f`.`net_weight`, 0)), 2) as `net_weight`,'
                    . ' ROUND(SUM(COALESCE(`f`.`goods_value`, 0)'
                    . ' + COALESCE(`f`.`containers`, 0) * COALESCE(`pf`.`freight_price`, 0)), 2) as `invoice_total`'
                )->groupBy('c.customername', 'c.customercountry', 'cur.code', 'cur.symbol')
                    ->orderByDesc('bills'),
                'totals' => $this->moneyTotals(['invoice_total'], ['bills', 'containers', 'net_weight']),
            ],

            'by_month' => [
                'label' => 'Summary (by month issued)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Bills', 'bills'],
                    ['Customers', 'customers'],
                    ['Containers', 'containers'],
                    ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    'LEFT(`bl`.`issued_date`, 7) as `month`,'
                    . ' COUNT(*) as `bills`,'
                    . ' COUNT(DISTINCT `c`.`id`) as `customers`,'
                    . ' SUM(COALESCE(`f`.`containers`, 0)) as `containers`,'
                    . ' ROUND(SUM(COALESCE(`f`.`net_weight`, 0)), 2) as `net_weight`'
                )->groupBy('month')->orderByDesc('month'),
                'totals' => ['bills', 'containers', 'net_weight'],
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function problemCell($row): string
    {
        return (string) $row->problem === ''
            ? ''
            : '<span class="badge badge-warning">' . e((string) $row->problem) . '</span>';
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_customer' => ['customer', 'country', 'currency'],
            'by_month' => ['month'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        return [
            ['B/L Number', 'number'],
            ['Shipment', 'raw_number', fn ($r) => PackingList::label($r->raw_number, (int) $r->customer_id, $r->customerlabel)],
            $this->dateCol('Issued', 'issued_date'),
            $this->dateCol('Sailed', 'shipped_date'),
            ['Containers', 'containers'],
            ['Tonnes', 'net_weight', $this->tonnesCell('net_weight')],
            ['Value', 'invoice_total', $this->moneyCell('invoice_total')],
        ];
    }

    public function detailSearchable(): array
    {
        return ['bl.number', 'pl.number', 'c.customername'];
    }

    public function detailQuery(string $key)
    {
        $fields = $this->expandableBy();

        if (! $fields) {
            return null;
        }

        $q = $this->select();

        $columns = [
            'customer' => DB::raw("COALESCE(`c`.`customername`, '—')"),
            'country' => DB::raw("COALESCE(`c`.`customercountry`, '')"),
            'currency' => DB::raw("COALESCE(`cur`.`code`, '')"),
            'month' => DB::raw('LEFT(`bl`.`issued_date`, 7)'),
        ];

        foreach (array_combine($fields, $this->detailKeyParts($key)) as $field => $value) {
            $q->where($columns[$field], $value);
        }

        return $q->orderByDesc('bl.issued_date')->orderByDesc('bl.id');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
