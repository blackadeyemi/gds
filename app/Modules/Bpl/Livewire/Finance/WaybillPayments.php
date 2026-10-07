<?php

namespace Modules\Bpl\Livewire\Finance;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplTransporter;
use Modules\Bpl\Models\BplWaybillPayment;
use Modules\Bpl\Models\BplWaybillPaymentHistory;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Finance → Waybill Payments. Rebuild of legacy `waybillpayment.php`
 * (handlers on `Bil\Bpl\Delivery`): what a haulier is owed for each trip, and
 * every payment made against it.
 *
 * One record per waybill (unique since migration 2026_09_23_120000), holding
 * the agreed amount; the payments themselves are history rows, and what has
 * been paid is their sum.
 *
 * Changes from legacy:
 *  - **Paid, balance and status are derived, never adjusted by hand.** The
 *    legacy nudged `amount_paid` at four separate call sites and left `status`
 *    alone entirely — all sixteen rows said "Paid", twelve of them with the
 *    whole amount outstanding. See BplWaybillPayment::recalculate().
 *  - **Over-payment is refused.** The legacy check compared an undefined
 *    variable, so it never fired once.
 *  - **The agreed amount cannot drop below what has already been paid.**
 *  - **A record cannot be raised twice for the same waybill**, and only
 *    against a waybill that exists.
 *  - Deleting a payment record is refused once money has been paid against it;
 *    the legacy deleted the record and its receipts together, on sight.
 */
#[Title('BPL Waybill Payments')]
class WaybillPayments extends DataGrid
{
    /* ---------------- The record form ---------------- */

    public string $barcode = '';
    public ?int $transporter_id = null;
    public string $amount = '';

    /** First payment, offered on the way in — the legacy's "with part payment". */
    public string $firstAmount = '';
    public string $firstDate = '';
    public string $firstNote = '';

    /* ---------------- The payments modal ---------------- */

    public bool $showPayments = false;
    public ?int $paymentId = null;
    public array $paymentContext = [];

    /** The row being added or edited in the modal. */
    public ?int $editingHistoryId = null;
    public string $historyAmount = '';
    public string $historyDate = '';
    public string $historyNote = '';

    public function pageKey(): string { return 'bpl.finance.waybill-payments'; }
    public function pageLabel(): string { return 'BPL Waybill Payments'; }
    public function pageSubtitle(): string { return 'What each haulier is owed for a trip, and what has been paid against it.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.waybill-payment'; }
    public function extraView(): ?string { return 'bpl::partials.waybill-payment-history'; }
    public function defaultSort(): array { return ['id', 'desc']; }
    public function modalSize(): string { return '600px'; }

    /* ---------------- The grid ---------------- */

    protected function baseQuery()
    {
        return BplWaybillPayment::query()
            ->leftJoin('bpl_transporters as t', 't.id', '=', 'bpl_waybill_payment.transporter_id')
            ->select('bpl_waybill_payment.*')
            ->selectRaw('t.transportername, t.transportercode')
            ->selectRaw('(SELECT COUNT(*) FROM `bpl_waybill_payment_history` h'
                . ' WHERE h.`waybill_payment_id` = `bpl_waybill_payment`.`id`) as payments_count')
            ->selectRaw('(SELECT MAX(h.`payment_date`) FROM `bpl_waybill_payment_history` h'
                . ' WHERE h.`waybill_payment_id` = `bpl_waybill_payment`.`id`) as last_payment');
    }

    public function filterDefs(): array
    {
        return [
            'transporter' => [
                'label' => 'Transporter',
                'options' => BplTransporter::orderBy('transportername')->pluck('transportername', 'id')->all(),
                'width' => 230,
            ],
        ];
    }

