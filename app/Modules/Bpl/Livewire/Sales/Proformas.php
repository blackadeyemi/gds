<?php

namespace Modules\Bpl\Livewire\Sales;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplAccount;
use Modules\Bpl\Models\BplPaymentTerm;
use Modules\Bpl\Models\BplProforma;
use Modules\Bpl\Models\BplProformaItem;
use Modules\Bpl\Support\ProformaInvoice;
use Modules\Bpl\Support\ProformaMilestones;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Sales → Proforma Invoices. Rebuild of legacy `bpl_proforma.php` /
 * `bpl_proforma_update.php` (handler `Bil\Bpl\ProformaInvoice`): the priced
 * version of a sales order, and the sheet the customer is sent.
 *
 * One proforma per order — `bpl_proforma.order_id` IS the primary key — so the
 * form starts from an order that has none. Prices are per tonne, one per line
 * of the order, and are what BPL Sales Orders then refuses to let anyone
 * remove or re-product.
 *
 * Changes from legacy:
 *  - **A price is required on every line, and must be above zero.** The legacy
 *    wrote whatever the form posted, and an unpriced line simply vanished from
 *    the printed sheet with no total to say so.
 *  - **The receiving account must be one that can be paid into** — an account
 *    whose beneficiary bank has been deleted is not offered.
 *  - **Deleted proformas are visible and restorable.** The legacy restored one
 *    only as a side effect of saving an edit over it, which nothing said.
 *  - The document is otherwise unchanged, including its wording and the way
 *    freight is charged per container.
 *
 * PROGRESS: the customer approval and the produced / loaded milestones live in
 * `core.actions` (see Support\ProformaMilestones) and are shown and set here.
 * gds does NOT send the legacy's notification emails, which posted the PDF to
 * a fixed address list.
 */
#[Title('BPL Proforma Invoices')]
class Proformas extends DataGrid
{
    /* ---------------- The form ---------------- */

    public ?int $order_id = null;
    public string $customer_ref = '';
    public string $freight = '';
    public string $container = '1';
    public string $freight_price = '';
    public string $terms = '';
    public string $shipment = '';
    public ?int $payment_term_id = null;
    public ?int $currency_id = null;
    public ?int $account_id = null;
    public string $nxp = '';
    public string $dateIso = '';

    /** order_item_id => price, in the order's line order. */
    public array $prices = [];

    /* ---------------- Progress ---------------- */

    public bool $showDetails = false;
    public ?int $detailsOrderId = null;

    public function pageKey(): string { return 'bpl.sales.proformas'; }
    public function pageLabel(): string { return 'BPL Proforma Invoices'; }
    public function pageSubtitle(): string { return 'The priced sales order: what the customer is quoted, and the sheet they are sent.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.proforma'; }
    public function extraView(): ?string { return 'bpl::partials.proforma-details'; }
    // Newest proforma first, by the DATE ON THE DOCUMENT rather than when the
    // row was written: a proforma is often raised days after it is dated.
    // Qualified, because bpl_sales carries a `date` of its own.
    public function defaultSort(): array { return ['bpl_proforma.date', 'desc']; }
    public function modalSize(): string { return '900px'; }

    /* ---------------- The grid ---------------- */

