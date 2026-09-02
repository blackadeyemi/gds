<?php

namespace Modules\Bil\Livewire\Sales;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Support\SalesReturns;

/**
 * BIL → Sales → Returns. Rebuild of sales_return.php and
 * sales_return_modification.php as one screen.
 *
 * SAME PATTERN AS LOADING AND DELIVERY — a queue on the left, the selected
 * thing on the right, tabs scoped to it, Print Outs top-right — with one
 * deliberate difference in how a NEW one is built.
 *
 * Loading starts from an order and Delivery starts from a load, because in both
 * cases the thing is already in front of you. A return is not: bundles arrive
 * back from a customer with no paperwork of their own, and which delivery they
 * came off is something the office works out. So New Return starts where the
 * event starts —
 *
 *     customer → product → how many → which delivery it comes off
 *
 * — and the last step is a LIST, not a guess: every delivery of that product to
 * that customer, newest first, with what is still returnable on each. The one
 * that can cover the quantity is pre-selected.
 *
 * Measured before building: across 366 return lines since 2024 a single
 * delivery always had enough, so that pre-selection is right almost always.
 * When it is not, the "split across deliveries" box spreads the quantity newest
 * first rather than dead-ending on a return that physically happened.
 *
 * Several products go on one return: the return NUMBER is per customer per day,
 * so everything they sent back in one go belongs on one sheet. The basket on
 * the right is that sheet being built.
 */
#[Layout('core::layouts.admin')]
#[Title('Returns')]
class Returns extends Component
{
    /** 'new' = building a return; 'queue' = looking at one already recorded. */
    public string $mode = 'new';

    /* ---- the selected return ---- */
    public string $dateofreturn = '';
    public ?int $returnnumber = null;

    /** Tab within the selected return. */
    public string $tab = 'lines';

    /* ---- queue ---- */
    public string $search = '';

    /**
     * How many returns the queue is showing. "Show more" adds another page.
     *
     * Kept as state rather than a page number because the list is newest-first
     * and grows at the top: paging by offset would shuffle rows under the
     * operator as returns are recorded.
     */
    public int $shown = SalesReturns::QUEUE_LIMIT;

    /* ---- New Return: the header ---- */
    public string $customerid = '';
    public string $dateIso = '';

    /* ---- New Return: the line being added ---- */
    public string $productid = '';
    public string $quantity = '';
    public string $rejected = '';
    /** sod_id of the delivery the operator picked, or '' for the default. */
    public string $sodId = '';
    /** Spread the quantity over several deliveries instead of one. */
    public bool $split = false;

    /**
     * The basket: what will be saved when Record return is pressed.
     * Each entry is ['sod_id', 'returned', 'rejected', 'productid',
     * 'productname', 'productcode', 'orderid'].
     */
    public array $basket = [];

    /* ---- per-line editing on an existing return ---- */
    public ?int $editingLine = null;
    public string $editReturned = '';
    public string $editRejected = '';
    public ?int $confirmingRemove = null;

    /* ---- Print Outs modal ---- */
    public bool $printOpen = false;
    public string $printDate = '';
    public string $printSearch = '';
    public array $printPicked = [];
    public string $printExpanded = '';

    public const PAGE_KEY = 'bil.sales.returns';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');

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

    public function canDelete(): bool
    {
        return $this->mayDo('delete');
    }

    /* ---------------- The queue ---------------- */

    /**
     * One page of the queue, plus whether there is another.
     *
     * Asks for one MORE than it shows: if that extra row comes back there is
     * something beyond this page, and it costs nothing to find out. A separate
     * count would have to repeat the search to be right about it.
     */
    #[Computed]
    public function page(): array
    {
        $rows = SalesReturns::recent($this->search ?: null, $this->shown + 1);

        return ['rows' => array_slice($rows, 0, $this->shown), 'more' => count($rows) > $this->shown];
    }

    #[Computed]
    public function returns(): array
    {
        return $this->page['rows'];
    }

    public function hasMore(): bool
    {
        return $this->page['more'];
    }

    /** All returns ever recorded — what the queue's page is a slice of. */
    #[Computed]
    public function returnCount(): int
    {
        return SalesReturns::totalCount();
    }

    public function showMore(): void
    {
        $this->shown += SalesReturns::QUEUE_LIMIT;
        unset($this->page, $this->returns);
    }