    public function views(): array
    {
        $naira = fn (string $field) => fn ($r) => number_format((float) $r->{$field}, 2);

        $status = function ($r) {
            $class = match ($r->status) {
                BplWaybillPayment::PAID => 'badge-success',
                BplWaybillPayment::PARTIAL => 'badge-warning',
                default => 'badge-danger',
            };

            return '<span class="badge ' . $class . '">' . e((string) $r->status) . '</span>';
        };

        $columns = [
            ['Waybill', 'barcode', fn ($r) => '<span class="mono">' . e($r->barcode) . '</span>'],
            ['Transporter', 'transportername', fn ($r) => e((string) ($r->transportername ?: '—'))],
            ['Customer', 'customer_name', fn ($r) => e((string) ($r->customer_name ?: '—'))],
            ['Truck', 'vechicle_number', fn ($r) => e((string) ($r->vechicle_number ?: '—'))],
            ['Amount', 'amount', $naira('amount')],
            ['Paid', 'amount_paid', $naira('amount_paid')],
            ['Balance', 'balance', $naira('balance')],
            ['Payments', 'payments_count', fn ($r) => number_format((int) $r->payments_count)],
            ['Last Paid', 'last_payment', fn ($r) => $r->last_payment
                ? e(\Illuminate\Support\Carbon::parse($r->last_payment)->format('d/m/Y'))
                : '<span class="text-muted">—</span>'],
            ['Status', 'status', $status],
        ];

        $searchable = ['bpl_waybill_payment.barcode', 'bpl_waybill_payment.customer_name',
            'bpl_waybill_payment.vechicle_number', 't.transportername'];
        $sortable = ['barcode', 'transportername', 'customer_name', 'vechicle_number', 'amount',
            'amount_paid', 'balance', 'payments_count', 'last_payment', 'status', 'id'];
        $totals = ['amount', 'amount_paid', 'balance'];

        $view = fn (string $label, ?string $status) => [
            'label' => $label,
            'type' => 'table',
            'columns' => $columns,
            'query' => fn () => $this->applyFilters(
                $this->baseQuery()->when($status, fn ($q) => $q->where('bpl_waybill_payment.status', $status)),
                ['transporter' => 'bpl_waybill_payment.transporter_id']
            ),
            'count' => fn () => $this->applyFilters(
                BplWaybillPayment::query()->when($status, fn ($q) => $q->where('status', $status)),
                ['transporter' => 'transporter_id']
            ),
            'searchable' => $searchable,
            'sortable' => $sortable,
            'totals' => $totals,
        ];

        return [
            'default' => $view('All', null),
            'outstanding' => $view('Outstanding', BplWaybillPayment::OUTSTANDING),
            'partial' => $view('Part paid', BplWaybillPayment::PARTIAL),
            'paid' => $view('Paid', BplWaybillPayment::PAID),
        ];
    }

    /* ---------------- The record ---------------- */

    /**
     * Waybills with no payment record yet, newest first.
     *
     * The legacy offered the last 14 days; this offers the last 120, because a
     * haulier's invoice does not always arrive inside a fortnight and the
     * legacy screen simply could not record one that came late.
     */
    #[Computed]
    public function openWaybills()
    {
        return DB::connection('bpl')->table('bpl_delivery_barcode as w')
            ->leftJoin('bpl_delivery_details as d', 'd.id', '=', DB::raw(
                '(SELECT MIN(dd.`id`) FROM `bpl_delivery_details` dd WHERE dd.`delivery_barcode` = w.`delivery_barcode`)'
            ))
            ->leftJoin('bpl_delivery as f', 'f.id', '=', 'd.bpl_delivery_id')
            ->whereRaw("STR_TO_DATE(w.`date`, '%y-%m-%d') >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_waybill_payment as p')
                ->whereColumn('p.barcode', 'w.delivery_barcode'))
            ->orderByDesc('w.id')
            ->limit(300)
            ->get(['w.delivery_barcode', 'w.date', 'f.customer_name', 'f.vechile_number']);
    }

    #[Computed]
    public function transporters()
    {
        return BplTransporter::orderBy('transportername')->get(['id', 'transportername', 'transportercode']);
    }

    protected function resetForm(): void
    {
        $this->barcode = '';
        $this->transporter_id = null;
        $this->amount = '';
        $this->firstAmount = '';
        $this->firstDate = now()->format('Y-m-d');
        $this->firstNote = '';
    }

    protected function fillForm(int $id): void
    {
        $p = BplWaybillPayment::findOrFail($id);
        $this->barcode = (string) $p->barcode;
        $this->transporter_id = (int) $p->transporter_id;
        $this->amount = (string) (float) $p->amount;
        $this->firstAmount = $this->firstNote = '';
        $this->firstDate = now()->format('Y-m-d');
    }

    protected function findRow(int $id)
    {
        return $this->baseQuery()->where('bpl_waybill_payment.id', $id)->first();
    }