    protected function baseQuery()
    {
        return BplProforma::query()
            ->join('bpl_sales as s', 's.id', '=', 'bpl_proforma.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'bpl_proforma.payment_term_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'bpl_proforma.currency_id')
            ->whereNull('s.deleted_at')
            ->select('bpl_proforma.*')
            ->selectRaw("COALESCE(s.orderno, REPLACE(CONCAT('BPL/', co.iso, '/', c.customerlabel, '/', s.ref), ' ', '')) as number")
            ->selectRaw('c.customername, pt.payment_terms, cur.code as currency, cur.symbol')
            // Goods + freight, the way the sheet adds them up: price is per
            // tonne and the line weight is in kilos.
            ->selectRaw('(SELECT ROUND(COALESCE(SUM(pi.`price` * i.`weight` / 1000), 0), 2)'
                . ' FROM `bpl_proforma_items` pi JOIN `bpl_sales_items` i ON i.`id` = pi.`order_item_id`'
                . ' WHERE i.`order_id` = `bpl_proforma`.`order_id`) as goods_value')
            ->selectRaw('ROUND(`bpl_proforma`.`container` * `bpl_proforma`.`freight_price`, 2) as freight_charge')
            ->selectRaw('(SELECT COUNT(*) FROM `bpl_sales_items` i WHERE i.`order_id` = `bpl_proforma`.`order_id`) as line_count')
            ->selectRaw('(SELECT COUNT(*) FROM `bpl_proforma_items` pi JOIN `bpl_sales_items` i ON i.`id` = pi.`order_item_id`'
                . ' WHERE i.`order_id` = `bpl_proforma`.`order_id`) as priced_count');
    }

    public function filterDefs(): array
    {
        return [
            'currency' => [
                'label' => 'Currency',
                'options' => DB::connection('bpl')->table('currencies')->orderBy('code')->pluck('code', 'id')->all(),
                'width' => 150,
            ],
            'shipment' => [
                'label' => 'Shipment',
                'options' => array_combine(BplProforma::SHIPMENTS, BplProforma::SHIPMENTS),
                'width' => 150,
            ],
        ];
    }

    public function views(): array
    {
        // The symbol is stored as an HTML entity; decoded, or the cell shows
        // "&#36;900.00" — see ProformaInvoice::symbol().
        $money = fn (string $field) => fn ($r) => e(ProformaInvoice::symbol($r->symbol))
            . number_format((float) $r->{$field}, 2);

        $columns = [
            ['Number', 'number', fn ($r) => '<span class="mono">' . e((string) $r->number) . '</span>'
                . ($r->customer_ref ? '<div class="text-sm text-muted">' . e($r->customer_ref) . '</div>' : '')],
            ['Date', 'bpl_proforma.date', fn ($r) => e((string) $r->date)],
            ['Customer', 'customername', fn ($r) => e((string) ($r->customername ?: '—'))],
            // Shipment, terms of delivery, payment term, NXP and the currency
            // code are all a click away in Details — what stays here is what a
            // reader scans a page by. The amounts carry the currency's symbol,
            // so dropping the code costs nothing.
            ['Goods', 'goods_value', $money('goods_value')],
            ['Freight', 'freight_charge', $money('freight_charge')],
            ['Lines', 'priced_count', function ($r) {
                $all = (int) $r->line_count;
                $priced = (int) $r->priced_count;

                return $priced >= $all
                    ? number_format($all)
                    : '<span class="badge badge-warning" title="' . $priced . ' of ' . $all . ' priced">'
                        . $priced . ' / ' . $all . '</span>';
            }],
            ['Progress', 'progress', function ($r) {
                $stages = $this->milestones[(int) $r->order_id] ?? [];

                $dots = '';
                foreach ([ProformaMilestones::APPROVED => 'A', ProformaMilestones::PRODUCED => 'P',
                    ProformaMilestones::LOADED => 'L'] as $stage => $letter) {
                    $done = isset($stages[$stage]);
                    $title = ProformaMilestones::label($stage) . ($done ? ' — ' . $stages[$stage]['date'] . ', ' . $stages[$stage]['user'] : ' — not yet');

                    $dots .= '<span class="badge ' . ($done ? 'badge-success' : 'badge-muted') . '"'
                        . ' style="margin-right:.2rem;" title="' . e($title) . '">' . $letter . '</span>';
                }

                return $dots;
            }],
        ];

        $searchable = ['s.orderno', 'c.customername', 'bpl_proforma.nxp', 'bpl_proforma.customer_ref'];
        $sortable = ['number', 'bpl_proforma.date', 'customername', 'goods_value', 'freight_charge',
            'priced_count', 'bpl_proforma.created_at'];

        $filters = ['currency' => 'bpl_proforma.currency_id', 'shipment' => 'bpl_proforma.shipment'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->applyFilters($this->baseQuery(), $filters),
                'searchable' => $searchable,
                'sortable' => $sortable,
                // Only a displayed column can carry a footer total.
                'totals' => ['goods_value', 'freight_charge'],
            ],
            'unpriced' => [
                'label' => 'Lines unpriced',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->applyFilters($this->baseQuery(), $filters)
                    ->whereRaw('(SELECT COUNT(*) FROM `bpl_proforma_items` pi JOIN `bpl_sales_items` i ON i.`id` = pi.`order_item_id`'
                        . ' WHERE i.`order_id` = `bpl_proforma`.`order_id`)'
                        . ' < (SELECT COUNT(*) FROM `bpl_sales_items` i WHERE i.`order_id` = `bpl_proforma`.`order_id`)'),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            'deleted' => [
                'label' => 'Deleted',
                'type' => 'table',
                'columns' => array_merge($columns, [
                    ['Deleted', 'deleted_at', fn ($r) => e(\Illuminate\Support\Carbon::parse($r->deleted_at)->format('d/m/Y'))],
                ]),
                'query' => fn () => $this->applyFilters($this->baseQuery()->onlyTrashed(), $filters),
                'searchable' => $searchable,
                'sortable' => array_merge($sortable, ['deleted_at']),
            ],
        ];
    }