    #[Computed]
    public function selected(): ?object
    {
        if ($this->dateofreturn === '' || $this->returnnumber === null) {
            return null;
        }

        return SalesReturns::find($this->dateofreturn, $this->returnnumber);
    }

    #[Computed]
    public function lines(): array
    {
        if ($this->dateofreturn === '' || $this->returnnumber === null) {
            return [];
        }

        return SalesReturns::lines($this->dateofreturn, $this->returnnumber);
    }

    public function totals(): array
    {
        $lines = $this->lines;

        return [
            'lines' => count($lines),
            'returned' => array_sum(array_map(fn ($l) => $l->quantityreturned, $lines)),
            'rejected' => array_sum(array_map(fn ($l) => $l->quantityrejected, $lines)),
            'to_stock' => array_sum(array_map(fn ($l) => $l->to_stock, $lines)),
        ];
    }

    /* ---------------- Pickers ---------------- */

    #[Computed]
    public function customerOptions(): array
    {
        return collect(SalesReturns::customers())
            ->map(fn ($name, $id) => ['value' => (string) $id, 'label' => $name])
            ->values()->all();
    }

    #[Computed]
    public function productOptions(): array
    {
        if ($this->customerid === '') {
            return [];
        }

        return collect(SalesReturns::productsFor((int) $this->customerid))
            ->map(fn ($name, $id) => ['value' => (string) $id, 'label' => $name])
            ->values()->all();
    }

    /** The customer's deliveries of the chosen product, newest first. */
    #[Computed]
    public function eligible(): array
    {
        if ($this->customerid === '' || $this->productid === '') {
            return [];
        }

        $lines = SalesReturns::eligibleLines((int) $this->customerid, (int) $this->productid);

        // Anything already in the basket is spoken for, so offer only what is
        // left — otherwise two lines of one basket could claim the same bundles
        // and only the second would be refused at save time.
        $claimed = [];

        foreach ($this->basket as $row) {
            $claimed[$row['sod_id']] = ($claimed[$row['sod_id']] ?? 0) + $row['returned'];
        }

        foreach ($lines as $line) {
            $line->remaining = max(0, $line->remaining - ($claimed[$line->id] ?? 0));
        }

        return array_values(array_filter($lines, fn ($l) => $l->remaining > 0));
    }

    /** Which delivery a plain (unsplit) return would be booked against. */
    #[Computed]
    public function defaultLine(): ?object
    {
        $want = (int) $this->quantity;
        $eligible = $this->eligible;

        if ($eligible === []) {
            return null;
        }

        if ($this->sodId !== '') {
            foreach ($eligible as $line) {
                if ((int) $line->id === (int) $this->sodId) {
                    return $line;
                }
            }
        }

        // The newest delivery that can carry the whole quantity.
        foreach ($eligible as $line) {
            if ($want > 0 && $line->remaining >= $want) {
                return $line;
            }
        }

        return null;
    }

    /** True when no single delivery can cover what is being returned. */
    public function needsSplit(): bool
    {
        $want = (int) $this->quantity;

        return $want > 0 && $this->eligible !== [] && $this->defaultLine === null;
    }

    /** How the quantity would be spread, when splitting. */
    #[Computed]
    public function splitPlan(): array
    {
        $want = (int) $this->quantity;

        if ($want <= 0) {
            return [];
        }

        $taken = SalesReturns::splitAcross($this->eligible, $want);
        $plan = [];

        foreach ($this->eligible as $line) {
            if (isset($taken[$line->id])) {
                $plan[] = ['line' => $line, 'quantity' => $taken[$line->id]];
            }
        }

        return $plan;
    }

    public function totalEligible(): int
    {
        return array_sum(array_map(fn ($l) => (int) $l->remaining, $this->eligible));
    }

    /* ---------------- Navigation ---------------- */

    public function openReturn(string $dateSlash, int $number): void
    {
        $this->dateofreturn = $dateSlash;
        $this->returnnumber = $number;
        $this->mode = 'queue';
        $this->tab = 'lines';
        $this->resetLineEditors();
        unset($this->selected, $this->lines);
    }

    public function startNew(): void
    {
        if (! $this->canCreate()) {
            return;
        }

        $this->mode = 'new';
        $this->dateofreturn = '';
        $this->returnnumber = null;
        $this->basket = [];
        $this->resetEntry();
        $this->reset(['customerid']);
        $this->dateIso = now()->format('Y-m-d');
    }

