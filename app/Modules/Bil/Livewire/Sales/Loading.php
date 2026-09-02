<?php

namespace Modules\Bil\Livewire\Sales;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Support\SalesLoadings;
use Modules\Core\Support\GateAccess;

/**
 * BIL → Sales → Loading. Rebuild of sales_loading.php,
 * sales_loading_modification.php and sales_loading_return.php as one screen.
 *
 * WHY ONE SCREEN, AND WHY THE TABS ARE NOT THE THREE PAGES.
 *
 * The three legacy pages are not peers. Only the first creates anything — it
 * starts from a sales ORDER. The other two start from a load BARCODE and both
 * filter `status IS NULL`: they are two things you do to the same open load.
 * Making them sibling tabs would mean picking the same load twice to correct a
 * quantity and then unload it, and finding out a load was closed only after
 * choosing it in the wrong tab.
 *
 * So the page is LOAD-CENTRIC. A queue of open loads on the left (71 right now,
 * a list rather than a search); New Loading creates one from an order; and the
 * tabs are scoped to whichever load is open:
 *
 *   Lines    correct a quantity, or take a line off      (Modification)
 *   Returns  record goods coming back off the truck      (Return)
 *   Print    the loading printout                        (legacy tab 2)
 *
 * The load's header — transporter, truck, driver, loader, cageroom — is the
 * other half of Modification. It sits above the tabs as an editable panel
 * because it belongs to the load rather than to any one of those views, and
 * saving it updates every line of the barcode at once, as the legacy did.
 *
 * Everything is gated on the load being OPEN. A closed load renders read-only
 * rather than disappearing, so it can still be reprinted.
 */
#[Layout('core::layouts.admin')]
#[Title('Loading')]
class Loading extends Component
{
    /**
     * 'new' = building a load from an order; 'queue' = working an existing one.
     *
     * New Loading is the default because it is what the cageroom opens the page
     * to do. Nothing is selected until the operator picks a load — landing on
     * whatever happened to be newest invited edits to the wrong truck.
     */
    public string $mode = 'new';

    /** Which load is open, by barcode. */
    public string $barcode = '';

    /** Tab within the open load. */
    public string $tab = 'lines';

    /* ---- queue filters ---- */
    public string $search = '';

    /**
     * How many open loads the queue is showing. "Show more" adds another page.
     *
     * Kept as a count rather than a page number because the list is newest-first
     * and grows at the top: paging by offset would shuffle rows under the
     * operator as loads are created.
     */
    public int $shown = SalesLoadings::QUEUE_LIMIT;
    public string $filterCageroom = '';
    public string $dateIso = '';
    public bool $showByDate = false;

    /* ---- the load header (also the New Loading form) ---- */
    public string $transporterid = '';
    public string $cageroomcode = '';
    public string $loader = '';
    public string $trucknumber = '';
    public string $truckdriver = '';

    /* ---- New Loading ---- */
    public string $orderid = '';

    /**
     * The legacy "NEW LOAD NUMBER" checkbox.
     *
     * Several sales orders for one customer share a load number when the truck
     * and crew match — that is how a truck takes more than one order out. What
     * the data cannot tell you is when the SAME truck and customer are going
     * out a second time, so this says it explicitly.
     */
    public bool $newLoadNumber = false;
    /** sod_id => quantity to load. */
    public array $toLoad = [];

    /* ---- the Print Outs modal ---- */
    public bool $printOpen = false;
    public string $printDate = '';
    public string $printSearch = '';
    /** Barcodes ticked for printing. */
    public array $printPicked = [];
    /** Barcode whose details are expanded inline ("View More"). */
    public string $printExpanded = '';

    /* ---- per-line editing ---- */
    public ?int $editingLine = null;
    public string $editQty = '';
    public ?int $returningLine = null;
    public string $returnQty = '';
    public ?int $confirmingRemove = null;

    public const PAGE_KEY = 'bil.sales.loading';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');

        // Someone who cannot create a load has nothing to see in that mode, so
        // they start on the queue with nothing selected instead.
        if (! $this->canCreate()) {
            $this->mode = 'queue';
        }
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

