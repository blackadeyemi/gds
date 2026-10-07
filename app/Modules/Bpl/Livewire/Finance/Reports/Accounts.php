<?php

namespace Modules\Bpl\Livewire\Finance\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Support\SalesFigures;

/**
 * BPL → Finance → Reports → Bank Accounts. Every receiving account BPL
 * publishes on a proforma, and what has been asked for and received through
 * it.
 *
 * The Banks and Accounts screens maintain the masters; nothing has ever shown
 * what the masters are USED for. An account is printed on an invoice and a
 * customer pays into it, so the questions worth asking are which accounts are
 * live, which are named on nothing, and how much money each is carrying.
 *
 * ## What this can and cannot say
 *
 * The account is the one the PROFORMA named — where the money was asked for.
 * It is not proof of where it landed: `bpl_invoice_payments` records the
 * amount and the date and nothing about the bank. Reconciling against a real
 * statement is outside this data, and the column headings say "asked for" and
 * "received against" rather than pretending otherwise.
 *
 * ## A bank with no account, and an account no proforma names
 *
 * Both exist. The master tables were maintained for years with no report to
 * read them back, so there is a tail: accounts nothing points at, and a
 * dangling bank id (#61) an account still references. The **Unused** view is
 * that tail, and it is the one worth working down.
 */
#[Title('BPL Bank Accounts Report')]
class Accounts extends FinanceReport
{
    public function title(): string
    {
        return 'Bank Accounts Report';
    }

    public function printKey(): string
    {
        return 'accounts';
    }

    protected function headline(): string
    {
        return 'The receiving accounts proformas name, and what has come through each.';
    }

    /**
     * A register, not a period. An account's whole history is the point — how
     * much has ever been billed to it — and a date range would answer a
     * question nobody asks of a bank account list.
     */
    public function hasDateRange(): bool
    {
        return false;
    }

    /* ---------------- Filters ---------------- */

    /** @var array<string,array>|null */
    private ?array $optCache = null;

    protected function options(): array
    {
        return $this->optCache ??= [
            'currencies' => $this->shared('currencies'),
            'banks' => $this->db()->table('bpl_banks')
                ->whereNotNull('name')->where('name', '<>', '')
                ->orderBy('name')->pluck('name', 'id')->all(),
            'used' => ['yes' => 'Named on a proforma', 'no' => 'Never named'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'bank' => ['label' => 'Bank', 'options' => $o['banks'], 'width' => 260],
            'currency' => ['label' => 'Currency', 'options' => $o['currencies'], 'width' => 150],
            'used' => ['label' => 'In Use', 'options' => $o['used'], 'width' => 190],
        ];
    }

    protected function joinedFilterKeys(): array
    {
        return ['bank', 'currency', 'used'];
    }

    /* ---------------- The query ---------------- */

    /** Proformas naming this account. */
    private const PROFORMAS = '(SELECT COUNT(*) FROM `bpl_proforma` `pf`'
        . ' WHERE `pf`.`account_id` = `acc`.`id` AND `pf`.`deleted_at` IS NULL)';

    /**
     * Invoiced and received through an account.
     *
     * Both walk the shipments raised against the proformas that name it, so
     * the figures are the same ones the Shipments and Receivables reports
     * show — one arithmetic, in SalesFigures, read from three places.
     */
    private function flowSub()
    {
        return $this->db()->table('bpl_proforma as pf')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.order_id', '=', 'pf.order_id')
            ->join('bpl_packing_list as pl', 'pl.id', '=', 'f.packing_id')
            ->whereNull('pf.deleted_at')
            ->groupBy('pf.account_id')
            ->selectRaw(
                '`pf`.`account_id`,'
                . ' COUNT(*) as `shipments`,'
                . ' ROUND(SUM(`f`.`goods_value` + `f`.`containers` * COALESCE(`pf`.`freight_price`, 0)), 2) as `invoiced`,'
                . ' ROUND(SUM(' . SalesFigures::SHIPMENT_PAID . '), 2) as `received`'
            );
    }

    protected function base()
    {
        $q = $this->db()->table('bpl_accounts as acc')
            ->leftJoin('bpl_banks as bank', 'bank.id', '=', 'acc.beneficiary')
            ->leftJoin('bpl_banks as inter', 'inter.id', '=', 'acc.intermediary')
            ->leftJoin('bpl_banks as corr', 'corr.id', '=', 'acc.correspondent')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'acc.currency_id')
            ->leftJoinSub($this->flowSub(), 'flow', 'flow.account_id', '=', 'acc.id')
            ->whereNull('acc.deleted_at');

        $this->applyFilters($q, [
            'bank' => 'acc.beneficiary',
            'currency' => 'acc.currency_id',
        ]);

        return match ((string) ($this->filters['used'] ?? '')) {
            'yes' => $q->whereRaw(self::PROFORMAS . ' > 0'),
            'no' => $q->whereRaw(self::PROFORMAS . ' = 0'),
            default => $q,
        };
    }

