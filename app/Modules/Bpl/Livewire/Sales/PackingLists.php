<?php

namespace Modules\Bpl\Livewire\Sales;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplPackingList;
use Modules\Bpl\Support\PackingList as PackingListSupport;
use Modules\Bpl\Support\ProformaInvoice;

/**
 * BPL → Sales → Packing Lists. Rebuild of legacy `bpl_packing_list.php`
 * (handler `Bil\Bpl\PackingList`): what actually went into each container, the
 * document that travels with the shipment, and the final invoice priced on
 * what shipped.
 *
 * A packing list is raised against an order that HAS a proforma. The proforma
 * says how many containers were quoted, and the form opens that many; each
 * carries its container (or truck) number, its tare weight, and a line per
 * product loaded into it, in rolls and kilograms.
 *
 * Changes from legacy:
 *  - **What is loaded is checked against what was ordered.** The legacy only
 *    refused a line with nothing on it at all ("Goods still on ground"); it
 *    would happily ship 40 tonnes against a 12-tonne order line. Over-loading
 *    is now refused by line, and under-loading has to be deliberate: the form
 *    shows ordered, loaded and what is left, and the save says so.
 *  - **Container numbers must be filled in and must differ from each other.**
 *  - **Nothing is half-written.** Splitting into partial shipments is one
 *    transaction, as is every save.
 *  - **The final invoice is the proforma sheet priced on what shipped**, not
 *    a second near-identical template.
 *
 * A packing list with a bill of lading cannot be changed or deleted, and a
 * partial shipment (a `…(A)` row) is never edited — both as legacy had it.
 *
 * NOT here: the bill of lading itself and the invoice payments
 * (`bpl_invoice_payments`), which are their own screens.
 */
#[Layout('core::layouts.admin')]
#[Title('BPL Packing Lists')]
class PackingLists extends Component
{
    public const PAGE_KEY = 'bpl.sales.packing_lists';

    /** 'list' (the default) or 'form'. */
    public string $mode = 'list';

    /* ---------------- The list ---------------- */

    public string $search = '';
    public int $perPage = 15;

    /* ---------------- The form ---------------- */

    public ?int $editingId = null;
    public ?int $orderId = null;
    public string $dateIso = '';

    /**
     * The containers being built:
     * [['name' => 'MSNU 739149-0', 'tare' => '1000', 'goods' => [order_item_id => ['rolls' => '', 'weight' => '']]], …]
     */
    public array $containers = [];

    public string $formError = '';

    /* ---------------- Splitting ---------------- */

    public ?int $splittingId = null;

    /** container index => shipment number (1-based). */
    public array $splitAssignment = [];
    public int $splitCount = 2;
    public string $splitError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    /* ---------------- The list ---------------- */