    public function canReturn(): bool
    {
        return $this->mayDo('return');
    }

    /* ---------------- Cagerooms the user may load from ---------------- */

    /**
     * The gate grants decide which bays an operator sees. Cagerooms were
     * imported as warehouse out-gates precisely so this needed no new concept.
     */
    #[Computed]
    public function cagerooms(): array
    {
        $gates = GateAccess::warehouseGates(auth()->user(), 'finished-goods', 'out');
        $codes = collect($gates)->pluck('legacy_name')->filter()->all();

        $all = SalesLoadings::cagerooms();

        // No explicit grants (an admin, typically) sees them all.
        return $codes === [] ? $all : array_intersect_key($all, array_flip($codes));
    }

    protected function allowedCageroomCodes(): ?array
    {
        $mine = array_keys($this->cagerooms);
        $all = array_keys(SalesLoadings::cagerooms());

        return count($mine) === count($all) ? null : $mine;
    }

    /* ---------------- The queue ---------------- */

    /**
     * One page of the queue, plus whether there is another.
     *
     * Asks for one MORE row than it shows: if that extra comes back there is
     * something beyond this page, and it costs nothing to find out. A separate
     * count would have to repeat the search to be right about it.
     */
    #[Computed]
    public function page(): array
    {
        $rows = SalesLoadings::openLoads(
            $this->search ?: null,
            $this->allowedCageroomCodes(),
            $this->shown + 1
        );

        return ['rows' => array_slice($rows, 0, $this->shown), 'more' => count($rows) > $this->shown];
    }

    #[Computed]
    public function openLoads(): array
    {
        return $this->page['rows'];
    }

    public function hasMore(): bool
    {
        return $this->page['more'];
    }

    /** How many are open in total — what the page is a slice of. */
    #[Computed]
    public function openLoadCount(): int
    {
        return SalesLoadings::openLoadCount($this->allowedCageroomCodes());
    }

    public function showMore(): void
    {
        $this->shown += SalesLoadings::QUEUE_LIMIT;
        unset($this->page, $this->openLoads);
    }

    /** Loads on a chosen date — how a closed one is reached, to reprint. */
    #[Computed]
    public function dateLoads(): array
    {
        if (! $this->showByDate || $this->dateIso === '') {
            return [];
        }

        return SalesLoadings::loadsOn(str_replace('-', '/', $this->dateIso));
    }

    /**
     * The open load's header.
     *
     * Taken from a list already fetched wherever possible: the queue row IS the
     * header, and re-running that five-table group-by for one barcode cost as
     * much as building the whole queue. Only a load reached some other way — a
     * closed one found by date, or one just created — needs its own query.
     */
    #[Computed]
    public function load(): ?object
    {
        if ($this->barcode === '') {
            return null;
        }

        foreach ([...$this->openLoads, ...$this->dateLoads] as $l) {
            if ($l->barcode === $this->barcode) {
                return $l;
            }
        }

        return SalesLoadings::load($this->barcode);
    }

    #[Computed]
    public function lines(): array
    {
        return $this->barcode === '' ? [] : SalesLoadings::lines($this->barcode);
    }

    public function isOpen(): bool
    {
        $load = $this->load;

        return $load !== null && ($load->status === null || trim((string) $load->status) === '');
    }

    /* ---------------- Lookups ---------------- */

    #[Computed]
    public function transporters(): array
    {
        return SalesLoadings::transporters();
    }

    #[Computed]
    public function transporterOptions(): array
    {
        return collect($this->transporters)
            ->map(fn ($name, $id) => ['value' => (string) $id, 'label' => $name])
            ->values()->all();
    }

    #[Computed]
    public function cageroomOptions(): array
    {
        return collect($this->cagerooms)
            ->map(fn ($name, $code) => ['value' => $code, 'label' => $name])
            ->values()->all();
    }

    #[Computed]
    public function trucks(): array
    {
        return SalesLoadings::recentTrucks();
    }

    #[Computed]
    public function drivers(): array
    {
        return SalesLoadings::recentDrivers();
    }