    protected function select()
    {
        return $this->base()->selectRaw(
            '`acc`.`id`, `acc`.`account`,'
            . " COALESCE(`acc`.`further_acc`, '') as `further_acc`,"
            // A dangling beneficiary id is a real condition in this data, so
            // it reads as one rather than as a blank cell.
            . " CASE WHEN `acc`.`beneficiary` IS NULL THEN '—'"
            . " WHEN `bank`.`id` IS NULL THEN CONCAT('Missing bank #', `acc`.`beneficiary`)"
            . ' ELSE `bank`.`name` END as `bank`,'
            . " COALESCE(`bank`.`number`, '') as `bank_number`,"
            . " COALESCE(`bank`.`swiftcode`, '') as `swift`,"
            . " COALESCE(`inter`.`name`, '') as `intermediary`,"
            . " COALESCE(`corr`.`name`, '') as `correspondent`,"
            . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
            . ' ' . self::PROFORMAS . ' as `proformas`,'
            . ' COALESCE(`flow`.`shipments`, 0) as `shipments`,'
            . ' ROUND(COALESCE(`flow`.`invoiced`, 0), 2) as `invoiced`,'
            . ' ROUND(COALESCE(`flow`.`received`, 0), 2) as `received`,'
            . ' ROUND(COALESCE(`flow`.`invoiced`, 0) - COALESCE(`flow`.`received`, 0), 2) as `outstanding`'
        );
    }

    /* ---------------- Views ---------------- */

    private const SEARCHABLE = ['acc.account', 'acc.further_acc', 'bank.name',
        'bank.number', 'bank.swiftcode', 'bank.sortcode'];

