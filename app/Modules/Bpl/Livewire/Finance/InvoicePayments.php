<?php

namespace Modules\Bpl\Livewire\Finance;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplInvoicePayment;
use Modules\Bpl\Models\BplPackingList;
use Modules\Bpl\Support\PackingList as PackingListSupport;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Finance → Invoice Payments. Rebuild of the payment corner of the
 * legacy packing-list screen (`Bil\Bpl\PackingList::addPayment` /
 * `getPayments`): what the CUSTOMER has paid against a shipment's final
 * invoice.
 *
 * It is the mirror of Waybill Payments — that is what BPL owes a haulier for a
 * trip; this is what a customer owes BPL for a shipment.
 *
 * ONE ROW PER PACKING LIST. The legacy updated the row in place instead of
 * adding another, so `amount` is the running total received rather than a
 * single receipt, and gds keeps that shape so both apps read the same figure.
 * (Where a receipt-by-receipt history matters — the haulier side — that is
 * what `bpl_waybill_payment_history` is for.)
 *
 * WHAT IS OWED is not stored anywhere: it is the final invoice, priced on what
 * actually shipped — each proforma line charged on the weight in the
 * containers, plus freight per container shipped. This page works it out the
 * same way the invoice prints it, so the two can never disagree.
 *
 * Changes from legacy:
 *  - **The invoice value is shown beside the payment**, with the balance. The
 *    legacy screen showed the amount alone, so nothing said whether a shipment
 *    was settled.
 *  - **A payment cannot exceed the invoice** (beyond a rounding tolerance) and
 *    cannot be negative.
 *  - **Shipments with nothing paid are visible** as a view of their own; the
 *    legacy only listed what had a payment row.
 */
#[Title('BPL Invoice Payments')]
class InvoicePayments extends DataGrid
{
    /** Rounding slack allowed over the invoice, in currency units. */
    public const TOLERANCE = 1.0;

    public ?int $packing_id = null;
    public string $amount = '';
    public string $dateIso = '';

    /** Read-only context for the form. */
    public array $context = [];

    public function pageKey(): string { return 'bpl.finance.invoice-payments'; }
    public function pageLabel(): string { return 'BPL Invoice Payments'; }
    public function pageSubtitle(): string { return 'What customers have paid against each shipment\'s final invoice.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.invoice-payment'; }
    public function defaultSort(): array { return ['pl.date', 'desc']; }
    public function modalSize(): string { return '560px'; }

    /** Nothing is created here: a payment belongs to a shipment that exists. */
    public function create(): void {}

    /* ---------------- The grid ---------------- */

