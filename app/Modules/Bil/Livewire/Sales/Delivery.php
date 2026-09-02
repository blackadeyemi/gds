<?php

namespace Modules\Bil\Livewire\Sales;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Support\SalesDeliveries;
use Modules\Bil\Support\SalesLoadings;
use Modules\Core\Support\GateAccess;

/**
 * BIL → Sales → Delivery. Rebuild of sales_delivery.php and
 * sales_delivery_modification.php as one screen.
 *
 * SAME SHAPE AS LOADING, BECAUSE IT IS THE SAME OBJECT. A queue on the left, a
 * load on the right, tabs scoped to it. What differs is that nothing here is
 * composed: the truck was filled at loading, and this screen only CONFIRMS that
 * it went. There is no "New Delivery", no quantity to type and no line to edit
 * — a delivery either exists or it does not.
 *
 * So the page is still LOAD-CENTRIC, and delivery is a state the load is in:
 *
 *   Awaiting delivery   the load is open      → Confirm delivery
 *   Delivered           the load is closed    → the note, and Undo
 *
 * The second legacy page, Modification, is only ever "delete this delivery and
 * put the load back". It is not a peer screen — it is the undo of the button on
 * this one — so it lives next to what it undoes rather than behind a barcode
 * picker on a page of its own.
 *
 * Both are gated. Confirming needs the `confirm` ability and undoing needs
 * `delete`, and a delivery whose waybill has been raised cannot be undone by
 * anyone, exactly as the legacy refused it.
 */
#[Layout('core::layouts.admin')]
#[Title('Delivery')]
class Delivery extends Component
{
    /** The LOAD in focus, by its barcode. Delivery is a state of a load. */
    public string $barcode = '';

    /**
     * Which delivery of that load is being looked at.
     *
     * Normally there is one and this stays null. A truck that goes out, comes
     * back and is loaded again under the same load number has two, and picking
     * one off the list has to say which.
     */
    public ?int $deliveryId = null;

    /** Tab within the selected load. */
    public string $tab = 'delivery';

    /* ---- queue filters ---- */
    public string $search = '';
    public string $dateIso = '';
    public bool $showDelivered = false;

    /* ---- the Print Outs modal ---- */
    public bool $printOpen = false;
    public string $printDate = '';
    public string $printSearch = '';
    /** Delivery barcodes ticked for printing. */
    public array $printPicked = [];
    /** Delivery barcode whose details are expanded inline ("View More"). */
    public string $printExpanded = '';

    /* ---- two-step confirmations ---- */
    public bool $confirmingUndo = false;

    public const PAGE_KEY = 'bil.sales.delivery';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    /* ---------------- Permissions ---------------- */

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    public function canConfirm(): bool
    {
        return $this->mayDo('confirm');
    }

    public function canUndo(): bool
    {
        return $this->mayDo('delete');
    }

    /* ---------------- Cagerooms the user may work ---------------- */

    /**
     * The same gate grants that decide which bays an operator may load from
     * decide which loads they may send out. Cagerooms are warehouse out-gates.
     */
    #[Computed]
    public function cagerooms(): array
    {
        $gates = GateAccess::warehouseGates(auth()->user(), 'finished-goods', 'out');
        $codes = collect($gates)->pluck('legacy_name')->filter()->all();

        $all = SalesLoadings::cagerooms();

        return $codes === [] ? $all : array_intersect_key($all, array_flip($codes));
    }

    protected function allowedCageroomCodes(): ?array
    {
        $mine = array_keys($this->cagerooms);
        $all = array_keys(SalesLoadings::cagerooms());

        return count($mine) === count($all) ? null : $mine;
    }

    /* ---------------- The queue ---------------- */

    /** Loads still on the floor — the legacy's "barcodes not delivered". */
    #[Computed]
    public function pendingLoads(): array
    {
        return SalesDeliveries::pendingLoads($this->search ?: null, $this->allowedCageroomCodes());
    }

    /** Deliveries already confirmed on a date — how one is reached to undo it. */
    #[Computed]
    public function deliveredLoads(): array
    {
        if (! $this->showDelivered || $this->dateIso === '') {
            return [];
        }

        return SalesDeliveries::deliveriesOn(str_replace('-', '/', $this->dateIso));
    }

    /* ---------------- The selected load ---------------- */

    #[Computed]
    public function load(): ?object
    {
        if ($this->barcode === '') {
            return null;
        }

        foreach ($this->pendingLoads as $l) {
            if ($l->barcode === $this->barcode) {
                return $l;
            }
        }

        return SalesLoadings::load($this->barcode);
    }

    /** The delivery of the selected load, or null while it is still awaiting one. */
    #[Computed]
    public function delivery(): ?object
    {
        $load = $this->load;

        if (! $load) {
            return null;
        }

        // A particular delivery was picked — off the list, or out of the Print
        // Outs modal, which is not the same list. Fetched by id rather than
        // found in whichever list happens to be on screen, because a load with
        // two deliveries would otherwise fall through to the wrong one.
        if ($this->deliveryId !== null) {
            foreach ($this->deliveredLoads as $d) {
                if ((int) $d->id === $this->deliveryId) {
                    return $d;
                }
            }

            return SalesDeliveries::find($this->deliveryId);
        }

        return SalesDeliveries::latestForLoad((int) $load->loadnumber, (string) $load->dateofloading);
    }