    #[Computed]
    public function orders(): array
    {
        return SalesLoadings::loadableOrders();
    }

    #[Computed]
    public function orderOptions(): array
    {
        return collect($this->orders)->map(fn ($o) => [
            'value' => $o->orderid,
            'label' => $o->orderid . ' — ' . ($o->customername ?: 'no customer'),
        ])->all();
    }

    #[Computed]
    public function orderLines(): array
    {
        return $this->orderid === '' ? [] : SalesLoadings::orderLines($this->orderid);
    }

    #[Computed]
    public function order(): ?object
    {
        return $this->orderid === '' ? null : SalesLoadings::order($this->orderid);
    }

    /* ---------------- What this load will join ---------------- */

    /** Loads this truck has already taken out today. */
    #[Computed]
    public function truckLoadCount(): int
    {
        return SalesLoadings::truckLoadCount($this->trucknumber, now()->format('Y/m/d'));
    }

    /**
     * The load number this would join, or null if it would start a new one.
     * Recomputed as the truck and crew are typed, so what is about to happen is
     * on screen before Create is pressed rather than discovered afterwards.
     */
    #[Computed]
    public function joiningLoad(): ?int
    {
        if ($this->newLoadNumber || $this->trucknumber === '' || $this->loader === '' || $this->truckdriver === '') {
            return null;
        }

        $order = $this->order;

        return SalesLoadings::joinableLoadNumber(now()->format('Y/m/d'), [
            'trucknumber' => strtoupper(str_replace(' ', '', $this->trucknumber)),
            'loader' => strtoupper(trim($this->loader)),
            'truckdriver' => strtoupper(trim($this->truckdriver)),
        ], $order && $order->customerid ? (int) $order->customerid : null);
    }

    /* ---------------- Navigation ---------------- */

    public function openLoad(string $barcode): void
    {
        $this->barcode = $barcode;
        $this->mode = 'queue';
        $this->tab = 'lines';
        $this->resetLineEditors();

        $load = $this->load;

        if ($load) {
            $this->transporterid = (string) ($load->transporterid ?? '');
            $this->cageroomcode = (string) ($load->cageroomcode ?? '');
            $this->loader = (string) ($load->loader ?? '');
            $this->trucknumber = (string) ($load->trucknumber ?? '');
            $this->truckdriver = (string) ($load->truckdriver ?? '');
        }
    }

    public function startNew(): void
    {
        if (! $this->canCreate()) {
            return;
        }

        $this->mode = 'new';
        $this->barcode = '';
        $this->orderid = '';
        $this->toLoad = [];
        $this->newLoadNumber = false;
        $this->reset(['transporterid', 'cageroomcode', 'loader', 'trucknumber', 'truckdriver']);
        $this->resetLineEditors();
    }

