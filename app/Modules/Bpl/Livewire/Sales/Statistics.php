<?php

namespace Modules\Bpl\Livewire\Sales;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Bpl\Support\SalesFigures;
use Modules\Core\Livewire\StatisticsPage;

/**
 * BPL → Sales → Statistics. An analytics dashboard over the export business —
 * what was ordered, what was quoted, what shipped, and what is still owed — on
 * the same Modules\Core\Livewire\StatisticsPage base the BIL dashboards use.
 *
 * ## Tonnes are comparable; money is not
 *
 * BPL sells in four currencies. A chart that adds a naira invoice to a dollar
 * one produces a number nobody can act on, so every chart that compares
 * customers, countries or months is drawn in TONNES, which mean the same thing
 * everywhere. Money appears only where it is scoped to one currency: the
 * Receivables tab draws one chart per currency, and the tiles name the
 * currency they are in.
 *
 * ## Every date is legacy 'Y/m/d' TEXT
 *
 * `bpl_sales.date`, `bpl_proforma.date`, `bpl_packing_list.date` and
 * `bpl_invoice_payments.date` are varchars, not dates — they sort correctly as
 * strings but cannot be handed to MySQL's date functions, so every series goes
 * through LegacyStatQueries with the '/' separator.
 */
#[Title('BPL Sales Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    public function pageTitle(): string
    {
        return 'BPL Sales Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'What was ordered, what shipped, and what is still owed.';
    }

    /**
     * Export sales are slow-moving — a few hundred orders a year — so the
     * short windows say very little and all-time is affordable. The opposite
     * of the Jumbo Rolls dashboard, which reads tables of 268,000 rows.
     */
    public function rangeOptions(): array
    {
        return [
            '90d' => ['Last 90 days', 90],
            '12m' => ['Last 12 months', 365],
            'all' => ['All time', null],
        ];
    }

    public function mount(): void
    {
        parent::mount();

        if ($this->range === '30d') {
            $this->range = '12m';
        }
    }

    protected function exportRouteName(): ?string
    {
        return 'bpl.sales.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bpl.sales.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'orders' => 'Orders',
            'shipments' => 'Shipments',
            'receivables' => 'Receivables',
            'customers' => 'Customers',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'orders' => $this->ordersSection(),
            'shipments' => $this->shipmentsSection(),
            'receivables' => $this->receivablesSection(),
            'customers' => $this->customersSection(),
            default => $this->overviewSection(),
        };
    }

    protected function db()
    {
        return DB::connection('bpl');
    }

    /* ---------------- Shared pieces ---------------- */

    /** Orders in the window, with their customer and country. */
    private function orders()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('bpl_sales as s')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->whereNull('s.deleted_at')
            ->when($from, fn ($q) => $q->whereBetween('s.date', [$from, $to]));
    }

    /**
     * Shipments in the window, with what they are worth. The value arithmetic
     * is SalesFigures', shared with the Shipments report and the printed
     * invoice.
     */
    private function shipments()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->when($from, fn ($q) => $q->whereBetween('pl.date', [$from, $to]));
    }

    private const INVOICE_TOTAL = 'ROUND(`f`.`goods_value` + `f`.`containers` * COALESCE(`pf`.`freight_price`, 0), 2)';

    /** An amount with a currency symbol, compacted per the Rounded/Exact toggle. */
    private function money(float $value, ?string $symbol): string
    {
        return ProformaInvoice::symbol((string) $symbol) . $this->figure($value);
    }

    /** Kilograms shown as tonnes. */
    private function tonnes(float $kg): string
    {
        return $this->figure(round($kg / 1000)) . ' t';
    }

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $orders = (clone $this->orders())->count();
        $orderedKg = (float) (clone $this->orders())
            ->selectRaw('SUM(' . SalesFigures::ORDER_WEIGHT . ') as `w`')->value('w');

        $ship = (clone $this->shipments())
            ->selectRaw('COUNT(*) as `n`, SUM(`f`.`containers`) as `c`, SUM(`f`.`net_weight`) as `w`,'
                . ' SUM(`f`.`rolls`) as `r`')->first();

        $ordersSeries = $this->series('bpl_sales', 'date', '/', 'COUNT(*)',
            fn ($q) => $q->whereNull('deleted_at'));
        $shipSeries = $this->series('bpl_packing_list', 'date', '/', 'COUNT(*)',
            fn ($q) => $q->where('split', '0'));

        // Destinations, in tonnes — the one unit that compares across
        // currencies.
        $byCountry = (clone $this->shipments())
            ->selectRaw("COALESCE(NULLIF(`c`.`customercountry`, ''), '—') as `grp`, SUM(`f`.`net_weight`) as `w`")
            ->groupBy('grp')->orderByDesc('w')->limit(10)->get();

        // Everything outstanding, whatever the window — a debt does not stop
        // existing because the date filter moved.
        $owed = $this->outstandingByCurrency();

        return [
            'tiles' => array_merge([
                $this->tile('Orders', $this->figure($orders), $this->rangeLabel(), 'brand'),
                $this->tile('Ordered', $this->tonnes($orderedKg), $this->rangeLabel()),
                $this->tile('Shipments', $this->figure((float) ($ship->n ?? 0)), $this->rangeLabel()),
                $this->tile('Containers', $this->figure((float) ($ship->c ?? 0)), $this->rangeLabel()),
                $this->tile('Shipped', $this->tonnes((float) ($ship->w ?? 0)), $this->rangeLabel()),
            ], $this->owedTiles($owed)),
            'charts' => [
                $this->chartSpec('bs-flow', 'line', 'Orders and Shipments',
                    $ordersSeries['labels'] ?: $shipSeries['labels'], [
                        ['name' => 'Orders raised', 'data' => $ordersSeries['data']],
                        ['name' => 'Shipments', 'data' => $shipSeries['data']],
                    ], ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bs-country', 'hbar', 'Shipped by Destination',
                    $byCountry->pluck('grp')->all(),
                    [['name' => 'Tonnes', 'data' => $byCountry->pluck('w')->map(fn ($v) => round((float) $v / 1000, 2))->all()]],
                    ['span' => 2, 'subtitle' => 'Tonnes — the one figure that compares across currencies']),
            ],
        ];
    }

    protected function ordersSection(): array
    {
        $orders = (clone $this->orders())->count();
        $customers = (int) (clone $this->orders())->distinct()->count('s.customerid');
        $orderedKg = (float) (clone $this->orders())
            ->selectRaw('SUM(' . SalesFigures::ORDER_WEIGHT . ') as `w`')->value('w');

        // An order that has no proforma has not been quoted yet, which is the
        // one thing a sales desk acts on.
        $unquoted = (int) (clone $this->orders())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_proforma as pf')
                ->whereColumn('pf.order_id', 's.id')->whereNull('pf.deleted_at'))
            ->count();

        $time = $this->series('bpl_sales', 'date', '/', 'COUNT(*)', fn ($q) => $q->whereNull('deleted_at'));

        $byCustomer = (clone $this->orders())
            ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`,"
                . ' SUM(' . SalesFigures::ORDER_WEIGHT . ') as `w`')
            ->groupBy('grp')->orderByDesc('w')->limit(12)->get();

        $byGrade = (clone $this->orders())
            ->join('bpl_sales_items as i', 'i.order_id', '=', 's.id')
            ->leftJoin('bpl_products_hardroll as p', 'p.id', '=', 'i.productid')
            ->selectRaw("COALESCE(NULLIF(`p`.`gradetype`, ''), '—') as `grp`, SUM(`i`.`weight`) as `w`")
            ->groupBy('grp')->orderByDesc('w')->limit(12)->get();

        return [
            'tiles' => [
                $this->tile('Orders', $this->figure($orders), $this->rangeLabel(), 'brand'),
                $this->tile('Ordered', $this->tonnes($orderedKg), $this->rangeLabel()),
                $this->tile('Customers Ordering', $this->figure($customers), $this->rangeLabel()),
                $this->tile('Not Yet Quoted', $this->figure($unquoted), 'no proforma raised',
                    $unquoted > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('bs-ord-time', 'bar', 'Orders Raised', $time['labels'],
                    [['name' => 'Orders', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bs-ord-cust', 'hbar', 'Ordered by Customer',
                    $byCustomer->pluck('grp')->all(),
                    [['name' => 'Tonnes', 'data' => $byCustomer->pluck('w')->map(fn ($v) => round((float) $v / 1000, 2))->all()]]),
                $this->chartSpec('bs-ord-grade', 'hbar', 'Ordered by Grade',
                    $byGrade->pluck('grp')->all(),
                    [['name' => 'Tonnes', 'data' => $byGrade->pluck('w')->map(fn ($v) => round((float) $v / 1000, 2))->all()]]),
            ],
        ];
    }

    protected function shipmentsSection(): array
    {
        $ship = (clone $this->shipments())
            ->selectRaw('COUNT(*) as `n`, SUM(`f`.`containers`) as `c`, SUM(`f`.`net_weight`) as `w`,'
                . ' SUM(`f`.`rolls`) as `r`, SUM(`f`.`unpriced_weight`) as `u`')->first();

        $noBl = (int) (clone $this->shipments())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_bill_of_lading as bl')
                ->whereColumn('bl.packing_id', 'pl.id'))
            ->count();

        $time = $this->series('bpl_packing_list', 'date', '/', 'SUM(JSON_LENGTH(`containers`))',
            fn ($q) => $q->where('split', '0'));

        $byCustomer = (clone $this->shipments())
            ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`, SUM(`f`.`net_weight`) as `w`")
            ->groupBy('grp')->orderByDesc('w')->limit(12)->get();

        $byShipment = (clone $this->shipments())
            ->selectRaw("COALESCE(NULLIF(`pf`.`shipment`, ''), '—') as `grp`, COUNT(*) as `n`")
            ->groupBy('grp')->orderByDesc('n')->get();

        return [
            'tiles' => [
                $this->tile('Shipments', $this->figure((float) ($ship->n ?? 0)), $this->rangeLabel(), 'brand'),
                $this->tile('Containers', $this->figure((float) ($ship->c ?? 0)), $this->rangeLabel()),
                $this->tile('Shipped', $this->tonnes((float) ($ship->w ?? 0)), $this->rangeLabel()),
                $this->tile('Rolls', $this->figure((float) ($ship->r ?? 0)), $this->rangeLabel()),
                $this->tile('No Bill of Lading', $this->figure($noBl), 'of the shipments in this window',
                    $noBl > 0 ? 'neg' : null),
                // Goods that shipped under a description no priced line matches,
                // so the invoice leaves them out — see SalesFigures.
                $this->tile('Unpriced', $this->tonnes((float) ($ship->u ?? 0)), 'shipped with no matching price',
                    (float) ($ship->u ?? 0) > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('bs-shp-time', 'bar', 'Containers Shipped', $time['labels'],
                    [['name' => 'Containers', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bs-shp-cust', 'hbar', 'Shipped by Customer',
                    $byCustomer->pluck('grp')->all(),
                    [['name' => 'Tonnes', 'data' => $byCustomer->pluck('w')->map(fn ($v) => round((float) $v / 1000, 2))->all()]]),
                $this->chartSpec('bs-shp-mode', 'donut', 'By Shipment Type',
                    $byShipment->pluck('grp')->all(),
                    [['name' => 'Shipments', 'data' => $byShipment->pluck('n')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }

    /**
     * What is owed, per currency.
     *
     * Deliberately NOT scoped to the date range: a debt does not stop existing
     * because the window moved, and a receivables figure that changes when you
     * pick "last 90 days" is a figure nobody can use.
     */
    private function outstandingByCurrency()
    {
        return $this->db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->whereRaw(SalesFigures::SHIPMENT_PAID . ' < ' . self::INVOICE_TOTAL . ' - 1')
            ->selectRaw(
                "COALESCE(`cur`.`code`, '—') as `code`, `cur`.`symbol`,"
                . ' COUNT(*) as `shipments`,'
                . ' ROUND(SUM(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . '), 2) as `balance`'
            )
            ->groupBy('cur.code', 'cur.symbol')
            ->orderByDesc('balance')
            ->get();
    }

    /** One tile per currency that is owed anything. */
    private function owedTiles($owed): array
    {
        $tiles = [];

        foreach ($owed as $row) {
            $tiles[] = $this->tile('Owed in ' . $row->code,
                $this->money((float) $row->balance, $row->symbol),
                (int) $row->shipments . ' ' . \Illuminate\Support\Str::plural('shipment', (int) $row->shipments),
                'neg');
        }

        return $tiles ?: [$this->tile('Owed', '—', 'nothing outstanding', 'pos')];
    }

    protected function receivablesSection(): array
    {
        $owed = $this->outstandingByCurrency();

        $customersOwing = (int) $this->db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->whereRaw(SalesFigures::SHIPMENT_PAID . ' < ' . self::INVOICE_TOTAL . ' - 1')
            ->distinct()->count('s.customerid');

        $received = $this->series('bpl_invoice_payments', 'date', '/', 'COUNT(*)');

        // One chart per currency: the amounts differ by orders of magnitude
        // between naira and dollars, so a single axis would flatten every bar
        // but one into the baseline.
        $charts = [
            $this->chartSpec('bs-rec-time', 'bar', 'Payments Received', $received['labels'],
                [['name' => 'Payments', 'data' => $received['data']]],
                ['span' => 2, 'subtitle' => $this->rangeLabel()]),
        ];

        foreach ($owed as $i => $row) {
            $byCustomer = $this->db()->table('bpl_packing_list as pl')
                ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
                ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
                ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
                ->leftJoin('bpl_proforma as pf', function ($j) {
                    $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
                })
                ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
                ->whereRaw(SalesFigures::SHIPMENT_PAID . ' < ' . self::INVOICE_TOTAL . ' - 1')
                ->when($row->code === '—',
                    fn ($q) => $q->whereNull('cur.code'),
                    fn ($q) => $q->where('cur.code', $row->code))
                ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`,"
                    . ' ROUND(SUM(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . '), 2) as `balance`')
                ->groupBy('grp')->orderByDesc('balance')->limit(12)->get();

            $charts[] = $this->chartSpec('bs-rec-' . $i, 'hbar',
                'Outstanding by Customer — ' . $row->code,
                $byCustomer->pluck('grp')->all(),
                [['name' => 'Outstanding', 'data' => $byCustomer->pluck('balance')->map(fn ($v) => (float) $v)->all()]],
                ['subtitle' => 'Whatever the date range — a debt has no window']);
        }

        return [
            'tiles' => array_merge($this->owedTiles($owed), [
                $this->tile('Customers Owing', $this->figure($customersOwing), 'right now',
                    $customersOwing > 0 ? 'neg' : 'pos'),
                $this->tile('Payments Received',
                    $this->figure((float) $this->countOver('bpl_invoice_payments', 'date', '/')),
                    $this->rangeLabel()),
            ]),
            'charts' => $charts,
        ];
    }

    protected function customersSection(): array
    {
        $ordering = (int) (clone $this->orders())->distinct()->count('s.customerid');
        $countries = (int) (clone $this->orders())->distinct()->count('c.customercountry');
        $onBooks = (int) $this->db()->table('bpl_customers')->whereNull('deleted_at')->count();

        $byCustomer = (clone $this->orders())
            ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`, COUNT(*) as `n`")
            ->groupBy('grp')->orderByDesc('n')->limit(12)->get();

        $byCountry = (clone $this->orders())
            ->selectRaw("COALESCE(NULLIF(`c`.`customercountry`, ''), '—') as `grp`,"
                . ' COUNT(DISTINCT `c`.`id`) as `n`')
            ->groupBy('grp')->orderByDesc('n')->get();

        // A customer on the books who has ordered nothing in this window.
        $dormant = max(0, $onBooks - $ordering);

        return [
            'tiles' => [
                $this->tile('Customers Ordering', $this->figure($ordering), $this->rangeLabel(), 'brand'),
                $this->tile('Countries', $this->figure($countries), $this->rangeLabel()),
                $this->tile('Customers on the Books', $this->figure($onBooks)),
                $this->tile('No Order in This Window', $this->figure($dormant),
                    $this->rangeLabel(), $dormant > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('bs-cus-orders', 'hbar', 'Orders by Customer',
                    $byCustomer->pluck('grp')->all(),
                    [['name' => 'Orders', 'data' => $byCustomer->pluck('n')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2]),
                $this->chartSpec('bs-cus-country', 'donut', 'Customers by Country',
                    $byCountry->pluck('grp')->all(),
                    [['name' => 'Customers', 'data' => $byCountry->pluck('n')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }
}