    protected function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:199'],
            'transporter_id' => ['required', 'integer', 'exists:bpl.bpl_transporters,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'firstAmount' => ['nullable', 'numeric', 'min:0'],
            'firstDate' => ['nullable', 'date', 'before_or_equal:today'],
            'firstNote' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'barcode' => 'waybill', 'transporter_id' => 'transporter', 'amount' => 'agreed amount',
            'firstAmount' => 'first payment', 'firstDate' => 'payment date', 'firstNote' => 'voucher number',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        $amount = (float) $data['amount'];

        $existing = $this->editingId ? BplWaybillPayment::find($this->editingId) : null;

        if ($this->editingId && ! $existing) {
            session()->flash('err', 'That payment record no longer exists.');
            $this->showModal = false;

            return;
        }

        // The waybill has to exist, and may carry only one payment record.
        if (! $existing) {
            $waybill = DB::connection('bpl')->table('bpl_delivery_barcode')
                ->where('delivery_barcode', $data['barcode'])->first();

            if (! $waybill) {
                $this->addError('barcode', 'No delivery has gone out on that waybill.');

                return;
            }

            if (BplWaybillPayment::where('barcode', $data['barcode'])->exists()) {
                $this->addError('barcode', 'That waybill already has a payment record — open it to add a payment.');

                return;
            }
        }

        // An agreed amount cannot be cut below what the haulier has been paid.
        if ($existing && $amount < (float) $existing->amount_paid) {
            $this->addError('amount', 'Already paid ' . number_format((float) $existing->amount_paid, 2)
                . ' against this waybill — the agreed amount cannot be less.');

            return;
        }

        $first = ($data['firstAmount'] ?? '') !== '' ? (float) $data['firstAmount'] : null;

        if (! $existing && $first !== null && $first > $amount) {
            $this->addError('firstAmount', 'That is more than the agreed amount.');

            return;
        }

        DB::connection('bpl')->transaction(function () use ($existing, $data, $amount, $first) {
            if ($existing) {
                $existing->forceFill([
                    'transporter_id' => (int) $data['transporter_id'],
                    'amount' => $amount,
                ])->save();
                $existing->recalculate();

                return;
            }

            $delivery = DB::connection('bpl')->table('bpl_delivery_details as d')
                ->leftJoin('bpl_delivery as f', 'f.id', '=', 'd.bpl_delivery_id')
                ->where('d.delivery_barcode', $data['barcode'])
                ->first(['f.customer_name', 'f.vechile_number']);

            $payment = BplWaybillPayment::create([
                'barcode' => $data['barcode'],
                'transporter_id' => (int) $data['transporter_id'],
                'amount' => $amount,
                'amount_paid' => 0,
                'balance' => $amount,
                'status' => BplWaybillPayment::OUTSTANDING,
                // Copied onto the record, as the legacy did, so the row still
                // reads correctly if the delivery is later corrected.
                'customer_name' => $delivery->customer_name ?? null,
                'vechicle_number' => $delivery->vechile_number ?? null,
                'created_at' => now(),
            ]);

            if ($first !== null) {
                BplWaybillPaymentHistory::create([
                    'waybill_payment_id' => $payment->id,
                    'amount' => $first,
                    'payment_date' => $data['firstDate'] ?: now()->format('Y-m-d'),
                    'payment_note' => $data['firstNote'] ?: null,
                    'created_at' => now(),
                ]);
            }

            $payment->recalculate();
        });

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Payment record updated.' : 'Payment record raised for ' . $data['barcode'] . '.');
    }

