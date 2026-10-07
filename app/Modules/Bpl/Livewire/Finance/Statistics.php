<?php

namespace Modules\Bpl\Livewire\Finance;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Bpl\Support\Receivables as Rule;
use Modules\Bpl\Support\SalesFigures;
use Modules\Core\Livewire\StatisticsPage;

/**
 * BPL → Finance → Statistics. The money: what is owed, what came in, what went
 * out, and where it was banked.
 *
 * The Sales dashboard answers "how is the business trading"; this one answers
 * "how is the cash". They share their arithmetic through
 * Modules\Bpl\Support\SalesFigures and Modules\Bpl\Support\Receivables, so a
 * figure can never mean one thing on one page and something else on the other.
 *
 * ## Money is not comparable across currencies
 *
 * BPL invoices in naira, dollars, euros and pounds. Every money tile names its
 * currency and every money chart is drawn for ONE currency at a time — the
 * Receivables tab renders a chart per currency rather than one chart with four
 * incomparable bars, which at these magnitudes (millions of naira against
 * thousands of dollars) would flatten all but one into the axis.
 *
 * Haulage is the exception and is naira throughout, because
 * `bpl_waybill_payment` carries no currency at all.
 *
 * ## The ledger has no window
 *
 * Receivables ignore the date range entirely: a debt does not stop existing
 * because somebody picked "last 90 days", and a figure that moves with the
 * filter is one nobody can act on. The range applies to the flow tabs —
 * money in, money out — where a period is the question.
 */