    public function cancelNew(): void
    {
        $this->mode = 'queue';
        $this->basket = [];
        $this->resetEntry();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['lines', 'print'], true)) {
            $this->tab = $tab;
            $this->resetLineEditors();
        }
    }

    protected function resetEntry(): void
    {
        $this->productid = '';
        $this->quantity = '';
        $this->rejected = '';
        $this->sodId = '';
        $this->split = false;
        unset($this->eligible, $this->defaultLine, $this->splitPlan, $this->productOptions);
    }

    protected function resetLineEditors(): void
    {
        $this->editingLine = null;
        $this->confirmingRemove = null;
        $this->editReturned = '';
        $this->editRejected = '';
    }

    public function updatedSearch(): void
    {
        // A new search starts at the first page — carrying a deep page over
        // means typing a term and seeing a scrollbar of results you did not ask
        // to expand.
        $this->shown = SalesReturns::QUEUE_LIMIT;
        unset($this->page, $this->returns);
    }

    public function updatedCustomerid(): void
    {
        // The product list is per customer, and the basket belongs to one
        // customer's return — changing customer starts again rather than
        // quietly mixing two people's goods onto one sheet.
        $this->basket = [];
        $this->resetEntry();
    }

    public function updatedProductid(): void
    {
        $this->quantity = '';
        $this->rejected = '';
        $this->sodId = '';
        $this->split = false;
        unset($this->eligible, $this->defaultLine, $this->splitPlan);
    }

    public function updatedQuantity(): void
    {
        unset($this->defaultLine, $this->splitPlan);
    }

    public function updatedSodId(): void
    {
        unset($this->defaultLine);
    }

    /* ---------------- Building the basket ---------------- */

    public function addLine(): void
    {
        if (! $this->canCreate()) {
            return;
        }

        $want = (int) $this->quantity;
        $reject = (int) ($this->rejected ?: 0);

        if ($this->customerid === '' || $this->productid === '') {
            $this->addError('productid', 'Choose a customer and a product first.');

            return;
        }

        if ($want <= 0) {
            $this->addError('quantity', 'Enter how many are coming back.');

            return;
        }

        if ($reject < 0 || $reject > $want) {
            $this->addError('rejected', 'Rejected cannot be more than the quantity returned.');

            return;
        }

        if ($want > $this->totalEligible()) {
            $this->addError('quantity', 'Only ' . number_format($this->totalEligible())
                . ' of this product were delivered to this customer and not already returned.');

            return;
        }

        $product = collect($this->productOptions)->firstWhere('value', $this->productid);

        // Splitting spreads the REJECTED part in proportion, so a two-line
        // split of 100 returned / 10 rejected does not put all ten on the first
        // line and misstate which delivery the damage came off.
        $allocation = $this->split || $this->needsSplit()
            ? $this->splitPlan
            : [['line' => $this->defaultLine, 'quantity' => $want]];

        if ($allocation === [] || $allocation[0]['line'] === null) {
            $this->addError('quantity', 'No delivery of this product is left to book the return against.');

            return;
        }

        $placed = 0;
        $rejectLeft = $reject;
        $last = count($allocation) - 1;

        foreach ($allocation as $i => $step) {
            $line = $step['line'];
            $qty = (int) $step['quantity'];

            // The last line takes the rounding, so the rejected parts always
            // add back up to what was typed.
            $lineReject = $i === $last
                ? $rejectLeft
                : (int) floor($reject * $qty / max(1, $want));

            $rejectLeft -= $lineReject;
            $placed += $qty;

            $this->basket[] = [
                'sod_id' => (int) $line->id,
                'returned' => $qty,
                'rejected' => max(0, $lineReject),
                'productid' => (int) $this->productid,
                'productname' => $product['label'] ?? ('#' . $this->productid),
                'productcode' => '',
                'orderid' => (string) $line->orderid,
                'dateoforder' => (string) $line->dateoforder,
                'last_delivery' => (string) $line->last_delivery,
            ];
        }

        if ($placed < $want) {
            // Should be unreachable — totalEligible() is checked above — but a
            // short basket would print a sheet that understates the return.
            $this->basket = [];
            $this->addError('quantity', 'That quantity could not be booked against the deliveries available.');

            return;
        }

        $this->resetEntry();
    }

    public function removeFromBasket(int $index): void
    {
        unset($this->basket[$index]);
        $this->basket = array_values($this->basket);
        unset($this->eligible, $this->defaultLine, $this->splitPlan);
    }

    public function basketTotals(): array
    {
        return [
            'lines' => count($this->basket),
            'returned' => array_sum(array_column($this->basket, 'returned')),
            'rejected' => array_sum(array_column($this->basket, 'rejected')),
        ];
    }

    public function saveNew(): void
    {
        if (! $this->canCreate()) {
            return;
        }

        if ($this->customerid === '' || $this->basket === []) {
            $this->addError('customerid', 'Add at least one product to the return.');

            return;
        }

        $result = SalesReturns::create(
            (int) $this->customerid,
            str_replace('-', '/', $this->dateIso),
            $this->basket
        );

        if (! $result['ok']) {
            session()->flash('err', $result['message']);

            return;
        }

        $number = (int) $result['number'];
        $this->basket = [];
        $this->resetEntry();
        unset($this->page, $this->returns);
        session()->flash('ok', $result['message']);
        $this->openReturn(str_replace('-', '/', $this->dateIso), $number);
    }

    /* ---------------- Modification ---------------- */

    public function editLine(int $id, int $returned, int $rejected): void
    {
        if (! $this->canModify()) {
            return;
        }

        $this->resetLineEditors();
        $this->editingLine = $id;
        $this->editReturned = (string) $returned;
        $this->editRejected = (string) $rejected;
    }

    public function saveLine(): void
    {
        if (! $this->canModify() || ! $this->editingLine) {
            return;
        }

        $result = SalesReturns::updateLine(
            $this->editingLine, (int) $this->editReturned, (int) ($this->editRejected ?: 0)
        );

        if (! $result['ok']) {
            $this->addError('editReturned', $result['message']);

            return;
        }

        $this->resetLineEditors();
        unset($this->lines, $this->selected, $this->page, $this->returns);
        session()->flash('ok', $result['message']);
    }

    public function removeLine(): void
    {
        if (! $this->canDelete() || ! $this->confirmingRemove) {
            return;
        }

        $result = SalesReturns::removeLine($this->confirmingRemove);
        $this->resetLineEditors();

        // The last line taken off leaves no return at all, so nothing stays
        // selected — the alternative is a header with an empty sheet under it.
        $remaining = $this->dateofreturn !== '' && $this->returnnumber !== null
            ? SalesReturns::lines($this->dateofreturn, $this->returnnumber)
            : [];

        unset($this->lines, $this->selected, $this->page, $this->returns);

        if ($remaining === []) {
            $this->dateofreturn = '';
            $this->returnnumber = null;
        }

        session()->flash($result['ok'] ? 'ok' : 'err', $result['message']);
    }

    /* ---------------- Print Outs ---------------- */

    #[Computed]
    public function printList(): array
    {
        if (! $this->printOpen || $this->printDate === '') {
            return [];
        }

        $rows = SalesReturns::returnsOn(str_replace('-', '/', $this->printDate));

        if ($this->printSearch === '') {
            return $rows;
        }

        $needle = mb_strtolower($this->printSearch);

        return array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
            $r->returnnumber . ' ' . ($r->customername ?? '') . ' ' . implode(' ', $r->orders)
        ), $needle)));
    }

    public function openPrintOuts(): void
    {
        $this->printOpen = true;
        $this->printDate = $this->printDate ?: ($this->dateofreturn !== ''
            ? str_replace('/', '-', $this->dateofreturn)
            : now()->format('Y-m-d'));
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

    public function togglePrintDetails(string $key): void
    {
        $this->printExpanded = $this->printExpanded === $key ? '' : $key;
    }

    public function togglePrintAll(): void
    {
        $listed = array_map(fn ($r) => $r->dateofreturn . '|' . $r->returnnumber, $this->printList);

        $this->printPicked = count($this->printPicked) === count($listed) ? [] : $listed;
    }

    public function allPrintPicked(): bool
    {
        $listed = $this->printList;

        return $listed !== [] && count($this->printPicked) === count($listed);
    }

    public function printUrl(): string
    {
        return route('bil.sales.returns.print', ['returns' => implode(',', $this->printPicked)]);
    }

    public function printUrlFor(string $key): string
    {
        return route('bil.sales.returns.print', ['returns' => $key]);
    }

    public function render()
    {
        return view('bil::livewire.sales.returns');
    }
}