    /** Money paid cannot be un-recorded by deleting the record it belongs to. */
    public function deleteGuard($row): ?string
    {
        return (float) $row->amount_paid > 0
            ? 'Already paid ' . number_format((float) $row->amount_paid, 2) . ' — remove the payments first.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        DB::connection('bpl')->transaction(function () use ($id) {
            BplWaybillPaymentHistory::where('waybill_payment_id', $id)->delete();
            BplWaybillPayment::whereKey($id)->delete();
        });
    }

    public function deletedMessage($row): string
    {
        return 'Payment record for ' . $row->barcode . ' removed.';
    }

    /* ---------------- Payments against a record ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return true;
    }

    public function leadingRowActions($row): string
    {
        return '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
            . ' wire:click="openPayments(' . (int) $row->id . ')" title="Payments made">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg></button>';
    }

    /** NOT named `showPayments` — a method sharing a property's name is shadowed. */
    public function openPayments(int $id): void
    {
        $row = $this->findRow($id);
        if (! $row) {
            return;
        }

        $this->paymentId = $id;
        $this->paymentContext = [
            'barcode' => (string) $row->barcode,
            'transporter' => (string) ($row->transportername ?: '—'),
            'customer' => (string) ($row->customer_name ?: '—'),
            'amount' => (float) $row->amount,
        ];
        $this->resetHistoryForm();
        $this->showPayments = true;
        unset($this->payments);
    }

    public function closePayments(): void
    {
        $this->showPayments = false;
        $this->paymentId = null;
        $this->paymentContext = [];
        $this->resetHistoryForm();
    }

    /** Read live: they are the record's balance, so a stale copy would lie. */
    #[Computed]
    public function payments()
    {
        return $this->paymentId
            ? BplWaybillPaymentHistory::where('waybill_payment_id', $this->paymentId)
                ->orderByDesc('payment_date')->orderByDesc('id')->get()
            : collect();
    }

    /** What is left to pay, with the row being edited taken out of the sum. */
    public function outstanding(): float
    {
        $paid = (float) $this->payments->where('id', '!=', $this->editingHistoryId)->sum('amount');

        return round((float) ($this->paymentContext['amount'] ?? 0) - $paid, 2);
    }

    public function resetHistoryForm(): void
    {
        $this->editingHistoryId = null;
        $this->historyAmount = '';
        $this->historyDate = now()->format('Y-m-d');
        $this->historyNote = '';
        $this->resetErrorBag(['historyAmount', 'historyDate', 'historyNote']);
    }

    public function editPayment(int $id): void
    {
        $row = $this->payments->firstWhere('id', $id);
        if (! $row) {
            return;
        }

        $this->editingHistoryId = $id;
        $this->historyAmount = (string) (float) $row->amount;
        $this->historyDate = $row->payment_date?->format('Y-m-d') ?? now()->format('Y-m-d');
        $this->historyNote = (string) $row->payment_note;
    }

    public function savePayment(): void
    {
        if (! $this->paymentId || ! $this->mayDo('edit')) {
            return;
        }

        $data = $this->validate([
            'historyAmount' => ['required', 'numeric', 'min:0'],
            'historyDate' => ['required', 'date', 'before_or_equal:today'],
            'historyNote' => ['nullable', 'string', 'max:100'],
        ], [], [
            'historyAmount' => 'amount', 'historyDate' => 'payment date', 'historyNote' => 'voucher number',
        ]);

        $amount = (float) $data['historyAmount'];

        // The check the legacy meant to make: it compared an undefined variable.
        if ($amount > $this->outstanding()) {
            $this->addError('historyAmount', 'That is more than the ' . number_format($this->outstanding(), 2)
                . ' still outstanding on this waybill.');

            return;
        }

        $payment = BplWaybillPayment::find($this->paymentId);
        if (! $payment) {
            $this->closePayments();

            return;
        }

        DB::connection('bpl')->transaction(function () use ($payment, $data, $amount) {
            $row = [
                'amount' => $amount,
                'payment_date' => $data['historyDate'],
                'payment_note' => $data['historyNote'] ?: null,
            ];

            if ($this->editingHistoryId) {
                BplWaybillPaymentHistory::whereKey($this->editingHistoryId)
                    ->where('waybill_payment_id', $payment->id)->update($row);
            } else {
                BplWaybillPaymentHistory::create($row + [
                    'waybill_payment_id' => $payment->id,
                    'created_at' => now(),
                ]);
            }

            $payment->recalculate();
        });

        $wasEdit = (bool) $this->editingHistoryId;
        $this->resetHistoryForm();
        unset($this->payments);

        session()->flash('ok', $wasEdit ? 'Payment updated.' : 'Payment of ' . number_format($amount, 2) . ' recorded.');
    }

    public function deletePayment(int $id): void
    {
        if (! $this->paymentId || ! $this->mayDo('delete')) {
            return;
        }

        $payment = BplWaybillPayment::find($this->paymentId);
        if (! $payment) {
            return;
        }

        DB::connection('bpl')->transaction(function () use ($payment, $id) {
            BplWaybillPaymentHistory::whereKey($id)->where('waybill_payment_id', $payment->id)->delete();
            $payment->recalculate();
        });

        if ($this->editingHistoryId === $id) {
            $this->resetHistoryForm();
        }
        unset($this->payments);

        session()->flash('ok', 'Payment removed.');
    }
}