#[Title('BPL Finance Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    public function pageTitle(): string
    {
        return 'BPL Finance Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'What is owed, what came in, what went out.';
    }

    /** Export finance moves slowly; the short windows say very little. */
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
        return 'bpl.finance.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bpl.finance.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'receivables' => 'Receivables',
            'received' => 'Money In',
            'haulage' => 'Haulage',
            'banks' => 'Banks',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'receivables' => $this->receivablesSection(),
            'received' => $this->receivedSection(),
            'haulage' => $this->haulageSection(),
            'banks' => $this->banksSection(),
            default => $this->overviewSection(),
        };
    }

    protected function db()
    {
        return DB::connection('bpl');
    }

    /* ---------------- Shared pieces ---------------- */

    private const INVOICE_TOTAL = 'ROUND(`f`.`goods_value` + `f`.`containers` * COALESCE(`pf`.`freight_price`, 0), 2)';

    private function balance(): string
    {
        return '(' . self::INVOICE_TOTAL . ' - ' . SalesFigures::SHIPMENT_PAID . ')';
    }

    /** Every shipment with its invoice, its terms and its bill of lading. */
    private function ledger()
    {
        return $this->db()->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id');
    }

    /** Payments received in the window. */
    private function received()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('bpl_invoice_payments as ip')
            ->leftJoin('bpl_packing_list as pl', 'pl.id', '=', 'ip.packing_id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->leftJoin('bpl_accounts as acc', 'acc.id', '=', 'pf.account_id')
            ->leftJoin('bpl_banks as bank', 'bank.id', '=', 'acc.beneficiary')
            ->when($from, fn ($q) => $q->whereBetween('ip.date', [$from, $to]));
    }

    private function money(float $value, ?string $symbol): string
    {
        return ProformaInvoice::symbol((string) $symbol) . $this->figure($value);
    }

    private function naira(float $value): string
    {
        return '₦' . $this->figure($value);
    }

    /** What is owed, per currency — the ledger, whatever the date range. */
    private function owed()
    {
        return $this->ledger()
            ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
            ->selectRaw(
                "COALESCE(`cur`.`code`, '—') as `code`, `cur`.`symbol`,"
                . ' COUNT(*) as `shipments`,'
                . ' ROUND(SUM(' . $this->balance() . '), 2) as `balance`,'
                . ' ROUND(SUM(CASE WHEN ' . Rule::DAYS_OVERDUE . ' > 0 THEN ' . $this->balance() . ' ELSE 0 END), 2) as `overdue`'
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

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $owed = $this->owed();

        $receivedSeries = $this->series('bpl_invoice_payments', 'date', '/', 'COUNT(*)');
        $payments = (int) $this->countOver('bpl_invoice_payments', 'date', '/');

        // Haulage, which is naira and has its own table.
        [$from, $to] = $this->bounds('-');
        $paidOut = (float) $this->db()->table('bpl_waybill_payment_history')
            ->when($from, fn ($q) => $q->whereBetween('payment_date', [$from, $to]))
            ->sum('amount');

        $haulageOwing = (float) $this->db()->table('bpl_waybill_payment as wp')
            ->selectRaw('ROUND(SUM(GREATEST(`wp`.`amount` - ' . self::HAULAGE_PAID . ', 0)), 2) as `v`')
            ->value('v');

        $overdue = $this->ledger()
            ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
            ->whereRaw(Rule::DAYS_OVERDUE . ' > 0')
            ->count();

        return [
            'tiles' => array_merge($this->owedTiles($owed), [
                $this->tile('Overdue Shipments', $this->figure($overdue), 'past their due date',
                    $overdue > 0 ? 'neg' : 'pos'),
                $this->tile('Payments Received', $this->figure($payments), $this->rangeLabel(), 'brand'),
                $this->tile('Haulage Paid Out', $this->naira($paidOut), $this->rangeLabel()),
                $this->tile('Haulage Owing', $this->naira($haulageOwing), 'right now',
                    $haulageOwing > 0 ? 'neg' : 'pos'),
            ]),
            'charts' => [
                $this->chartSpec('bf-in-time', 'bar', 'Payments Received', $receivedSeries['labels'],
                    [['name' => 'Payments', 'data' => $receivedSeries['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bf-owed-state', 'donut', 'Outstanding Shipments by Age',
                    array_values(array_map(fn ($s) => Rule::label($s), array_keys($this->ageCounts()))),
                    [['name' => 'Shipments', 'data' => array_map('floatval', array_values($this->ageCounts()))]],
                    ['subtitle' => 'Count, so the four currencies can share one chart']),
            ],
        ];
    }

    /** What has been paid against a haulier's bill — the receipts, not the stored total. */
    private const HAULAGE_PAID = '(SELECT COALESCE(SUM(`h`.`amount`), 0)'
        . ' FROM `bpl_waybill_payment_history` `h` WHERE `h`.`waybill_payment_id` = `wp`.`id`)';

    /**
     * Outstanding shipments per ageing bucket, as COUNTS.
     *
     * Counts rather than amounts on purpose: a count is the one figure that
     * can put four currencies on one chart without adding them together.
     */
    private function ageCounts(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $rows = $this->ledger()
            ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
            ->selectRaw(Rule::state($this->balance()) . ' as `state`, COUNT(*) as `n`')
            ->groupBy('state')->pluck('n', 'state');

        $out = [];

        foreach (Rule::STATES as $state => $label) {
            if ($state !== 'paid' && ($rows[$state] ?? 0) > 0) {
                $out[$state] = (int) $rows[$state];
            }
        }

        return $cache = $out;
    }

    protected function receivablesSection(): array
    {
        $owed = $this->owed();

        $customers = (int) $this->ledger()
            ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
            ->distinct()->count('s.customerid');

        $oldest = (int) ($this->ledger()
            ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
            ->selectRaw('MAX(' . Rule::DAYS_OVERDUE . ') as `d`')->value('d') ?? 0);

        $ages = $this->ageCounts();

        $charts = [
            $this->chartSpec('bf-age', 'bar', 'Outstanding Shipments by Age',
                array_values(array_map(fn ($s) => Rule::label($s), array_keys($ages))),
                [['name' => 'Shipments', 'data' => array_map('floatval', array_values($ages))]],
                ['span' => 2, 'subtitle' => 'Counts — one chart can hold every currency']),
        ];

        // One chart per currency: naira runs to millions where dollars run to
        // thousands, so a shared axis would hide everything but the naira.
        foreach ($owed as $i => $row) {
            $byCustomer = $this->ledger()
                ->whereRaw($this->balance() . ' >= ' . Rule::TOLERANCE)
                ->when($row->code === '—',
                    fn ($q) => $q->whereNull('cur.code'),
                    fn ($q) => $q->where('cur.code', $row->code))
                ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`,"
                    . ' ROUND(SUM(' . $this->balance() . '), 2) as `v`')
                ->groupBy('grp')->orderByDesc('v')->limit(12)->get();

            $charts[] = $this->chartSpec('bf-owed-' . $i, 'hbar',
                'Owed by Customer — ' . $row->code,
                $byCustomer->pluck('grp')->all(),
                [['name' => 'Outstanding', 'data' => $byCustomer->pluck('v')->map(fn ($v) => (float) $v)->all()]],
                ['subtitle' => 'The ledger as it stands — no date range applies']);
        }

        return [
            'tiles' => array_merge($this->owedTiles($owed), [
                $this->tile('Customers Owing', $this->figure($customers), 'right now',
                    $customers > 0 ? 'neg' : 'pos'),
                $this->tile('Oldest Debt', $oldest > 0 ? $this->figure($oldest) . ' days' : '—',
                    'past its due date', $oldest > 90 ? 'neg' : null),
                $this->tile('Awaiting B/L', $this->figure((float) ($ages['awaiting_bl'] ?? 0)),
                    'invoiced, but the clock has not started',
                    ($ages['awaiting_bl'] ?? 0) > 0 ? 'neg' : null),
            ]),
            'charts' => $charts,
        ];
    }

    protected function receivedSection(): array
    {
        $byCurrency = (clone $this->received())
            ->selectRaw("COALESCE(`cur`.`code`, '—') as `code`, `cur`.`symbol`,"
                . ' COUNT(*) as `n`, ROUND(SUM(`ip`.`amount`), 2) as `v`')
            ->groupBy('cur.code', 'cur.symbol')->orderByDesc('v')->get();

        $time = $this->series('bpl_invoice_payments', 'date', '/', 'COUNT(*)');
        $customers = (int) (clone $this->received())->distinct()->count('s.customerid');

        $tiles = [$this->tile('Payments', $this->figure((float) $byCurrency->sum('n')),
            $this->rangeLabel(), 'brand')];

        foreach ($byCurrency as $row) {
            $tiles[] = $this->tile('Received in ' . $row->code,
                $this->money((float) $row->v, $row->symbol), $this->rangeLabel(), 'pos');
        }

        $tiles[] = $this->tile('Customers Paying', $this->figure($customers), $this->rangeLabel());

        $charts = [
            $this->chartSpec('bf-rec-time', 'bar', 'Payments Received', $time['labels'],
                [['name' => 'Payments', 'data' => $time['data']]],
                ['span' => 2, 'subtitle' => $this->rangeLabel()]),
        ];

        foreach ($byCurrency as $i => $row) {
            $byCustomer = (clone $this->received())
                ->when($row->code === '—',
                    fn ($q) => $q->whereNull('cur.code'),
                    fn ($q) => $q->where('cur.code', $row->code))
                ->selectRaw("COALESCE(`c`.`customername`, '—') as `grp`, ROUND(SUM(`ip`.`amount`), 2) as `v`")
                ->groupBy('grp')->orderByDesc('v')->limit(12)->get();

            $charts[] = $this->chartSpec('bf-rec-' . $i, 'hbar',
                'Received by Customer — ' . $row->code,
                $byCustomer->pluck('grp')->all(),
                [['name' => 'Received', 'data' => $byCustomer->pluck('v')->map(fn ($v) => (float) $v)->all()]],
                ['subtitle' => $this->rangeLabel()]);
        }

        return ['tiles' => $tiles, 'charts' => $charts];
    }

    /**
     * Haulage — money out. Naira throughout, because `bpl_waybill_payment`
     * carries no currency, so this is the one tab where a single axis is
     * honest.
     */
    protected function haulageSection(): array
    {
        [$from, $to] = $this->bounds('-');

        $receipts = $this->db()->table('bpl_waybill_payment_history as h')
            ->when($from, fn ($q) => $q->whereBetween('h.payment_date', [$from, $to]));

        $paidOut = (float) (clone $receipts)->sum('h.amount');
        $count = (int) (clone $receipts)->count();

        $agreed = (float) $this->db()->table('bpl_waybill_payment as wp')
            ->when($from, fn ($q) => $q->whereBetween('wp.created_at', [$from . ' 00:00:00', $to . ' 23:59:59']))
            ->sum('wp.amount');

        $owing = (float) $this->db()->table('bpl_waybill_payment as wp')
            ->selectRaw('ROUND(SUM(GREATEST(`wp`.`amount` - ' . self::HAULAGE_PAID . ', 0)), 2) as `v`')
            ->value('v');

        $unpaid = (int) $this->db()->table('bpl_waybill_payment as wp')
            ->whereRaw('`wp`.`amount` - ' . self::HAULAGE_PAID . ' > 0')->count();

        $byTransporter = $this->db()->table('bpl_waybill_payment as wp')
            ->leftJoin('bpl_transporters as t', 't.id', '=', 'wp.transporter_id')
            ->selectRaw("COALESCE(`t`.`transportername`, '—') as `grp`,"
                . ' ROUND(SUM(GREATEST(`wp`.`amount` - ' . self::HAULAGE_PAID . ', 0)), 2) as `v`')
            ->groupBy('grp')->orderByDesc('v')->limit(12)->get();

        // The receipts table stores a real DATE, so this series does not go
        // through the legacy string helpers the rest of the module needs.
        $time = $this->db()->table('bpl_waybill_payment_history')
            ->when($from, fn ($q) => $q->whereBetween('payment_date', [$from, $to]))
            ->selectRaw("DATE_FORMAT(`payment_date`, '%Y/%m') as `grp`, ROUND(SUM(`amount`), 2) as `v`")
            ->groupBy('grp')->orderBy('grp')->get();

        return [
            'tiles' => [
                $this->tile('Paid Out', $this->naira($paidOut), $this->rangeLabel(), 'brand'),
                $this->tile('Receipts', $this->figure((float) $count), $this->rangeLabel()),
                $this->tile('Agreed', $this->naira($agreed), 'bills raised in this window'),
                $this->tile('Still Owing', $this->naira($owing), 'right now', $owing > 0 ? 'neg' : 'pos'),
                $this->tile('Waybills Unpaid', $this->figure((float) $unpaid), 'right now',
                    $unpaid > 0 ? 'neg' : null),
            ],
            'charts' => [
                $this->chartSpec('bf-haul-time', 'bar', 'Haulage Paid Out',
                    $time->pluck('grp')->all(),
                    [['name' => 'Paid', 'data' => $time->pluck('v')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel(), 'valueFmt' => 'ngn']),
                $this->chartSpec('bf-haul-owing', 'hbar', 'Owing by Transporter',
                    $byTransporter->pluck('grp')->all(),
                    [['name' => 'Owing', 'data' => $byTransporter->pluck('v')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2, 'valueFmt' => 'ngn']),
            ],
        ];
    }

    /**
     * The receiving accounts, by what they carry.
     *
     * The bank is the one the PROFORMA named — where the money was asked for,
     * not proof of where it landed; nothing in this data records that.
     */
    protected function banksSection(): array
    {
        $accounts = (int) $this->db()->table('bpl_accounts')->whereNull('deleted_at')->count();
        $banks = (int) $this->db()->table('bpl_banks')->count();

        $named = (int) $this->db()->table('bpl_accounts as acc')
            ->whereNull('acc.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bpl_proforma as pf')
                ->whereColumn('pf.account_id', 'acc.id')->whereNull('pf.deleted_at'))
            ->count();

        $byBank = (clone $this->received())
            ->selectRaw("COALESCE(NULLIF(`bank`.`name`, ''), '— none named —') as `grp`, COUNT(*) as `n`")
            ->groupBy('grp')->orderByDesc('n')->limit(12)->get();

        $byTerms = $this->db()->table('bpl_proforma as pf')
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->whereNull('pf.deleted_at')
            ->selectRaw("COALESCE(`pt`.`payment_terms`, '—') as `grp`, COUNT(*) as `n`")
            ->groupBy('grp')->orderByDesc('n')->limit(12)->get();

        return [
            'tiles' => [
                $this->tile('Receiving Accounts', $this->figure((float) $accounts), null, 'brand'),
                $this->tile('Named on a Proforma', $this->figure((float) $named)),
                $this->tile('Never Named', $this->figure((float) ($accounts - $named)),
                    'accounts nothing points at', $accounts - $named > 0 ? 'neg' : null),
                $this->tile('Banks on File', $this->figure((float) $banks)),
            ],
            'charts' => [
                $this->chartSpec('bf-bank-pay', 'hbar', 'Payments by Receiving Bank',
                    $byBank->pluck('grp')->all(),
                    [['name' => 'Payments', 'data' => $byBank->pluck('n')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel()]),
                $this->chartSpec('bf-terms', 'hbar', 'Proformas by Payment Terms',
                    $byTerms->pluck('grp')->all(),
                    [['name' => 'Proformas', 'data' => $byTerms->pluck('n')->map(fn ($v) => (float) $v)->all()]],
                    ['span' => 2]),
            ],
        ];
    }
}