    public function views(): array
    {
        $columns = [
            ['Account', 'account'],
            ['Bank', 'bank'],
            ['Currency', 'currency'],
            ['Proformas', 'proformas'],
            ['Shipments', 'shipments'],
            ['Asked For', 'invoiced', $this->moneyCell('invoiced')],
            ['Received Against', 'received', $this->moneyCell('received')],
            ['Outstanding', 'outstanding', $this->moneyCell('outstanding')],
        ];

        $money = ['invoiced', 'received', 'outstanding'];
        $sortable = ['account', 'bank', 'currency', 'proformas', 'shipments',
            'invoiced', 'received', 'outstanding'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->select()->orderByDesc(DB::raw(self::PROFORMAS))
                    ->orderBy('acc.account'),
                'totals' => $this->moneyTotals($money, ['proformas', 'shipments']),
                'sortable' => $sortable,
            ],

            'unused' => [
                'label' => 'Unused',
                'type' => 'table',
                'columns' => $columns,
                'searchable' => self::SEARCHABLE,
                // An account no live proforma names. Harmless, but it is what
                // a clerk picks by mistake, and there is no other way to see it.
                'query' => fn () => $this->select()->whereRaw(self::PROFORMAS . ' = 0')
                    ->orderBy('acc.account'),
                'totals' => [],
                'sortable' => $sortable,
            ],

            'details' => [
                'label' => 'Full details',
                'type' => 'table',
                'columns' => [
                    ['Account', 'account'],
                    ['Bank', 'bank'],
                    ['Bank Number', 'bank_number'],
                    ['SWIFT', 'swift'],
                    ['Intermediary', 'intermediary'],
                    ['Correspondent', 'correspondent'],
                    ['Further Credit', 'further_acc'],
                    ['Currency', 'currency'],
                ],
                'searchable' => self::SEARCHABLE,
                // Everything the proforma's bank block prints, so a wire
                // instruction can be checked against the report.
                'query' => fn () => $this->select()->orderBy('bank')->orderBy('acc.account'),
                'totals' => [],
                'sortable' => ['account', 'bank', 'currency'],
            ],

            'by_bank' => [
                'label' => 'Summary (by bank)',
                'type' => 'summary',
                'columns' => [
                    ['Bank', 'bank'],
                    // Grouped by currency as well — a bank holding an account
                    // in each of two currencies cannot have one meaningful
                    // total, so the column has to say which row is which.
                    ['Currency', 'currency'],
                    ['Accounts', 'accounts'],
                    ['Proformas', 'proformas'],
                    ['Asked For', 'invoiced', $this->moneyCell('invoiced')],
                    ['Received Against', 'received', $this->moneyCell('received')],
                ],
                'searchable' => self::SEARCHABLE,
                'query' => fn () => $this->base()->selectRaw(
                    "CASE WHEN `acc`.`beneficiary` IS NULL THEN '—'"
                    . " WHEN `bank`.`id` IS NULL THEN CONCAT('Missing bank #', `acc`.`beneficiary`)"
                    . ' ELSE `bank`.`name` END as `bank`,'
                    . " COALESCE(`cur`.`code`, '') as `currency`, `cur`.`symbol`,"
                    . ' COUNT(*) as `accounts`,'
                    . ' SUM(' . self::PROFORMAS . ') as `proformas`,'
                    . ' ROUND(SUM(COALESCE(`flow`.`invoiced`, 0)), 2) as `invoiced`,'
                    . ' ROUND(SUM(COALESCE(`flow`.`received`, 0)), 2) as `received`'
                )->groupBy('bank', 'cur.code', 'cur.symbol')->orderByDesc('invoiced'),
                'totals' => $this->moneyTotals(['invoiced', 'received'], ['accounts', 'proformas']),
            ],
        ];
    }

    /* ---------------- Drill-down ---------------- */

    public function expandableBy(): ?array
    {
        return $this->view === 'by_bank' ? ['bank', 'currency'] : null;
    }

    public function detailColumns(): array
    {
        return [
            ['Account', 'account'],
            ['Currency', 'currency'],
            ['SWIFT', 'swift'],
            ['Proformas', 'proformas'],
            ['Asked For', 'invoiced', $this->moneyCell('invoiced')],
            ['Received Against', 'received', $this->moneyCell('received')],
        ];
    }

    public function detailSearchable(): array
    {
        return ['acc.account', 'bank.name'];
    }

    public function detailQuery(string $key)
    {
        if ($this->view !== 'by_bank') {
            return null;
        }

        [$bank, $currency] = $this->detailKeyParts($key) + ['', ''];

        return $this->select()
            ->where(DB::raw(
                "CASE WHEN `acc`.`beneficiary` IS NULL THEN '—'"
                . " WHEN `bank`.`id` IS NULL THEN CONCAT('Missing bank #', `acc`.`beneficiary`)"
                . ' ELSE `bank`.`name` END'
            ), $bank)
            ->where(DB::raw("COALESCE(`cur`.`code`, '')"), $currency)
            ->orderBy('acc.account');
    }

    public function detailTitle(string $key): string
    {
        return implode(' · ', array_filter(
            $this->detailKeyParts($key),
            fn ($part) => (string) $part !== ''
        ));
    }
}
