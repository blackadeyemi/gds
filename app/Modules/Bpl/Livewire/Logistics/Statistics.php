<?php

namespace Modules\Bpl\Livewire\Logistics;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bpl\Models\BplDelivery;
use Modules\Bpl\Support\DeliveryFigures;
use Modules\Bpl\Support\SalesFigures;
use Modules\Core\Livewire\StatisticsPage;

/**
 * BPL → Logistics → Statistics. Getting the rolls out of the gate: the truck
 * loads, what the weighbridge made of them, and the bills of lading that
 * follow the sea shipments.
 *
 * ## Tonnes, not money
 *
 * Almost everything here is weights and counts, which mean the same thing
 * whoever is reading — a truck load has no currency. The one money figure, on
 * the Shipping tab, is a bill of lading's shipment value, and it is shown per
 * currency for the reason every other BPL dashboard does it.
 *
 * ## Dates
 *
 * `bpl_delivery`.`date` and `bpl_bill_of_lading`.`issued_date` are the usual
 * legacy `Y/m/d` text, so the series go through LegacyStatQueries with the '/'
 * separator. `bpl_delivery_barcode`.`date` is `yy-mm-dd` and is never used —
 * see Modules\Bpl\Support\DeliveryFigures.
 */
#[Title('BPL Logistics Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    public function pageTitle(): string
    {
        return 'BPL Logistics Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'Truck loads, the weighbridge, and the shipping documents behind them.';
    }

    /** About one load a day, so the short windows say something here. */
    public function rangeOptions(): array
    {
        return [
            '30d' => ['Last 30 days', 30],
            '90d' => ['Last 90 days', 90],
            '12m' => ['Last 12 months', 365],
            'all' => ['All time', null],
        ];
    }

    protected function exportRouteName(): ?string
    {
        return 'bpl.logistics.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bpl.logistics.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'deliveries' => 'Deliveries',
            'weighbridge' => 'Weighbridge',
            'shipping' => 'Shipping',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'deliveries' => $this->deliveriesSection(),
            'weighbridge' => $this->weighbridgeSection(),
            'shipping' => $this->shippingSection(),
            default => $this->overviewSection(),
        };
    }

    protected function db()
    {
        return DB::connection('bpl');
    }

    /* ---------------- Shared pieces ---------------- */

    /** Waybills in the window, with their load and their weights. */
    private function loads()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->query()->fromSub(DeliveryFigures::waybills(), 'f')
            ->when($from, fn ($q) => $q->whereBetween('date', [$from, $to]));
    }

    /** Bills of lading in the window, by the date they were issued. */
    private function bills()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('bpl_bill_of_lading as bl')
            ->leftJoin('bpl_packing_list as pl', 'pl.id', '=', 'bl.packing_id')
            ->leftJoinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->when($from, fn ($q) => $q->whereBetween('bl.issued_date', [$from, $to]));
    }

    private function tonnes(float $kg): string
    {
        return $this->figure(round($kg / 1000)) . ' t';
    }

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $loads = (clone $this->loads())
            ->selectRaw('COUNT(*) as `n`, SUM(`rolls`) as `rolls`, ROUND(SUM(`roll_weight`), 2) as `kg`,'
                . ' SUM(`weighed`) as `weighed`, COUNT(DISTINCT `customer`) as `customers`')->first();

        $time = $this->series('bpl_delivery', 'date', '/', 'COUNT(*)');

        $byCustomer = (clone $this->loads())
            ->selectRaw("COALESCE(NULLIF(`customer`, ''), '—') as `grp`, ROUND(SUM(`roll_weight`), 2) as `v`")
            ->groupBy('grp')->orderByDesc('v')->limit(10)->get();

        // Waiting for the weighbridge, whatever the window — a truck does not
        // stop waiting because the date filter moved. Internal moves complete
        // at once and are not waiting for anything.
        $pending = (int) $this->db()->query()->fromSub(DeliveryFigures::waybills(), 'f')
            ->where('status', 'pending')
            ->where('customer', '<>', BplDelivery::INTERNAL_CUSTOMER)
            ->count();

        $bills = (int) $this->countOver('bpl_bill_of_lading', 'issued_date', '/');

        return [
            'tiles' => [
                $this->tile('Truck Loads', $this->figure((float) ($loads->n ?? 0)), $this->rangeLabel(), 'brand'),
                $this->tile('Rolls Delivered', $this->figure((float) ($loads->rolls ?? 0)), $this->rangeLabel()),
                $this->tile('Delivered', $this->tonnes((float) ($loads->kg ?? 0)), $this->rangeLabel()),
                $this->tile('Customers', $this->figure((float) ($loads->customers ?? 0)), $this->rangeLabel()),
                $this->tile('Bills of Lading', $this->figure((float) $bills), $this->rangeLabel()),
                $this->tile('Awaiting the Weighbridge', $this->figure((float) $pending), 'right now',
                    $pending > 0 ? 'neg' : 'pos'),
            ],
            'charts' => [
                $this->chartSpec('bl-loads-time', 'bar', 'Truck Loads', $time['labels'],
                    [['name' => 'Loads', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bl-loads-cust', 'hbar', 'Delivered by Customer',
                    $byCustomer->pluck('grp')->all(),
                    [['name' => 'Tonnes', 'data' => $byCustomer->pluck('v')->map(fn ($v) => round((float) $v / 1000, 2))->all()]],
                    ['span' => 2]),
            ],
        ];
    }

    protected function deliveriesSection(): array
    {
        $loads = (clone $this->loads())
            ->selectRaw('COUNT(*) as `n`, SUM(`rolls`) as `rolls`, ROUND(SUM(`roll_weight`), 2) as `kg`,'
                . ' ROUND(AVG(`rolls`), 1) as `avg_rolls`,'
                . ' COUNT(DISTINCT `vehicle`) as `vehicles`,'
                . ' COUNT(DISTINCT `driver`) as `drivers`')->first();

        $internal = (int) (clone $this->loads())
            ->where('customer', BplDelivery::INTERNAL_CUSTOMER)->count();

        $time = $this->series('bpl_delivery', 'date', '/', 'COUNT(*)');

        $byVehicle = (clone $this->loads())
            ->selectRaw("COALESCE(NULLIF(`vehicle`, ''), '— none recorded —') as `grp`, COUNT(*) as `v`")
            ->groupBy('grp')->orderByDesc('v')->limit(12)->get();

        $byLoader = (clone $this->loads())
            ->selectRaw("COALESCE(NULLIF(`loader`, ''), '—') as `grp`, COUNT(*) as `v`")
            ->groupBy('grp')->orderByDesc('v')->limit(10)->get();

        return [
            'tiles' => [
                $this->tile('Truck Loads', $this->figure((float) ($loads->n ?? 0)), $this->rangeLabel(), 'brand'),
                $this->tile('Rolls', $this->figure((float) ($loads->rolls ?? 0)), $this->rangeLabel()),
                $this->tile('Delivered', $this->tonnes((float) ($loads->kg ?? 0)), $this->rangeLabel()),
                $this->tile('Rolls per Load', (string) ($loads->avg_rolls ?? '0'), 'on average'),
                $this->tile('Vehicles', $this->figure((float) ($loads->vehicles ?? 0)), $this->rangeLabel()),
                // An inter-company move to Belimpex rather than a sale.
                $this->tile('Internal Moves', $this->figure((float) $internal), 'to Belimpex'),
            ],
            'charts' => [
                $this->chartSpec('bl-del-time', 'bar', 'Loads by Day', $time['labels'],
                    [['name' => 'Loads', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bl-del-vehicle', 'hbar', 'Loads by Vehicle',
                    $byVehicle->pluck('grp')->all(),
                    [['name' => 'Loads', 'data' => $byVehicle->pluck('v')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('bl-del-loader', 'hbar', 'Loads by Loader',
                    $byLoader->pluck('grp')->all(),
                    [['name' => 'Loads', 'data' => $byLoader->pluck('v')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }

    /**
     * The weighbridge check as figures: what the rolls say a load weighs
     * against what the scale says.
     */
    protected function weighbridgeSection(): array
    {
        $tolerance = DeliveryFigures::TOLERANCE;

        $w = (clone $this->loads())->whereNotNull('variance')
            ->selectRaw(
                'COUNT(*) as `n`,'
                . " SUM(`variance` > {$tolerance}) as `heavy`,"
                . " SUM(`variance` < -{$tolerance}) as `short`,"
                . ' ROUND(AVG(`variance`), 1) as `avg`,'
                . ' MAX(`variance`) as `max`, MIN(`variance`) as `min`,'
                . ' ROUND(SUM(`roll_weight`), 2) as `rolls_kg`,'
                . ' ROUND(SUM(`net_weight`), 2) as `net_kg`'
            )->first();

        // The spread and the unweighed count come out of ONE pass. Each of
        // these reads materialises the whole waybill aggregate, so asking it
        // five separate questions cost five times what asking one does.
        $counts = (clone $this->loads())->selectRaw(
            'SUM(`weighed` = 0) as `unweighed`,'
            . ' SUM(`variance` > 1000) as `b1`,'
            . " SUM(`variance` > {$tolerance} AND `variance` <= 1000) as `b2`,"
            . " SUM(`variance` BETWEEN -{$tolerance} AND {$tolerance}) as `b3`,"
            . " SUM(`variance` < -{$tolerance}) as `b4`"
        )->first();

        $unweighed = (int) ($counts->unweighed ?? 0);

        $spread = [
            'More than 1 t heavy' => (int) ($counts->b1 ?? 0),
            $tolerance . ' kg – 1 t heavy' => (int) ($counts->b2 ?? 0),
            'Within ' . $tolerance . ' kg' => (int) ($counts->b3 ?? 0),
            'More than ' . $tolerance . ' kg short' => (int) ($counts->b4 ?? 0),
        ];

        $worst = (clone $this->loads())->whereNotNull('variance')
            ->whereRaw("ABS(`variance`) > {$tolerance}")
            ->orderByDesc(DB::raw('ABS(`variance`)'))->limit(12)
            ->get(['waybill', 'variance']);

        return [
            'tiles' => [
                $this->tile('Loads Weighed', $this->figure((float) ($w->n ?? 0)), $this->rangeLabel(), 'brand'),
                $this->tile('Not Weighed', $this->figure((float) $unweighed), $this->rangeLabel(),
                    $unweighed > 0 ? 'neg' : null),
                $this->tile('Rolls Say', $this->tonnes((float) ($w->rolls_kg ?? 0)), $this->rangeLabel()),
                $this->tile('Scale Says', $this->tonnes((float) ($w->net_kg ?? 0)), $this->rangeLabel()),
                $this->tile('Over Tolerance', $this->figure((float) (($w->heavy ?? 0) + ($w->short ?? 0))),
                    'more than ' . $tolerance . ' kg out',
                    (($w->heavy ?? 0) + ($w->short ?? 0)) > 0 ? 'neg' : 'pos'),
                $this->tile('Heaviest Over', $this->figure((float) ($w->max ?? 0)) . ' kg', 'single load'),
            ],
            'charts' => [
                $this->chartSpec('bl-wb-spread', 'donut', 'How Close the Scale Is',
                    array_keys($spread),
                    [['name' => 'Loads', 'data' => array_map('floatval', array_values($spread))]],
                    ['span' => 2, 'subtitle' => 'The Weighbridge refuses anything more than '
                        . $tolerance . ' kg short, and nothing above']),
                $this->chartSpec('bl-wb-worst', 'hbar', 'Furthest Out',
                    $worst->pluck('waybill')->all(),
                    [['name' => 'Variance (kg)', 'data' => $worst->pluck('variance')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2]),
            ],
        ];
    }

    protected function shippingSection(): array
    {
        $bills = (clone $this->bills())
            ->selectRaw('COUNT(*) as `n`,'
                . ' SUM(COALESCE(`f`.`containers`, 0)) as `containers`,'
                . ' ROUND(SUM(COALESCE(`f`.`net_weight`, 0)), 2) as `kg`,'
                . ' COUNT(DISTINCT `c`.`id`) as `customers`,'
                . " SUM(COALESCE(`bl`.`arrival_date`, '') <> '') as `arrived`")->first();

        $time = $this->series('bpl_bill_of_lading', 'issued_date', '/', 'COUNT(*)');

        $byPort = (clone $this->bills())
            ->selectRaw("COALESCE(NULLIF(`c`.`port`, ''), '—') as `grp`, COUNT(*) as `v`")
            ->groupBy('grp')->orderByDesc('v')->limit(12)->get();

        $byCustomer = (clone $this->bills())
            ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`, COUNT(*) as `v`")
            ->groupBy('grp')->orderByDesc('v')->limit(12)->get();

        // Shipments that went out with no bill of lading raised against them.
        $noBl = (int) $this->db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_bill_of_lading as bl')
                ->whereColumn('bl.packing_id', 'pl.id'))
            ->count();

        return [
            'tiles' => [
                $this->tile('Bills of Lading', $this->figure((float) ($bills->n ?? 0)), $this->rangeLabel(), 'brand'),
                $this->tile('Containers', $this->figure((float) ($bills->containers ?? 0)), $this->rangeLabel()),
                $this->tile('Shipped', $this->tonnes((float) ($bills->kg ?? 0)), $this->rangeLabel()),
                $this->tile('Customers', $this->figure((float) ($bills->customers ?? 0)), $this->rangeLabel()),
                // The column exists and is almost never filled, so this is a
                // statement about the data rather than about the ships.
                $this->tile('Arrival Recorded', $this->figure((float) ($bills->arrived ?? 0)),
                    'of ' . number_format((float) ($bills->n ?? 0)) . ' — rarely captured'),
                $this->tile('Shipments With No B/L', $this->figure((float) $noBl), 'right now',
                    $noBl > 0 ? 'neg' : 'pos'),
            ],
            'charts' => [
                $this->chartSpec('bl-ship-time', 'bar', 'Bills of Lading Issued', $time['labels'],
                    [['name' => 'Bills', 'data' => $time['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bl-ship-port', 'donut', 'By Destination Port',
                    $byPort->pluck('grp')->all(),
                    [['name' => 'Bills', 'data' => $byPort->pluck('v')->map(fn ($v) => (float) $v)->all()]]),
                $this->chartSpec('bl-ship-cust', 'hbar', 'By Customer',
                    $byCustomer->pluck('grp')->all(),
                    [['name' => 'Bills', 'data' => $byCustomer->pluck('v')->map(fn ($v) => (float) $v)->all()]]),
            ],
        ];
    }
}