    /**
     * The milestones of every proforma on screen, in one read.
     *
     * Filled by the grid's own row loop through views(); asking per row would
     * be three queries a line.
     */
    #[Computed]
    public function milestones(): array
    {
        return ProformaMilestones::forOrders($this->visibleOrderIds);
    }

    /** The orders on the page being rendered — filled by paginateView(). */
    public array $visibleOrderIds = [];

    /**
     * Page the view, then note which orders came back.
     *
     * The Progress cell needs each row's milestones, and asking per row would
     * be three queries a line; this lets milestones() read them all in one.
     */
    protected function paginateView(array $view)
    {
        $rows = parent::paginateView($view);

        $this->visibleOrderIds = collect($rows->items())->pluck('order_id')->map(fn ($id) => (int) $id)->all();
        unset($this->milestones);

        return $rows;
    }

    /* ---------------- Row actions ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return true;
    }

    public function leadingRowActions($row): string
    {
        if ($this->view === 'deleted') {
            return $this->mayDo('edit')
                ? '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
                    . ' wire:click="restore(' . (int) $row->order_id . ')" title="Restore this proforma">'
                    . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
                    . ' stroke-linecap="round" stroke-linejoin="round">'
                    . '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></button>'
                : '';
        }

        $details = '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
            . ' wire:click="openDetails(' . (int) $row->order_id . ')" title="Details, prices and progress">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            . '</svg></button>';

        return $details . '<a href="' . e(route('bpl.sales.proformas.print', $row->order_id)) . '"'
            . ' target="_blank" rel="noopener" class="btn btn-ghost btn-icon btn-sm" title="Print proforma">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>'
            . '<path d="M6 14h12v8H6z"/></svg></a>';
    }

    public function restore(int $orderId): void
    {
        if (! $this->mayDo('edit')) {
            return;
        }

        $proforma = BplProforma::onlyTrashed()->find($orderId);
        if ($proforma) {
            $proforma->restore();
            session()->flash('ok', 'Proforma restored.');
        }
    }

    /* ---------------- Details ---------------- */

    /**
     * Everything the grid no longer shows.
     *
     * The list carries what a reader scans by — number, date, customer, value,
     * lines and progress — and the rest (terms, shipment, payment term, NXP,
     * the freight split, the receiving account and the priced lines) lives
     * here, one click away on the row that matters.
     *
     * NOT named `showDetails` — a method sharing a property's name is shadowed.
     */
    public function openDetails(int $orderId): void
    {
        if (! $this->findRow($orderId)) {
            return;
        }

        $this->detailsOrderId = $orderId;
        $this->showDetails = true;
        unset($this->details, $this->progress, $this->milestones);
    }

