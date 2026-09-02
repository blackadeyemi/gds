<?php

namespace Modules\Bil\Livewire\Sales;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Support\SalesDeliveries;
use Modules\Bil\Support\SalesWaybills;

/**
 * BIL → Sales → Waybill. Rebuild of sales_waybill.php.
 *
 * The last step of the chain and the thinnest: a delivery already says what
 * went and to whom, and the waybill adds a RECEIPT NUMBER and a TRANSPORT COST.
 * Two fields, one button.
 *
 * Same shape as Loading and Delivery — queue left, selected thing right, tabs,
 * Print Outs — with one difference forced by the data. Those queues are
 * "everything still open"; the equivalent here would be every delivery without
 * a waybill, which is 74,692 of them, because most deliveries never get one:
 * a customer collecting in their own truck has no haulier to pay. An
 * unwaybilled delivery is a normal end state, not work outstanding. So the
 * queue is scoped to a DATE, as the legacy's was, and the screen opens on the
 * most recent date that still has one to raise rather than on today — which
 * would be blank on any day the office is catching up.
 */
#[Layout('core::layouts.admin')]
#[Title('Waybill')]
class Waybill extends Component
{
    /** The date being worked, as ISO for the date field. */
    public string $dateIso = '';

    /** The DELIVERY in focus, by its barcode. A waybill is a state of one. */
    public string $barcode = '';

    public string $tab = 'waybill';

    /* ---- queue ---- */
    public string $search = '';
    /** Hide deliveries that already have a waybill. */
    public bool $awaitingOnly = true;
    public int $shown = SalesWaybills::QUEUE_LIMIT;

    /* ---- the form ---- */
    public string $receiptnumber = '';
    public string $transportcost = '';

    /* ---- two-step confirmation ---- */
    public bool $confirmingRemove = false;

    /* ---- Print Outs modal ---- */
    public bool $printOpen = false;
    public string $printDate = '';
    public string $printSearch = '';
    public array $printPicked = [];
    public string $printExpanded = '';

    public const PAGE_KEY = 'bil.sales.waybill';

    public function mount(): void
    {
        // Open where the work is, not on a date that may well be empty.
        $this->dateIso = str_replace('/', '-',
            SalesWaybills::latestDateAwaiting() ?? now()->format('Y/m/d'));
    }

    /* ---------------- Permissions ---------------- */

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    public function canCreate(): bool
    {
        return $this->mayDo('create');
    }

    public function canModify(): bool
    {
        return $this->mayDo('modify');
    }

    /**
     * Removing a waybill is its own ability, not part of modify.
     *
     * It is the only thing that re-opens a delivery for undo — SalesDeliveries
     * refuses while a waybill stands — so it reaches back a step in the chain
     * and deserves to be granted separately.
     */
    public function canDelete(): bool
    {
        return $this->mayDo('delete');
    }

    protected function dateSlash(): string
    {
        return $this->dateIso === '' ? now()->format('Y/m/d') : str_replace('-', '/', $this->dateIso);
    }

    /* ---------------- The queue ---------------- */

    /**
     * The day, fetched ONCE. The list, the counts above it and the selected
     * delivery all come out of this — an earlier cut read the date three times
     * for those three things.
     */
    #[Computed]
    public function day(): array
    {
        return SalesWaybills::dayView($this->dateSlash());
    }

    #[Computed]
    public function page(): array
    {
        $rows = SalesWaybills::filter($this->day, $this->search ?: null, $this->awaitingOnly);

        return ['rows' => array_slice($rows, 0, $this->shown), 'more' => count($rows) > $this->shown];
    }

    #[Computed]
    public function deliveries(): array
    {
        return $this->page['rows'];
    }

    public function hasMore(): bool
    {
        return $this->page['more'];
    }

    #[Computed]
    public function counts(): array
    {
        return SalesWaybills::countsFrom($this->day);
    }

    public function showMore(): void
    {
        $this->shown += SalesWaybills::QUEUE_LIMIT;
        unset($this->day, $this->page, $this->deliveries);
    }

    /* ---------------- The selected delivery ---------------- */

    #[Computed]
    public function delivery(): ?object
    {
        if ($this->barcode === '') {
            return null;
        }

        // From the day already in hand — the queue may be filtered or paged
        // past it, so the whole day is searched rather than the visible page.
        foreach ($this->day as $d) {
            if ($d->barcode === $this->barcode) {
                return $d;
            }
        }

        return SalesDeliveries::delivery($this->barcode);
    }

    /** The waybill on that delivery, or null while it still wants one. */
    #[Computed]
    public function waybill(): ?object
    {
        $delivery = $this->delivery;

        if (! $delivery) {
            return null;
        }

        return SalesWaybills::forDelivery(
            (int) $delivery->deliverynumber, (string) $delivery->dateofdelivery
        );
    }