    /**
     * The load's lines, as they will appear on the note.
     *
     * Before delivery they are read live off the load, so what is about to be
     * confirmed is what is on the truck now. Afterwards they are read through
     * the delivery, which is what attributes them to the right sheet when a
     * load number carries two.
     */
    #[Computed]
    public function lines(): array
    {
        $delivery = $this->delivery;

        if ($delivery) {
            return SalesDeliveries::lines($delivery);
        }

        return $this->barcode === '' ? [] : SalesLoadings::lines($this->barcode);
    }

    /** Open = still awaiting delivery. */
    public function isPending(): bool
    {
        $load = $this->load;

        return $load !== null && ($load->status === null || trim((string) $load->status) === '');
    }

    public function totals(): array
    {
        $lines = $this->lines;

        return [
            'lines' => count($lines),
            'quantity' => array_sum(array_map(
                fn ($l) => (int) ($l->quantityloaded ?? $l->loaded_net ?? 0), $lines
            )),
        ];
    }

    /* ---------------- Navigation ---------------- */

    public function openLoad(string $barcode, ?int $deliveryId = null): void
    {
        $this->barcode = $barcode;
        $this->deliveryId = $deliveryId;
        $this->tab = 'delivery';
        $this->confirmingUndo = false;
        unset($this->load, $this->delivery, $this->lines);
    }

    /** Reached from the delivered list, which knows which delivery it means. */
    public function openDelivery(string $deliveryBarcode): void
    {
        $delivery = SalesDeliveries::delivery($deliveryBarcode);

        if (! $delivery) {
            return;
        }

        $this->openLoad(SalesDeliveries::loadBarcodeFor($delivery), (int) $delivery->id);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['delivery', 'print'], true)) {
            $this->tab = $tab;
            $this->confirmingUndo = false;
        }
    }

    public function updatedSearch(): void
    {
        unset($this->pendingLoads);
    }

    public function updatedDateIso(): void
    {
        unset($this->deliveredLoads);
    }

    public function updatedShowDelivered(): void
    {
        unset($this->deliveredLoads);
    }

    /* ---------------- Confirming ---------------- */

    public function confirmDelivery(): void
    {
        if (! $this->canConfirm() || $this->barcode === '') {
            return;
        }

        $result = SalesDeliveries::confirm($this->barcode);

        unset($this->load, $this->delivery, $this->lines, $this->pendingLoads, $this->deliveredLoads);

        if (! $result['ok']) {
            session()->flash('err', $result['message']);

            return;
        }

        $this->deliveryId = null;
        session()->flash('ok', $result['message']);
    }

    /* ---------------- Undoing ---------------- */

    public function undoDelivery(): void
    {
        $delivery = $this->delivery;

        if (! $this->canUndo() || ! $delivery) {
            return;
        }

        $result = SalesDeliveries::undo((int) $delivery->id);

        $this->confirmingUndo = false;
        $this->deliveryId = null;
        unset($this->load, $this->delivery, $this->lines, $this->pendingLoads, $this->deliveredLoads);

        session()->flash($result['ok'] ? 'ok' : 'err', $result['message']);
    }

    /* ---------------- Print Outs ---------------- */

    /**
     * The day's deliveries, as the legacy "Delivery Print Out" tab listed them:
     * delivery number, barcode, customer and a way into the detail. Everything
     * on the date, newest number first — a delivery is reprinted long after it
     * was made.
     */
    #[Computed]
    public function printList(): array
    {
        if (! $this->printOpen || $this->printDate === '') {
            return [];
        }

        $rows = SalesDeliveries::deliveriesOn(str_replace('-', '/', $this->printDate));

        if ($this->printSearch === '') {
            return $rows;
        }

        $needle = mb_strtolower($this->printSearch);

        return array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
            $r->barcode . ' ' . $r->deliverynumber . ' ' . ($r->customername ?? '')
            . ' ' . ($r->loadbarcode ?? '') . ' ' . ($r->transportername ?? '')
            . ' ' . ($r->trucknumber ?? '')
        ), $needle)));
    }

    public function openPrintOuts(): void
    {
        $this->printOpen = true;
        $this->printDate = $this->printDate ?: ($this->dateIso ?: now()->format('Y-m-d'));
        $this->printPicked = [];
        $this->printExpanded = '';
        $this->printSearch = '';
        unset($this->printList);
    }

    public function closePrintOuts(): void
    {
        $this->printOpen = false;
        $this->printExpanded = '';
        unset($this->printList);
    }

    public function updatedPrintDate(): void
    {
        // A tick on a delivery from another day would silently print with it.
        $this->printPicked = [];
        $this->printExpanded = '';
        unset($this->printList);
    }

    public function updatedPrintSearch(): void
    {
        unset($this->printList);
    }

    public function togglePrintDetails(string $barcode): void
    {
        $this->printExpanded = $this->printExpanded === $barcode ? '' : $barcode;
    }

    public function togglePrintAll(): void
    {
        $listed = array_map(fn ($r) => $r->barcode, $this->printList);

        $this->printPicked = count($this->printPicked) === count($listed) ? [] : $listed;
    }

    public function allPrintPicked(): bool
    {
        $listed = $this->printList;

        return $listed !== [] && count($this->printPicked) === count($listed);
    }

    public function printUrl(): string
    {
        return route('bil.sales.delivery.print', ['deliveries' => implode(',', $this->printPicked)]);
    }

    public function printUrlFor(string $barcode): string
    {
        return route('bil.sales.delivery.print', ['deliveries' => $barcode]);
    }

    public function render()
    {
        return view('bil::livewire.sales.delivery');
    }
}
