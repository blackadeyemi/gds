<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\StockTransfer as TransferModel;
use Modules\Bil\Support\StockTransfers;
use Modules\Core\Models\Warehouse;

/**
 * BIL → Finished Goods → Receive Transfer. Rebuild of the legacy
 * fg_inter_received.php.
 *
 * The other half of a transfer. Bundles came off the source when the truck left;
 * they arrive here, and only then do they count as stock at the destination.
 * What has left but not arrived is IN TRANSIT — a real state, visible on this
 * screen, rather than a gap between two warehouses' figures.
 *
 * Counts default to what was sent, because that is what usually turns up, and
 * retyping every line invites transcription errors. A SHORT delivery has to be
 * entered deliberately — that is the direction worth the friction, and the
 * shortfall is recorded on the line rather than quietly absorbed.
 *
 * Approval is a separate step from receipt, as the legacy table's `approved`
 * status was: the storekeeper counts, a supervisor signs off.
 */
#[Layout('core::layouts.admin')]
#[Title('Receive Transfer')]
class StockTransferReceive extends Component
{
    /** Which destination warehouse's arrivals to show; '' = all I can see. */
    public string $filterWarehouse = '';

    public ?int $openId = null;

    /** lineId => bundles actually counted in. */
    public array $counts = [];

    public string $note = '';

    public bool $confirmingCancel = false;

    public const PAGE_KEY = 'bil.finished_goods.stock_transfer_receive';

    public function mount(): void
    {
        $pending = $this->pending;

        if ($pending->isNotEmpty()) {
            $this->openTransfer($pending->first()->id);
        }
    }

    /* ---------------- Permissions ---------------- */

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    public function canApprove(): bool
    {
        return $this->mayDo('approve');
    }

    public function canCancel(): bool
    {
        return $this->mayDo('cancel');
    }

    /* ---------------- Lists ---------------- */

    #[Computed]
    public function warehouses()
    {
        return StockTransfers::sources();
    }

    #[Computed]
    public function warehouseOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => 'All destinations']],
            $this->warehouses->map(fn ($w) => [
                'value' => (string) $w->id,
                'label' => $w->name . ' — ' . ($w->company?->name ?? ''),
            ])->all()
        );
    }

    /** Dispatched and not yet received, oldest first — a queue, like waste. */
    #[Computed]
    public function pending()
    {
        return StockTransfers::awaitingReceipt(
            $this->filterWarehouse === '' ? null : (int) $this->filterWarehouse
        );
    }

    /** Recently received, so a mistake can be found and approved or queried. */
    #[Computed]
    public function recent()
    {
        return TransferModel::with(['lines', 'fromWarehouse', 'toWarehouse'])
            ->where('status', TransferModel::RECEIVED)
            ->where('is_historic', false)
            ->when($this->filterWarehouse !== '', fn ($q) => $q->where('to_warehouse_id', (int) $this->filterWarehouse))
            ->orderByDesc('received_at')->limit(25)->get();
    }

    #[Computed]
    public function transfer(): ?TransferModel
    {
        return $this->openId
            ? TransferModel::with(['lines', 'fromWarehouse.company', 'toWarehouse.company'])->find($this->openId)
            : null;
    }

    /* ---------------- Actions ---------------- */

    public function openTransfer(int $id): void
    {
        $transfer = TransferModel::with('lines')->find($id);

        if (! $transfer) {
            return;
        }

        $this->openId = $id;
        $this->note = (string) $transfer->note;

        // Default to what was sent; short counts are typed in deliberately.
        $this->counts = $transfer->lines
            ->mapWithKeys(fn ($l) => [$l->id => $l->received_bundles ?? $l->bundles])
            ->all();

        unset($this->transfer);
    }

    public function receive(): void
    {
        $transfer = $this->transfer;

        if (! $transfer || ! $transfer->inTransit()) {
            return;
        }

        foreach ($this->counts as $lineId => $qty) {
            if ((int) $qty < 0) {
                $this->addError('counts.' . $lineId, 'A count cannot be negative.');

                return;
            }
        }

        StockTransfers::receive($transfer, $this->counts, $this->note);

        $fresh = $transfer->fresh();
        $short = $fresh->shortfall();

        session()->flash('ok', $short > 0
            ? sprintf('Received with a shortfall of %s bundle(s) — recorded on the transfer.', number_format($short))
            : 'Transfer received in full.');

        $this->openId = null;
        $this->counts = [];
        $this->note = '';
        unset($this->transfer, $this->pending, $this->recent);

        // Move straight to the next arrival rather than back to an empty screen.
        if ($this->pending->isNotEmpty()) {
            $this->openTransfer($this->pending->first()->id);
        }
    }

    public function approve(int $id): void
    {
        if (! $this->canApprove()) {
            return;
        }

        $transfer = TransferModel::find($id);

        if ($transfer) {
            StockTransfers::approve($transfer);
            session()->flash('ok', 'Transfer approved.');
            unset($this->recent);
        }
    }

    /**
     * Cancel a dispatch that never arrived, putting the bundles back on the
     * source. Only before receipt — once counted in, the way back is another
     * transfer, not an undo.
     */
    public function cancel(): void
    {
        if (! $this->canCancel()) {
            return;
        }

        $transfer = $this->transfer;

        if ($transfer && $transfer->inTransit()) {
            StockTransfers::cancel($transfer, $this->note ?: 'Cancelled at the destination');
            session()->flash('ok', 'Transfer cancelled — the bundles are back on '
                . ($transfer->fromWarehouse?->name ?? 'the source') . '.');
            $this->openId = null;
            $this->confirmingCancel = false;
            unset($this->transfer, $this->pending, $this->recent);
        }
    }

    public function updatedFilterWarehouse(): void
    {
        $this->openId = null;
        unset($this->pending, $this->recent, $this->transfer);
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.stock-transfer-receive');
    }
}