    /** What the waybill covers — the load's products, as the sheet prints them. */
    #[Computed]
    public function lines(): array
    {
        $delivery = $this->delivery;

        if (! $delivery) {
            return [];
        }

        return SalesDeliveries::lines($delivery);
    }

    public function totals(): array
    {
        $lines = $this->lines;

        return [
            'lines' => count($lines),
            'quantity' => array_sum(array_map(fn ($l) => (int) $l->quantityloaded, $lines)),
        ];
    }

    /* ---------------- Navigation ---------------- */

    public function openDelivery(string $barcode): void
    {
        $this->barcode = $barcode;
        $this->tab = 'waybill';
        $this->confirmingRemove = false;
        unset($this->delivery, $this->waybill, $this->lines);

        // An existing waybill loads its figures in for correction; a new one
        // starts empty rather than inheriting the last delivery's cost.
        $waybill = $this->waybill;
        $this->receiptnumber = $waybill && $waybill->receiptnumber !== null
            ? (string) $waybill->receiptnumber : '';
        $this->transportcost = $waybill ? (string) $waybill->transportcost : '';
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['waybill', 'print'], true)) {
            $this->tab = $tab;
            $this->confirmingRemove = false;
        }
    }

    public function updatedSearch(): void
    {
        $this->shown = SalesWaybills::QUEUE_LIMIT;
        unset($this->day, $this->page, $this->deliveries);
    }

    public function updatedDateIso(): void
    {
        // The selection belongs to the old date; carrying it over would leave a
        // delivery on screen that the list no longer contains.
        $this->barcode = '';
        $this->shown = SalesWaybills::QUEUE_LIMIT;
        $this->receiptnumber = '';
        $this->transportcost = '';
        unset($this->day, $this->page, $this->deliveries, $this->counts, $this->delivery, $this->waybill, $this->lines);
    }

    public function updatedAwaitingOnly(): void
    {
        $this->shown = SalesWaybills::QUEUE_LIMIT;
        unset($this->day, $this->page, $this->deliveries);
    }

    /* ---------------- Raising and correcting ---------------- */

    protected function figures(): array
    {
        $receipt = trim($this->receiptnumber) === '' ? null : (int) $this->receiptnumber;
        $cost = (float) str_replace(',', '', $this->transportcost);

        return [$receipt, $cost];
    }

    public function raise(): void
    {
        $delivery = $this->delivery;

        if (! $this->canCreate() || ! $delivery) {
            return;
        }

        [$receipt, $cost] = $this->figures();
        $result = SalesWaybills::create($delivery, $receipt, $cost);

        unset($this->day, $this->page, $this->deliveries, $this->counts, $this->delivery, $this->waybill);

        if (! $result['ok']) {
            $this->addError('transportcost', $result['message']);

            return;
        }

        session()->flash('ok', $result['message']);
    }

    public function saveFigures(): void
    {
        $waybill = $this->waybill;

        if (! $this->canModify() || ! $waybill) {
            return;
        }

        [$receipt, $cost] = $this->figures();
        $result = SalesWaybills::update((int) $waybill->id, $receipt, $cost);

        unset($this->day, $this->page, $this->deliveries, $this->delivery, $this->waybill);

        if (! $result['ok']) {
            $this->addError('transportcost', $result['message']);

            return;
        }

        session()->flash('ok', $result['message']);
    }

    public function removeWaybill(): void
    {
        $waybill = $this->waybill;

        if (! $this->canDelete() || ! $waybill) {
            return;
        }

        $result = SalesWaybills::remove((int) $waybill->id);

        $this->confirmingRemove = false;
        $this->receiptnumber = '';
        $this->transportcost = '';
        unset($this->day, $this->page, $this->deliveries, $this->counts, $this->delivery, $this->waybill);

        session()->flash($result['ok'] ? 'ok' : 'err', $result['message']);
    }

    /* ---------------- Print Outs ---------------- */

    #[Computed]
    public function printList(): array
    {
        if (! $this->printOpen || $this->printDate === '') {
            return [];
        }

        $rows = SalesWaybills::waybillsOn(str_replace('-', '/', $this->printDate));

        if ($this->printSearch === '') {
            return $rows;
        }

        $needle = mb_strtolower($this->printSearch);

        return array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
            $r->barcode . ' ' . $r->deliverynumber . ' ' . ($r->customername ?? '')
            . ' ' . ($r->transportername ?? '') . ' ' . ($r->trucknumber ?? '')
            . ' ' . ($r->receiptnumber ?? '')
        ), $needle)));
    }

    public function openPrintOuts(): void
    {
        $this->printOpen = true;
        $this->printDate = $this->printDate ?: $this->dateIso;
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
        return route('bil.sales.waybill.print', ['waybills' => implode(',', $this->printPicked)]);
    }

    public function printUrlFor(string $barcode): string
    {
        return route('bil.sales.waybill.print', ['waybills' => $barcode]);
    }

    public function render()
    {
        return view('bil::livewire.sales.waybill');
    }
}