    public function closeDetails(): void
    {
        $this->showDetails = false;
        $this->detailsOrderId = null;
        unset($this->details, $this->progress);
    }

    /** The row behind the modal, read live so it cannot show a stale price. */
    #[Computed]
    public function details()
    {
        return $this->detailsOrderId ? $this->findRow($this->detailsOrderId) : null;
    }

    /** The proforma's priced lines, for the details modal. */
    #[Computed]
    public function detailLines(): array
    {
        return $this->detailsOrderId ? ProformaInvoice::lines($this->detailsOrderId) : [];
    }

    /** The receiving account as the sheet prints it. */
    #[Computed]
    public function detailBank(): ?array
    {
        $account = (int) ($this->details->account_id ?? 0);

        return $account ? ProformaInvoice::bankBlock($account) : null;
    }

    /** Read live: it is the record of what happened, so a cached copy could lie. */
    #[Computed]
    public function progress(): array
    {
        return $this->detailsOrderId ? ProformaMilestones::forOrder($this->detailsOrderId) : [];
    }

    public function markStage(string $stage): void
    {
        if (! $this->detailsOrderId || ! $this->mayDo('edit') || ! ProformaMilestones::isStage($stage)) {
            return;
        }

        if (ProformaMilestones::mark($stage, $this->detailsOrderId, (string) (auth()->user()->username ?? ''))) {
            session()->flash('ok', ProformaMilestones::label($stage) . ' recorded.');
        }

        unset($this->progress, $this->milestones);
    }

    public function unmarkStage(string $stage): void
    {
        if (! $this->detailsOrderId || ! $this->mayDo('edit') || ! ProformaMilestones::isStage($stage)) {
            return;
        }

        // An approval is what lets the export form be raised; once the NXP
        // number is on the proforma, taking the approval back would contradict
        // a document that already exists. The legacy hid the button for this.
        if ($stage === ProformaMilestones::APPROVED && ProformaMilestones::hasNxp($this->detailsOrderId)) {
            session()->flash('err', 'The NXP number has already been issued against this approval — it cannot be taken back.');

            return;
        }

        if (ProformaMilestones::unmark($stage, $this->detailsOrderId)) {
            session()->flash('ok', ProformaMilestones::label($stage) . ' taken back.');
        }

        unset($this->progress, $this->milestones);
    }

    /* ---------------- Options ---------------- */

    /** Orders with no live proforma, plus the one being edited. */
    #[Computed]
    public function openOrders()
    {
        $orders = ProformaInvoice::openOrders();

        if ($this->editingId) {
            $current = DB::connection('bpl')->table('bpl_sales as s')
                ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
                ->where('s.id', $this->editingId)
                ->first(['s.id', 's.orderno', 's.ref', 's.date', 'c.customername']);

            if ($current) {
                $orders = $orders->prepend($current)->unique('id')->values();
            }
        }

        return $orders;
    }

    #[Computed]
    public function paymentTerms()
    {
        return BplPaymentTerm::orderBy('payment_terms')->get(['id', 'payment_terms', 'days']);
    }

    #[Computed]
    public function currencies()
    {
        return DB::connection('bpl')->table('currencies')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * Receiving accounts that can actually be paid into, in the currency the
     * proforma is raised in.
     *
     * An account is held in one currency (`bpl_accounts.currency_id`), so
     * quoting a customer in EUR and pointing them at a dollar account is a
     * mistake — one the legacy screen offered freely, and 44 of the 418 live
     * proformas took it. Accounts with NO currency set stay on the list: they
     * are not declared either way, and two live proformas use one.
     *
     * The account already on the proforma is always included, whatever its
     * currency and even if it has since been deleted, so opening an old
     * proforma shows what it actually says.
     */
    #[Computed]
    public function accounts()
    {
        $base = fn () => BplAccount::query()
            ->join('bpl_banks as b', 'b.id', '=', 'bpl_accounts.beneficiary')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'bpl_accounts.currency_id')
            ->orderBy('b.name')
            ->select(['bpl_accounts.id', 'bpl_accounts.account', 'bpl_accounts.currency_id',
                'b.name as bank', 'b.number', 'cur.code as currency']);

        $accounts = $base()
            ->when($this->currency_id, fn ($q) => $q->where(fn ($w) => $w
                ->where('bpl_accounts.currency_id', $this->currency_id)
                ->orWhereNull('bpl_accounts.currency_id')))
            ->get();

        if ($this->account_id && ! $accounts->contains('id', $this->account_id)) {
            $current = $base()->withTrashed()->where('bpl_accounts.id', $this->account_id)->first();

            if ($current) {
                $accounts = $accounts->prepend($current);
            }
        }

        return $accounts->values();
    }

