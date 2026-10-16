<?php

namespace Modules\Bpl\Livewire\Finance\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;

/**
 * BPL → Finance → Reports → Waybill Payments. What BPL owes its hauliers for
 * each trip, and every receipt against it.
 *
 * The mirror of the Invoice Payments report: that one is money in from
 * customers, this is money out to transporters. Rebuilt from the legacy
 * `waybillpayment.php`, which had no report at all — the screen listed the
 * records and there was no way to read a period, a haulier's account or what
 * was outstanding.
 *
 * ## Two units, two views
 *
 * A waybill payment is ONE record holding the agreed amount, with MANY
 * receipts under it. Those are different questions — "what do we owe on this
 * trip" and "what did we pay out in August" — so they are different views:
 * **Waybills** has a row per trip, **Receipts** a row per payment, and the two
 * total different things on purpose.
 *
 * ## One currency
 *
 * Haulage is local and `bpl_waybill_payment` carries no currency column, so
 * every figure here is naira. That is why this report has no currency filter
 * and totals unconditionally, unlike every other BPL money report.
 *
 * ## Paid and balance are derived
 *
 * The stored `amount_paid`, `balance` and `status` were maintained by hand by
 * the legacy and were wrong — all sixteen rows said "Paid", twelve with the
 * whole amount outstanding (see the Waybill Payments screen, which repaired
 * them). This report reads the RECEIPTS and adds them up rather than trusting
 * the stored total, so a row that drifts again shows the truth.
 */
#[Title('BPL Waybill Payments Report')]
class WaybillPayments extends FinanceReport
{
    public function title(): string
    {
        return 'Waybill Payments Report';
    }

    public function printKey(): string
    {
        return 'waybill-payments';
    }

    protected function headline(): string
    {
        return 'What each haulier is owed for a trip, and what has been paid out.';
    }

    /**
     * Haulage is naira only, so nothing here needs a currency before it can be
     * totalled.
     */
    protected function hasMoneyColumns(): bool
    {
        return false;
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'transporters' => $this->db()->table('bpl_transporters')
                ->orderBy('transportername')->pluck('transportername', 'id')->all(),
            'customers' => $this->db()->table('bpl_waybill_payment')
                ->whereNotNull('customer_name')->where('customer_name', '<>', '')
                ->select('customer_name')->distinct()->orderBy('customer_name')
                ->pluck('customer_name', 'customer_name')->all(),
            'status' => [
                'outstanding' => 'Nothing paid',
                'partial' => 'Part paid',
                'paid' => 'Paid in full',
                'owing' => 'Anything still owing',
            ],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'transporter' => ['label' => 'Transporter', 'options' => $o['transporters'], 'width' => 230],
            'customer' => ['label' => 'Delivered To', 'options' => $o['customers'], 'width' => 200],
            'status' => ['label' => 'Status', 'options' => $o['status'], 'width' => 190],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['status'];
    }

    /* ---------------- The query ---------------- */

    /**
     * The date range, applied to when the haulier's bill was AGREED.
     *
     * `bpl_waybill_payment` has no date of its own beyond `created_at`, which
     * is a real timestamp rather than the legacy 'Y/m/d' text every other BPL
     * table uses — so the framework's applyDate() cannot be used here. Bounded
     * on the column itself rather than on DATE(created_at), which would make
     * the index unusable.
     */
    private function applyCreatedRange($q)
    {
        if ($this->dateFrom !== '' && $this->dateTo !== '') {
            return $q->whereBetween('wp.created_at',
                [$this->dateFrom . ' 00:00:00', $this->dateTo . ' 23:59:59']);
        }

        if ($this->dateFrom !== '') {
            return $q->where('wp.created_at', '>=', $this->dateFrom . ' 00:00:00');
        }

        if ($this->dateTo !== '') {
            return $q->where('wp.created_at', '<=', $this->dateTo . ' 23:59:59');
        }

        return $q;
    }