    /**
     * One row per SHIPMENT, with its payment if it has one.
     *
     * Driven off the packing list rather than the payment table, so a shipment
     * nobody has paid for still appears — that is the row a credit controller
     * actually wants.
     */
    protected function baseQuery()
    {
        // An Eloquent query, not a raw one: the grid keys each row with
        // getKey(), so rows have to be models — here the packing list, with
        // its payment and the pieces of the invoice hung off it.
        return BplPackingList::query()->from('bpl_packing_list as pl')
            ->leftJoin('bpl_invoice_payments as p', 'p.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_proforma as pf', 'pf.order_id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->select('pl.id', 'pl.number as packing_number', 'pl.date', 'pl.containers', 'pl.order_id')
            ->selectRaw('p.id as payment_id, p.amount, p.date as payment_date')
            ->selectRaw('c.id as customer_id, c.customerlabel, c.customername')
            ->selectRaw('cur.code as currency, cur.symbol, pf.freight_price, pf.container');
    }

    public function filterDefs(): array
    {
        return [
            'currency' => [
                'label' => 'Currency',
                'options' => DB::connection('bpl')->table('currencies')->orderBy('code')->pluck('code', 'id')->all(),
                'width' => 150,
            ],
        ];
    }

    public function views(): array
    {
        $columns = [
            ['Shipment', 'packing_number', fn ($r) => '<span class="mono">'
                . e(PackingListSupport::label($r->packing_number, (int) $r->customer_id, $r->customerlabel)) . '</span>'],
            ['Date', 'pl.date', fn ($r) => e((string) $r->date)],
            ['Customer', 'customername', fn ($r) => e((string) ($r->customername ?: '—'))],
            ['Invoice', 'invoice_value', fn ($r) => $this->money($r, $this->invoiceValue($r))],
            ['Paid', 'amount', fn ($r) => $r->payment_id
                ? $this->money($r, (float) $r->amount)
                : '<span class="text-muted">—</span>'],
            ['Balance', 'balance', function ($r) {
                $balance = round($this->invoiceValue($r) - (float) ($r->amount ?? 0), 2);

                return '<span class="badge ' . ($balance <= self::TOLERANCE ? 'badge-success' : 'badge-warning') . '">'
                    . $this->money($r, $balance) . '</span>';
            }],
            ['Paid On', 'payment_date', fn ($r) => $r->payment_date
                ? e((string) $r->payment_date)
                : '<span class="text-muted">—</span>'],
        ];

        $searchable = ['pl.number', 'c.customername'];
        $sortable = ['packing_number', 'pl.date', 'customername', 'amount', 'p.date'];
        $filters = ['currency' => 'pf.currency_id'];

        $view = fn (string $label, ?callable $scope) => [
            'label' => $label,
            'type' => 'table',
            'columns' => $columns,
            'query' => fn () => $scope
                ? $scope($this->applyFilters($this->baseQuery(), $filters))
                : $this->applyFilters($this->baseQuery(), $filters),
            'searchable' => $searchable,
            'sortable' => $sortable,
        ];

        return [
            'default' => $view('All shipments', null),
            'unpaid' => $view('Nothing paid', fn ($q) => $q->whereNull('p.id')),
            'paid' => $view('Paid', fn ($q) => $q->whereNotNull('p.id')),
        ];
    }

    /** Amounts carry the shipment's currency; the symbol is stored as an entity. */
    protected function money($row, float $value): string
    {
        return e(ProformaInvoice::symbol($row->symbol ?? null)) . number_format($value, 2);
    }

    /**
     * The final invoice for a shipment: the proforma's prices against the
     * weights that actually shipped, plus freight per container shipped.
     *
     * Read from the containers JSON already on the row, so no extra query per
     * line; the prices are one read per shipment.
     */
    public function invoiceValue($row): float
    {
        // The row is a BplPackingList, which casts `containers` to an array;
        // a raw row would still hand over JSON, so both are accepted.
        $containers = is_array($row->containers)
            ? $row->containers
            : (json_decode((string) $row->containers, true) ?: []);

        $shipped = [];
        foreach ($containers as $container) {
            foreach ($container['goods'] ?? [] as $lot) {
                $key = (string) ($lot['description'] ?? '');
                $shipped[$key] = ($shipped[$key] ?? 0) + (float) ($lot['weight'] ?? 0);
            }
        }

        $goods = 0.0;
        foreach (ProformaInvoice::lines((int) $row->order_id) as $line) {
            $weight = $shipped[$line['fullname']] ?? 0;

            if ($line['price'] !== null && $weight > 0) {
                $goods += $line['price'] * $weight / 1000;
            }
        }

        return round($goods + count($containers) * (float) ($row->freight_price ?? 0), 2);
    }

    /* ---------------- The form ---------------- */

    protected function findRow(int $id)
    {
        return $this->baseQuery()->where('pl.id', $id)->first();
    }

    protected function resetForm(): void
    {
        $this->packing_id = null;
        $this->amount = '';
        $this->dateIso = now()->format('Y-m-d');
        $this->context = [];
    }

    protected function fillForm(int $packingId): void
    {
        $row = $this->findRow($packingId);
        abort_if(! $row, 404);

        $this->packing_id = $packingId;
        $this->amount = $row->payment_id ? (string) (float) $row->amount : '';
        $this->dateIso = $row->payment_date ? str_replace('/', '-', (string) $row->payment_date) : now()->format('Y-m-d');
        $this->context = [
            'shipment' => PackingListSupport::label($row->packing_number, (int) $row->customer_id, $row->customerlabel),
            'customer' => (string) ($row->customername ?: '—'),
            'currency' => (string) ($row->currency ?: ''),
            'symbol' => ProformaInvoice::symbol($row->symbol ?? null),
            'invoice' => $this->invoiceValue($row),
            'date' => (string) $row->date,
        ];
    }

    protected function rules(): array
    {
        return [
            'packing_id' => ['required', 'integer', 'exists:bpl.bpl_packing_list,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'dateIso' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['packing_id' => 'shipment', 'amount' => 'amount paid', 'dateIso' => 'date of payment'];
    }

    public function save(): void
    {
        $data = $this->validate();

        $row = $this->findRow((int) $data['packing_id']);

        if (! $row) {
            session()->flash('err', 'That shipment no longer exists.');
            $this->showModal = false;

            return;
        }

        $invoice = $this->invoiceValue($row);
        $amount = (float) $data['amount'];

        if ($invoice > 0 && $amount > $invoice + self::TOLERANCE) {
            $this->addError('amount', 'That is more than the invoice of '
                . number_format($invoice, 2) . ' for this shipment.');

            return;
        }

        // One row per shipment, updated in place — the legacy's own rule.
        BplInvoicePayment::updateOrCreate(
            ['packing_id' => (int) $data['packing_id']],
            ['amount' => $amount, 'date' => str_replace('-', '/', $data['dateIso'])]
        );

        $this->showModal = false;
        session()->flash('ok', 'Payment recorded against ' . $this->context['shipment'] . '.');
    }

    /* ---------------- Deleting ---------------- */

    public function deleteGuard($row): ?string
    {
        return $row->payment_id ? null : 'Nothing has been paid against this shipment.';
    }

    protected function performDelete(int $packingId): void
    {
        BplInvoicePayment::where('packing_id', $packingId)->delete();
    }

    public function deletedMessage($row): string
    {
        return 'Payment against ' . PackingListSupport::label($row->packing_number, (int) $row->customer_id, $row->customerlabel)
            . ' removed.';
    }

    /* ---------------- Row actions ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return true;
    }

    public function leadingRowActions($row): string
    {
        return '<a href="' . e(route('bpl.sales.packing-lists.invoice', $row->id)) . '"'
            . ' target="_blank" rel="noopener" class="btn btn-ghost btn-icon btn-sm" title="Final invoice">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>'
            . '<path d="M12 17V9M9.5 11h5M9.5 14.5h5"/></svg></a>';
    }
}