    /** Leave New Loading without selecting anything — nothing is chosen for you. */
    public function cancelNew(): void
    {
        $this->mode = 'queue';
        $this->barcode = '';
        $this->orderid = '';
        $this->toLoad = [];
        $this->resetLineEditors();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['lines', 'returns', 'print'], true)) {
            $this->tab = $tab;
            $this->resetLineEditors();
        }
    }

    protected function resetLineEditors(): void
    {
        $this->editingLine = null;
        $this->returningLine = null;
        $this->confirmingRemove = null;
        $this->editQty = '';
        $this->returnQty = '';
    }

    public function updatedSearch(): void
    {
        // A new search starts at the first page.
        $this->shown = SalesLoadings::QUEUE_LIMIT;
        unset($this->page, $this->openLoads);
    }

    public function updatedFilterCageroom(): void
    {
        $this->shown = SalesLoadings::QUEUE_LIMIT;
        unset($this->page, $this->openLoads, $this->openLoadCount);
    }

    public function updatedOrderid(): void
    {
        // Default every line to its outstanding quantity — the common case is
        // loading what is left, and retyping it invites transcription errors.
        $this->toLoad = [];

        foreach ($this->orderLines as $line) {
            $this->toLoad[$line->sod_id] = $line->outstanding > 0 ? $line->outstanding : '';
        }

        unset($this->orderLines, $this->order);
    }

    /* ---------------- New loading ---------------- */

    public function saveNew(): void
    {
        if (! $this->canCreate()) {
            return;
        }

        $this->validate([
            'orderid' => ['required', 'string'],
            'transporterid' => ['required', 'integer'],
            'cageroomcode' => ['required', 'string'],
            'loader' => ['required', 'string', 'max:255'],
            'trucknumber' => ['required', 'string', 'max:30'],
            'truckdriver' => ['required', 'string', 'max:50'],
        ], [], [
            'transporterid' => 'transporter',
            'cageroomcode' => 'cageroom',
            'trucknumber' => 'truck number',
            'truckdriver' => 'driver',
        ]);

        $order = $this->order;

        if (! $order) {
            $this->addError('orderid', 'That order could not be found.');

            return;
        }

        // Only lines with something on them, and never more than is outstanding
        // — loading more than was ordered is a data-entry slip, not a decision.
        $lines = [];

        foreach ($this->orderLines as $line) {
            $qty = (int) ($this->toLoad[$line->sod_id] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            if ($qty > $line->outstanding) {
                $this->addError('toLoad.' . $line->sod_id,
                    'Only ' . number_format($line->outstanding) . ' left to load on this line.');

                return;
            }

            $lines[] = ['sod_id' => $line->sod_id, 'quantity' => $qty];
        }

        if ($lines === []) {
            $this->addError('orderid', 'Nothing to load — enter a quantity on at least one line.');

            return;
        }

        $barcode = SalesLoadings::create([
            'transporterid' => (int) $this->transporterid,
            'cageroomcode' => $this->cageroomcode,
            'loader' => strtoupper(trim($this->loader)),
            'trucknumber' => strtoupper(str_replace(' ', '', $this->trucknumber)),
            'truckdriver' => strtoupper(trim($this->truckdriver)),
            'warehousecode' => $order->warehousecode,
        ], $lines, now()->format('Y/m/d'),
            $order->customerid ? (int) $order->customerid : null,
            $this->newLoadNumber);

        unset($this->page, $this->openLoads);
        session()->flash('ok', 'Load ' . $barcode . ' created with ' . count($lines) . ' line(s).');
        $this->openLoad($barcode);
    }

    /* ---------------- Modification: the header ---------------- */

    public function saveHeader(): void
    {
        if (! $this->canModify() || ! $this->isOpen()) {
            return;
        }

        $this->validate([
            'transporterid' => ['required', 'integer'],
            'cageroomcode' => ['required', 'string'],
            'loader' => ['required', 'string', 'max:255'],
            'trucknumber' => ['required', 'string', 'max:30'],
            'truckdriver' => ['required', 'string', 'max:50'],
        ]);

        SalesLoadings::updateHeader($this->barcode, [
            'transporterid' => (int) $this->transporterid,
            'cageroomcode' => $this->cageroomcode,
            'loader' => strtoupper(trim($this->loader)),
            'trucknumber' => strtoupper(str_replace(' ', '', $this->trucknumber)),
            'truckdriver' => strtoupper(trim($this->truckdriver)),
        ]);

        unset($this->load, $this->page, $this->openLoads);
        session()->flash('ok', 'Truck and crew updated for the whole load.');
    }

    /* ---------------- Modification: the lines ---------------- */

    public function editLine(int $id, int $current): void
    {
        if (! $this->canModify() || ! $this->isOpen()) {
            return;
        }

        $this->resetLineEditors();
        $this->editingLine = $id;
        $this->editQty = (string) $current;
    }

    public function saveLine(): void
    {
        if (! $this->canModify() || ! $this->isOpen() || ! $this->editingLine) {
            return;
        }

        $qty = (int) $this->editQty;

        if ($qty < 0) {
            $this->addError('editQty', 'A quantity cannot be negative.');

            return;
        }

        SalesLoadings::correctLine($this->editingLine, $qty);
        $this->resetLineEditors();
        unset($this->lines, $this->load, $this->page, $this->openLoads);
        session()->flash('ok', 'Line corrected. Any return recorded against it has been cleared.');
    }

    public function removeLine(): void
    {
        if (! $this->canModify() || ! $this->isOpen() || ! $this->confirmingRemove) {
            return;
        }

        SalesLoadings::removeLine($this->confirmingRemove);
        $this->resetLineEditors();
        unset($this->lines, $this->load, $this->page, $this->openLoads);
        session()->flash('ok', 'Line removed from the load.');
    }

    /* ---------------- Returns ---------------- */

    public function startReturn(int $id): void
    {
        if (! $this->canReturn() || ! $this->isOpen()) {
            return;
        }

        $this->resetLineEditors();
        $this->returningLine = $id;
        $this->returnQty = '';
    }

    public function saveReturn(): void
    {
        if (! $this->canReturn() || ! $this->isOpen() || ! $this->returningLine) {
            return;
        }

        $qty = (int) $this->returnQty;

        if ($qty <= 0) {
            $this->addError('returnQty', 'Enter how many are coming back off.');

            return;
        }

        if (! SalesLoadings::recordReturn($this->returningLine, $qty)) {
            $this->addError('returnQty', 'That is more than is on this line.');

            return;
        }

        $this->resetLineEditors();
        unset($this->lines, $this->load, $this->page, $this->openLoads);
        session()->flash('ok', 'Return recorded — the goods are back off the truck.');
    }

    public function undoReturn(int $id): void
    {
        if (! $this->canModify() || ! $this->isOpen()) {
            return;
        }

        SalesLoadings::undoReturns($id);
        unset($this->lines, $this->load, $this->page, $this->openLoads);
        session()->flash('ok', 'Return undone — the goods are back on the truck.');
    }

    /* ---------------- Print Outs ---------------- */

    /**
     * The day's loads, as the legacy "Loading Print Out" tab listed them.
     *
     * Deliberately NOT the open queue: this is the printing view, and a load
     * that has been delivered still gets reprinted. It lists everything on the
     * date, newest load number first, exactly as the legacy did.
     */
    #[Computed]
    public function printList(): array
    {
        if (! $this->printOpen || $this->printDate === '') {
            return [];
        }

        $rows = SalesLoadings::printoutList(str_replace('-', '/', $this->printDate));

        if ($this->printSearch === '') {
            return $rows;
        }

        $needle = mb_strtolower($this->printSearch);

        return array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
            $r->barcode . ' ' . $r->loadnumber . ' ' . ($r->customername ?? '')
            . ' ' . ($r->transportername ?? '') . ' ' . ($r->trucknumber ?? '')
        ), $needle)));
    }

    public function openPrintOuts(): void
    {
        $this->printOpen = true;
        // Default to the day being worked — usually today, or whatever date the
        // operator was already looking at.
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
        // A tick on a load from another day would silently print with it.
        $this->printPicked = [];
        $this->printExpanded = '';
        unset($this->printList);
    }

    public function updatedPrintSearch(): void
    {
        unset($this->printList);
    }

    /** Expand one row's details inline — the legacy "View More". */
    public function togglePrintDetails(string $barcode): void
    {
        $this->printExpanded = $this->printExpanded === $barcode ? '' : $barcode;
    }

    /** Tick every load currently listed, or clear the lot. */
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

    /**
     * Where the Print button goes: one document holding every ticked load,
     * page-broken between them. Empty selection prints nothing rather than
     * everything, which is the safer way round.
     */
    public function printUrl(): string
    {
        return route('bil.sales.loading.print', ['loads' => implode(',', $this->printPicked)]);
    }

    /** A single load, for the per-row print link. */
    public function printUrlFor(string $barcode): string
    {
        return route('bil.sales.loading.print', ['loads' => $barcode]);
    }

    /* ---------------- Totals ---------------- */

    public function totals(): array
    {
        $lines = $this->lines;

        return [
            'lines' => count($lines),
            'loaded' => array_sum(array_map(fn ($l) => $l->loaded_net, $lines)),
            'returned' => array_sum(array_map(fn ($l) => $l->returned, $lines)),
        ];
    }

    public function render()
    {
        return view('bil::livewire.sales.loading');
    }
}