    #[Computed]
    public function rows()
    {
        $term = trim($this->search);

        return DB::connection('bpl')->table('bpl_packing_list as pl')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('REPLACE(pl.`number`, CONCAT(\':\', c.`id`), c.`customerlabel`) LIKE ?', ['%' . $term . '%'])
                ->orWhere('c.customername', 'like', '%' . $term . '%')))
            ->orderByDesc('pl.date')
            ->orderByDesc('pl.id')
            ->limit($this->perPage)
            ->get(['pl.id', 'pl.order_id', 'pl.number', 'pl.containers', 'pl.date', 'pl.split',
                'c.id as customer_id', 'c.customerlabel', 'c.customername', 's.orderno']);
    }

    /** Order ids with more than one packing list — a split order. */
    #[Computed]
    public function splitOrders(): array
    {
        $ids = $this->rows->pluck('order_id')->unique()->all();

        if ($ids === []) {
            return [];
        }

        return DB::connection('bpl')->table('bpl_packing_list')
            ->whereIn('order_id', $ids)
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Packing lists in the list that already have a bill of lading. */
    #[Computed]
    public function shipped(): array
    {
        $ids = $this->rows->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return DB::connection('bpl')->table('bpl_bill_of_lading')
            ->whereIn('packing_id', $ids)->pluck('packing_id')->map(fn ($id) => (int) $id)->all();
    }

    public function label($row): string
    {
        return PackingListSupport::label($row->number, (int) $row->customer_id, $row->customerlabel);
    }

    /** Rolls, net and gross for a list row, read off its containers JSON. */
    public function totalsFor($row): array
    {
        $containers = json_decode((string) $row->containers, true) ?: [];

        $rolls = 0;
        $net = 0.0;
        $tare = 0.0;

        foreach ($containers as $container) {
            $rolls += array_sum(array_column($container['goods'] ?? [], 'rolls'));
            $net += array_sum(array_column($container['goods'] ?? [], 'weight'));
            $tare += (float) ($container['weight'] ?? 0);
        }

        return ['containers' => count($containers), 'rolls' => $rolls,
            'net' => round($net, 2), 'gross' => round($net + $tare, 2)];
    }

    /* ---------------- The form ---------------- */

    #[Computed]
    public function openProformas()
    {
        return PackingListSupport::openProformas();
    }

    /** The order's priced lines — what may be loaded, and how much of it. */
    #[Computed]
    public function lines(): array
    {
        return $this->orderId ? ProformaInvoice::lines($this->orderId) : [];
    }

    /** How much of each line has been put into containers so far. */
    public function loaded(): array
    {
        $loaded = [];

        foreach ($this->containers as $container) {
            foreach ($container['goods'] ?? [] as $itemId => $goods) {
                $loaded[$itemId] = ($loaded[$itemId] ?? 0) + (float) ($goods['weight'] ?? 0);
            }
        }

        return $loaded;
    }

    public function startNew(): void
    {
        $this->editingId = null;
        $this->orderId = null;
        $this->dateIso = now()->format('Y-m-d');
        $this->containers = [];
        $this->formError = '';
        $this->resetErrorBag();
        $this->mode = 'form';
        unset($this->lines, $this->openProformas);
    }

    public function showList(): void
    {
        $this->mode = 'list';
        $this->formError = '';
        unset($this->rows, $this->splitOrders, $this->shipped);
    }

    /** Choosing an order opens as many containers as the proforma quoted. */
    public function updatedOrderId(): void
    {
        unset($this->lines);
        $this->containers = [];
        $this->formError = '';

        $proforma = $this->openProformas->firstWhere('id', $this->orderId);
        $wanted = max(1, (int) ($proforma->container ?? 1));

        for ($i = 0; $i < $wanted; $i++) {
            $this->containers[] = $this->blankContainer();
        }

        if ($proforma && $proforma->date) {
            $this->dateIso = str_replace('/', '-', $proforma->date);
        }
    }

    protected function blankContainer(): array
    {
        return [
            'name' => '',
            'tare' => (string) BplPackingList::DEFAULT_TARE,
            'goods' => collect($this->lines)->mapWithKeys(fn ($l) => [$l['order_item_id'] => ['rolls' => '', 'weight' => '']])->all(),
        ];
    }

    public function addContainer(): void
    {
        if (count($this->containers) >= 40) {
            $this->formError = 'That is 40 containers — split the shipment instead.';

            return;
        }

        $this->containers[] = $this->blankContainer();
    }

    public function removeContainer(int $index): void
    {
        unset($this->containers[$index]);
        $this->containers = array_values($this->containers);

        if ($this->containers === []) {
            $this->containers[] = $this->blankContainer();
        }
    }

    public function edit(int $id): void
    {
        if (! $this->mayDo('edit')) {
            return;
        }

        $packing = BplPackingList::find($id);

        if (! $packing) {
            return;
        }

        if ($packing->isPartial()) {
            session()->flash('err', 'A partial shipment cannot be changed. Delete it and split the packing list again.');

            return;
        }

        if (BplPackingList::where('order_id', $packing->order_id)->count() > 1) {
            session()->flash('err', 'This packing list has been split into partial shipments — those are what ship now.');

            return;
        }

        if (PackingListSupport::hasBillOfLading($id)) {
            session()->flash('err', 'A bill of lading has been issued for this packing list — it can no longer be changed.');

            return;
        }

        $this->editingId = $id;
        $this->orderId = (int) $packing->order_id;
        $this->dateIso = str_replace('/', '-', (string) $packing->date);
        $this->formError = '';
        $this->resetErrorBag();
        unset($this->lines);

        // Goods are stored by DESCRIPTION; the form works by order line, so
        // each lot is matched back to the line it describes.
        $byDescription = collect($this->lines)->keyBy('fullname');

        $this->containers = [];
        foreach ($packing->containers ?? [] as $container) {
            $goods = collect($this->lines)->mapWithKeys(fn ($l) => [$l['order_item_id'] => ['rolls' => '', 'weight' => '']])->all();

            foreach ($container['goods'] ?? [] as $lot) {
                $line = $byDescription->get($lot['description'] ?? '');

                if ($line) {
                    $goods[$line['order_item_id']] = [
                        'rolls' => (string) ($lot['rolls'] ?? ''),
                        'weight' => (string) ($lot['weight'] ?? ''),
                    ];
                }
            }

            $this->containers[] = [
                'name' => (string) ($container['name'] ?? ''),
                'tare' => (string) ($container['weight'] ?? BplPackingList::DEFAULT_TARE),
                'goods' => $goods,
            ];
        }

        if ($this->containers === []) {
            $this->containers[] = $this->blankContainer();
        }

        $this->mode = 'form';
    }

    public function save(): void
    {
        $this->formError = '';

        $this->validate([
            'orderId' => ['required', 'integer'],
            'dateIso' => ['required', 'date'],
        ], [], ['orderId' => 'order', 'dateIso' => 'date']);

        $lines = collect($this->lines)->keyBy('order_item_id');

        if ($lines->isEmpty()) {
            $this->formError = 'That order has no priced lines.';

            return;
        }

        $names = [];
        $payload = [];
        $loaded = [];

        foreach ($this->containers as $index => $container) {
            $name = strtoupper(trim((string) ($container['name'] ?? '')));
            $tare = (float) ($container['tare'] ?? 0);

            if ($name === '') {
                $this->addError('containers.' . $index . '.name', 'Container ' . ($index + 1) . ' needs its number.');

                return;
            }

            if (isset($names[$name])) {
                $this->addError('containers.' . $index . '.name', 'Container ' . ($index + 1) . ' repeats ' . $names[$name] . '.');

                return;
            }
            $names[$name] = 'container ' . ($index + 1);

            if ($tare <= 0) {
                $this->addError('containers.' . $index . '.tare', 'Container ' . ($index + 1) . ' needs its empty weight.');

                return;
            }

            $goods = [];
            foreach ($container['goods'] ?? [] as $itemId => $entry) {
                $rolls = (int) ($entry['rolls'] ?? 0);
                $weight = (float) ($entry['weight'] ?? 0);

                if ($rolls === 0 && $weight === 0.0) {
                    continue;
                }

                if ($rolls <= 0 || $weight <= 0) {
                    $this->addError('containers.' . $index . '.goods.' . $itemId . '.weight',
                        'Rolls and weight go together — fill both or neither.');

                    return;
                }

                $line = $lines->get((int) $itemId);
                if (! $line) {
                    continue;
                }

                $goods[] = ['description' => $line['fullname'], 'rolls' => $rolls, 'weight' => round($weight, 2)];
                $loaded[(int) $itemId] = ($loaded[(int) $itemId] ?? 0) + $weight;
            }

            if ($goods === []) {
                $this->addError('containers.' . $index . '.name', 'Container ' . ($index + 1) . ' is empty.');

                return;
            }

            $payload[] = ['id' => 'container_' . ($index + 1), 'name' => $name, 'weight' => (string) $tare, 'goods' => $goods];
        }

        // Nothing may ship that was not ordered: the legacy checked only that
        // each line had SOMETHING on it.
        foreach ($lines as $itemId => $line) {
            $shipped = $loaded[$itemId] ?? 0;

            if ($shipped <= 0) {
                $this->formError = 'Nothing is loaded against "' . $line['fullname'] . '" — every line on the order has to ship.';

                return;
            }

            if ($shipped > $line['weight']) {
                $this->formError = 'Loaded ' . number_format($shipped, 2) . ' kg of "' . $line['fullname']
                    . '", which is more than the ' . number_format($line['weight'], 2) . ' kg ordered.';

                return;
            }
        }

        $number = $this->editingId
            ? BplPackingList::find($this->editingId)?->number
            : PackingListSupport::numberFor($this->orderId);

        if (! $number) {
            $this->formError = 'That order cannot be numbered — its customer has no label or country. Fix it under BPL → Sales → Customers.';

            return;
        }

        $data = [
            'order_id' => $this->orderId,
            'number' => $number,
            'containers' => $payload,
            'date' => str_replace('-', '/', $this->dateIso),
        ];

        if ($this->editingId) {
            if (PackingListSupport::hasBillOfLading($this->editingId)) {
                $this->formError = 'A bill of lading has been issued for this packing list — it can no longer be changed.';

                return;
            }

            BplPackingList::whereKey($this->editingId)->update([
                'containers' => json_encode($payload),
                'date' => $data['date'],
            ]);
            $id = $this->editingId;
        } else {
            if (BplPackingList::where('order_id', $this->orderId)->exists()) {
                $this->formError = 'That order already has a packing list.';

                return;
            }

            $id = BplPackingList::create($data + ['split' => '0'])->id;
        }

        session()->flash('ok', 'Packing list ' . PackingListSupport::label(
            $number,
            (int) DB::connection('bpl')->table('bpl_sales')->where('id', $this->orderId)->value('customerid'),
            DB::connection('bpl')->table('bpl_sales as s')->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
                ->where('s.id', $this->orderId)->value('c.customerlabel')
        ) . ' saved.');
        session()->flash('printId', $id);

        $this->startNew();
        $this->showList();
    }

    public function deletePacking(int $id): void
    {
        if (! $this->mayDo('delete')) {
            return;
        }

        $packing = BplPackingList::find($id);
        if (! $packing) {
            return;
        }

        if (PackingListSupport::hasBillOfLading($id)) {
            session()->flash('err', 'A bill of lading has been issued — this packing list stays.');

            return;
        }

        // A parent still carrying partial shipments would orphan them.
        if (! $packing->isPartial() && BplPackingList::where('order_id', $packing->order_id)->count() > 1) {
            session()->flash('err', 'Delete this packing list\'s partial shipments first.');

            return;
        }

        $orderId = (int) $packing->order_id;
        $packing->delete();
        PackingListSupport::resetSplit($orderId);

        unset($this->rows, $this->splitOrders, $this->shipped);
        session()->flash('ok', 'Packing list deleted.');
    }

    /* ---------------- Partial shipments ---------------- */

    public function openSplit(int $id): void
    {
        if (! $this->mayDo('edit')) {
            return;
        }

        $packing = BplPackingList::find($id);
        $this->splitError = '';

        if (! $packing || $packing->isPartial()) {
            return;
        }

        if ($packing->containerCount() < 2) {
            session()->flash('err', 'A single-container packing list cannot be split.');

            return;
        }

        if (BplPackingList::where('order_id', $packing->order_id)->count() > 1) {
            session()->flash('err', 'This packing list has already been split.');

            return;
        }

        $this->splittingId = $id;
        $this->splitCount = 2;
        $this->splitAssignment = collect($packing->containers)->keys()->mapWithKeys(fn ($i) => [$i => 1])->all();
    }

    public function closeSplit(): void
    {
        $this->splittingId = null;
        $this->splitAssignment = [];
        $this->splitError = '';
    }

    #[Computed]
    public function splitting(): ?BplPackingList
    {
        return $this->splittingId ? BplPackingList::find($this->splittingId) : null;
    }

    public function saveSplit(): void
    {
        $packing = $this->splitting;

        if (! $packing || ! $this->mayDo('edit')) {
            return;
        }

        $count = max(2, min(12, (int) $this->splitCount));
        $shipments = [];

        foreach ($packing->containers as $index => $container) {
            $shipment = (int) ($this->splitAssignment[$index] ?? 1);
            $shipment = max(1, min($count, $shipment));
            $shipments[$shipment][] = $container;
        }

        if (count($shipments) < 2) {
            $this->splitError = 'Put the containers into at least two shipments.';

            return;
        }

        ksort($shipments);
        PackingListSupport::split($packing, array_values($shipments));

        $this->closeSplit();
        unset($this->rows, $this->splitOrders, $this->shipped);
        session()->flash('ok', 'Split into ' . count($shipments) . ' partial shipments.');
    }

    public function render()
    {
        return view('bpl::livewire.sales.packing-lists');
    }
}