    /** What has actually been paid, from the receipts rather than the stored total. */
    private const PAID = '(SELECT ROUND(COALESCE(SUM(`h`.`amount`), 0), 2)'
        . ' FROM `bpl_waybill_payment_history` `h` WHERE `h`.`waybill_payment_id` = `wp`.`id`)';

    private const BALANCE = '(ROUND(`wp`.`amount`, 2) - ' . self::PAID . ')';

    protected function base()
    {
        $q = $this->db()->table('bpl_waybill_payment as wp')
            ->leftJoin('bpl_transporters as t', 't.id', '=', 'wp.transporter_id');

        $this->applyCreatedRange($q);
        $this->applyFilters($q, [
            'transporter' => 'wp.transporter_id',
            'customer' => 'wp.customer_name',
        ]);

        return match ((string) ($this->filters['status'] ?? '')) {
            'outstanding' => $q->whereRaw(self::PAID . ' <= 0'),
            'partial' => $q->whereRaw(self::PAID . ' > 0')->whereRaw(self::BALANCE . ' > 0'),
            'paid' => $q->whereRaw(self::BALANCE . ' <= 0'),
            'owing' => $q->whereRaw(self::BALANCE . ' > 0'),
            default => $q,
        };
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`wp`.`id`, `wp`.`barcode`, `wp`.`created_at`,'
            . ' DATE(`wp`.`created_at`) as `raised_on`,'
            . " COALESCE(`t`.`transportername`, '—') as `transporter`,"
            . " COALESCE(`t`.`transportercode`, '') as `transportercode`,"
            . " COALESCE(`wp`.`customer_name`, '') as `delivered_to`,"
            . " COALESCE(`wp`.`vechicle_number`, '') as `vehicle`,"
            . ' ROUND(`wp`.`amount`, 2) as `amount`,'
            . ' ' . self::PAID . ' as `paid`,'
            . ' ROUND(' . self::BALANCE . ', 2) as `balance`,'
            . ' (SELECT COUNT(*) FROM `bpl_waybill_payment_history` `h`'
            . ' WHERE `h`.`waybill_payment_id` = `wp`.`id`) as `receipts`,'
            . ' (SELECT MAX(`h`.`payment_date`) FROM `bpl_waybill_payment_history` `h`'
            . ' WHERE `h`.`waybill_payment_id` = `wp`.`id`) as `last_paid`,'
            // The status the row STORES, kept beside the derived one so a
            // disagreement is visible rather than quietly overwritten.
            . ' `wp`.`status` as `stored_status`'
        );
    }

    /** One row per receipt, for the Receipts view. */
    protected function receipts()
    {
        $q = $this->db()->table('bpl_waybill_payment_history as h')
            ->join('bpl_waybill_payment as wp', 'wp.id', '=', 'h.waybill_payment_id')
            ->leftJoin('bpl_transporters as t', 't.id', '=', 'wp.transporter_id');

        // A receipt's own date, which is what a payments-out period means.
        $this->applyDate($q, 'h.payment_date');
        $this->applyFilters($q, [
            'transporter' => 'wp.transporter_id',
            'customer' => 'wp.customer_name',
        ]);

        return $q->selectRaw(
            '`h`.`id`, `h`.`payment_date`, ROUND(`h`.`amount`, 2) as `amount`,'
            . " COALESCE(`h`.`payment_note`, '') as `note`,"
            . ' `wp`.`barcode`,'
            . " COALESCE(`t`.`transportername`, '—') as `transporter`,"
            . " COALESCE(`wp`.`customer_name`, '') as `delivered_to`,"
            . " COALESCE(`wp`.`vechicle_number`, '') as `vehicle`,"
            . ' ROUND(`wp`.`amount`, 2) as `agreed`'
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['wp.barcode', 't.transportername', 't.transportercode',
        'wp.customer_name', 'wp.vechicle_number'];

    private const RECEIPT_SEARCHABLE = ['wp.barcode', 't.transportername',
        'wp.customer_name', 'wp.vechicle_number', 'h.payment_note'];

    public function views(): array
    {
        $naira = fn (string $field) => fn ($r) => ($v = data_get($r, $field)) === null || $v === ''
            ? '' : '₦' . number_format((float) $v, 2);

        $columns = [
            ['Waybill', 'barcode', fn ($r) => '<span class="mono">' . e((string) $r->barcode) . '</span>'],
            $this->dateCol('Raised', 'raised_on'),
            ['Transporter', 'transporter'],
            ['Delivered To', 'delivered_to'],
            ['Vehicle', 'vehicle'],
            ['Agreed', 'amount', $naira('amount')],
            ['Paid', 'paid', $naira('paid')],
            ['Balance', 'balance', $naira('balance')],
            ['Receipts', 'receipts'],
            ['Status', 'status', fn ($r) => $this->statusCell($r)],
        ];

        $money = ['amount', 'paid', 'balance'];

        return [
            'default' => [
                'label' => 'Waybills',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc('wp.id'),
                'totals' => $money,
                'sortable' => ['barcode', 'raised_on', 'transporter', 'delivered_to',
                    'vehicle', 'amount', 'paid', 'balance', 'receipts'],
            ],

            'owing' => [
                'label' => 'Still owing',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->whereRaw(self::BALANCE . ' > 0')
                    ->orderByDesc(DB::raw(self::BALANCE)),
                'totals' => $money,
                'sortable' => ['barcode', 'raised_on', 'transporter', 'amount', 'paid', 'balance'],
            ],

            'receipts' => [
                'label' => 'Receipts',
                'type' => 'table',
                'columns' => [
                    $this->dateCol('Paid', 'payment_date'),
                    ['Waybill', 'barcode', fn ($r) => '<span class="mono">' . e((string) $r->barcode) . '</span>'],
                    ['Transporter', 'transporter'],
                    ['Delivered To', 'delivered_to'],
                    ['Vehicle', 'vehicle'],
                    ['Amount', 'amount', $naira('amount')],
                    ['Against', 'agreed', $naira('agreed')],
                    ['Note', 'note'],
                ],
                'searchable' => self::RECEIPT_SEARCHABLE,
                // One row per payment made, dated by the payment itself — this
                // is the view a period question is asked of.
                'query' => fn () => $this->receipts()
                    ->orderByDesc('h.payment_date')->orderByDesc('h.id'),
                // `agreed` is the trip's whole bill repeated on each of its
                // receipts, so totalling it would multiply.
                'totals' => ['amount'],
                'sortable' => ['payment_date', 'barcode', 'transporter', 'amount'],
            ],

            'by_transporter' => [
                'label' => 'Summary (by transporter)',
                'type' => 'summary',
                'columns' => [
                    ['Transporter', 'transporter'],
                    ['Waybills', 'waybills'],
                    ['Agreed', 'amount', $naira('amount')],
                    ['Paid', 'paid', $naira('paid')],
                    ['Owing', 'balance', $naira('balance')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "COALESCE(`t`.`transportername`, '—') as `transporter`,"
                    . ' COUNT(*) as `waybills`,'
                    . ' ROUND(SUM(`wp`.`amount`), 2) as `amount`,'
                    . ' ROUND(SUM(' . self::PAID . '), 2) as `paid`,'
                    . ' ROUND(SUM(' . self::BALANCE . '), 2) as `balance`'
                )->groupBy('t.transportername')->orderByDesc('balance'),
                'totals' => ['waybills', 'amount', 'paid', 'balance'],
            ],

            'by_month' => [
                'label' => 'Summary (paid by month)',
                'type' => 'summary',
                'columns' => [
                    ['Month', 'month'],
                    ['Receipts', 'receipts'],
                    ['Transporters', 'transporters'],
                    ['Paid Out', 'amount', $naira('amount')],
                ],
                'searchable' => self::RECEIPT_SEARCHABLE,
                // Over the RECEIPTS, because "what went out in August" is a
                // question about payments, not about trips.
                'query' => fn () => $this->receiptsBase()->selectRaw(
                    "DATE_FORMAT(`h`.`payment_date`, '%Y/%m') as `month`,"
                    . ' COUNT(*) as `receipts`,'
                    . ' COUNT(DISTINCT `wp`.`transporter_id`) as `transporters`,'
                    . ' ROUND(SUM(`h`.`amount`), 2) as `amount`'
                )->groupBy('month')->orderByDesc('month'),
                'totals' => ['receipts', 'amount'],
            ],
        ];
    }

    /** The receipts query without its select list, for aggregating. */
    private function receiptsBase()
    {
        $q = $this->db()->table('bpl_waybill_payment_history as h')
            ->join('bpl_waybill_payment as wp', 'wp.id', '=', 'h.waybill_payment_id')
            ->leftJoin('bpl_transporters as t', 't.id', '=', 'wp.transporter_id');

        $this->applyDate($q, 'h.payment_date');
        $this->applyFilters($q, [
            'transporter' => 'wp.transporter_id',
            'customer' => 'wp.customer_name',
        ]);

        return $q;
    }

    /* ---------------- Cells ---------------- */

    private function statusCell($row): string
    {
        $paid = (float) $row->paid;
        $balance = (float) $row->balance;

        $derived = $balance <= 0 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Outstanding');

        $tone = ['Paid' => 'badge-success', 'Partial' => 'badge-warning', 'Outstanding' => 'badge-danger'];

        $out = '<span class="badge ' . $tone[$derived] . '">' . $derived . '</span>';

        // The stored status was maintained by hand and has been wrong before;
        // a disagreement is worth seeing rather than hiding.
        if ((string) $row->stored_status !== '' && (string) $row->stored_status !== $derived) {
            $out .= ' <span class="badge badge-muted" title="The row stores &quot;'
                . e((string) $row->stored_status) . '&quot;, which the receipts do not agree with">stored: '
                . e((string) $row->stored_status) . '</span>';
        }

        return $out;
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return match ($this->view) {
            'by_transporter' => ['transporter'],
            'by_month' => ['month'],
            default => null,
        };
    }

    public function detailColumns(): array
    {
        $naira = fn (string $field) => fn ($r) => ($v = data_get($r, $field)) === null || $v === ''
            ? '' : '₦' . number_format((float) $v, 2);

        return $this->view === 'by_month'
            ? [
                $this->dateCol('Paid', 'payment_date'),
                ['Waybill', 'barcode'],
                ['Transporter', 'transporter'],
                ['Vehicle', 'vehicle'],
                ['Amount', 'amount', $naira('amount')],
                ['Note', 'note'],
            ]
            : [
                ['Waybill', 'barcode'],
                $this->dateCol('Raised', 'raised_on'),
                ['Delivered To', 'delivered_to'],
                ['Vehicle', 'vehicle'],
                ['Agreed', 'amount', $naira('amount')],
                ['Paid', 'paid', $naira('paid')],
                ['Balance', 'balance', $naira('balance')],
            ];
    }

    public function detailSearchable(): array
    {
        return $this->view === 'by_month' ? self::RECEIPT_SEARCHABLE : self::SEARCHABLE;
    }

    public function detailQuery(string $key)
    {
        $parts = $this->detailKeyParts($key);

        if ($this->view === 'by_month') {
            return $this->receipts()
                ->where(DB::raw("DATE_FORMAT(`h`.`payment_date`, '%Y/%m')"), $parts[0] ?? '')
                ->orderByDesc('h.payment_date')->orderByDesc('h.id');
        }

        if ($this->view === 'by_transporter') {
            return $this->select()
                ->where(DB::raw("COALESCE(`t`.`transportername`, '—')"), $parts[0] ?? '')
                ->orderByDesc('wp.id');
        }

        return null;
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