    /**
     * A new currency means a new set of accounts — and the one already chosen
     * may no longer be payable in it, so it is dropped rather than left
     * looking selected in a list it is not on.
     */
    public function updatedCurrencyId(): void
    {
        unset($this->accounts);

        if (! $this->account_id || ! $this->currency_id) {
            return;
        }

        $chosen = BplAccount::withTrashed()->find($this->account_id);

        if ($chosen && $chosen->currency_id !== null && (int) $chosen->currency_id !== (int) $this->currency_id) {
            $this->account_id = null;
        }
    }

    /** The order's lines, with whatever price each already carries. */
    #[Computed]
    public function lines(): array
    {
        return $this->order_id ? ProformaInvoice::lines($this->order_id) : [];
    }

    /** Goods value as typed, for the form's running total. */
    public function goodsValue(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $price = (float) ($this->prices[$line['order_item_id']] ?? 0);
            $total += $price * $line['weight'] / 1000;
        }

        return round($total, 2);
    }

    public function freightCharge(): float
    {
        return round((int) $this->container * (float) $this->freight_price, 2);
    }

    /** Picking a different order means different lines, so the prices start again. */
    public function updatedOrderId(): void
    {
        unset($this->lines);
        $this->prices = [];
        $this->seedPrices();
    }

    protected function seedPrices(): void
    {
        foreach ($this->lines as $line) {
            $this->prices[$line['order_item_id']] = $line['price'] !== null ? (string) $line['price'] : '';
        }
    }

    /* ---------------- Form ---------------- */

    protected function resetForm(): void
    {
        $this->order_id = null;
        $this->customer_ref = '';
        $this->freight = '';
        $this->container = '1';
        $this->freight_price = '';
        $this->terms = '';
        $this->shipment = '';
        $this->payment_term_id = null;
        $this->currency_id = null;
        $this->account_id = null;
        $this->nxp = '';
        $this->dateIso = now()->format('Y-m-d');
        $this->prices = [];
        unset($this->lines);
    }

    protected function fillForm(int $orderId): void
    {
        $p = BplProforma::withTrashed()->findOrFail($orderId);

        $this->order_id = (int) $p->order_id;
        $this->customer_ref = (string) $p->customer_ref;
        $this->freight = (string) $p->freight;
        $this->container = (string) (int) $p->container;
        $this->freight_price = (string) (float) $p->freight_price;
        $this->terms = (string) $p->terms;
        $this->shipment = (string) $p->shipment;
        $this->payment_term_id = (int) $p->payment_term_id;
        $this->currency_id = (int) $p->currency_id;
        $this->account_id = (int) $p->account_id;
        $this->nxp = (string) $p->nxp;
        $this->dateIso = $p->date ? str_replace('/', '-', $p->date) : now()->format('Y-m-d');

        unset($this->lines);
        $this->prices = [];
        $this->seedPrices();
    }

    protected function findRow(int $orderId)
    {
        return $this->baseQuery()->withTrashed()->where('bpl_proforma.order_id', $orderId)->first();
    }

    protected function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:bpl.bpl_sales,id'],
            'customer_ref' => ['nullable', 'string', 'max:50'],
            'freight' => ['required', 'string', 'max:100'],
            'container' => ['required', 'integer', 'min:1', 'max:99'],
            'freight_price' => ['required', 'numeric', 'min:0'],
            'terms' => ['required', 'in:' . implode(',', BplProforma::TERMS)],
            'shipment' => ['required', 'in:' . implode(',', BplProforma::SHIPMENTS)],
            'payment_term_id' => ['required', 'integer', 'exists:bpl.bpl_payment_terms,id'],
            'currency_id' => ['required', 'integer', 'exists:bpl.currencies,id'],
            'account_id' => ['required', 'integer', 'exists:bpl.bpl_accounts,id'],
            'nxp' => ['nullable', 'string', 'max:100'],
            'dateIso' => ['required', 'date'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'order_id' => 'sales order', 'customer_ref' => 'customer reference',
            'freight_price' => 'freight price', 'payment_term_id' => 'payment term',
            'currency_id' => 'currency', 'account_id' => 'receiving account', 'dateIso' => 'date',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $orderId = (int) $data['order_id'];

        // One proforma per order: the order_id IS the primary key.
        if (! $this->editingId) {
            $existing = ProformaInvoice::existingFor($orderId);

            if ($existing && ! $existing->trashed()) {
                $this->addError('order_id', 'That order already has a proforma — open it instead.');

                return;
            }
        } elseif ($orderId !== (int) $this->editingId) {
            $this->addError('order_id', 'A proforma belongs to the order it was raised for.');

            return;
        }

        $lines = ProformaInvoice::lines($orderId);

        if ($lines === []) {
            $this->addError('order_id', 'That order has no products on it yet.');

            return;
        }

        // Every line is priced, and above zero: an unpriced line silently
        // dropped off the legacy's printed sheet.
        $prices = [];
        foreach ($lines as $i => $line) {
            $raw = $this->prices[$line['order_item_id']] ?? '';

            if ($raw === '' || ! is_numeric($raw) || (float) $raw <= 0) {
                $this->addError('prices.' . $line['order_item_id'],
                    'Line ' . ($i + 1) . ' needs a price above zero.');

                return;
            }

            $prices[(int) $line['order_item_id']] = round((float) $raw, 2);
        }

        $header = [
            'customer_ref' => $data['customer_ref'] ?: null,
            'freight' => $data['freight'],
            'container' => (int) $data['container'],
            'freight_price' => (float) $data['freight_price'],
            'terms' => $data['terms'],
            'shipment' => $data['shipment'],
            'payment_term_id' => (int) $data['payment_term_id'],
            'currency_id' => (int) $data['currency_id'],
            'account_id' => (int) $data['account_id'],
            'nxp' => $data['nxp'] ?: null,
            'date' => str_replace('-', '/', $data['dateIso']),
        ];

        DB::connection('bpl')->transaction(function () use ($orderId, $header, $prices) {
            $proforma = BplProforma::withTrashed()->find($orderId);

            if ($proforma) {
                // Saving over a deleted proforma brings it back, as legacy did.
                $proforma->forceFill($header + ['updated_at' => now(), 'deleted_at' => null])->save();
            } else {
                BplProforma::create($header + ['order_id' => $orderId, 'created_at' => now()]);
            }

            foreach ($prices as $orderItemId => $price) {
                $item = BplProformaItem::where('order_item_id', $orderItemId)->first();

                $item
                    ? $item->update(['price' => $price])
                    : BplProformaItem::create(['order_item_id' => $orderItemId, 'price' => $price]);
            }
        });

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Proforma updated.' : 'Proforma raised.');
    }

    /* ---------------- Deleting ---------------- */

    public function deleteGuard($row): ?string
    {
        if ($row->deleted_at) {
            return 'Already deleted.';
        }

        $packing = DB::connection('bpl')->table('bpl_packing_list')->where('order_id', $row->order_id)->exists();

        return $packing
            ? 'A packing list has been raised against this order — the proforma stays.'
            : null;
    }

    protected function performDelete(int $orderId): void
    {
        BplProforma::whereKey($orderId)->first()?->delete();
    }

    public function deletedMessage($row): string
    {
        return 'Proforma ' . $row->number . ' deleted — it can be restored from the Deleted view.';
    }
}
