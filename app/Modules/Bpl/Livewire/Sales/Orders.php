<?php

namespace Modules\Bpl\Livewire\Sales;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplCustomer;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplSalesOrder;
use Modules\Bpl\Models\BplSalesOrderItem;
use Modules\Bpl\Support\SalesOrderNumber;
use Modules\Core\Models\User;

/**
 * BPL → Sales → Orders. Rebuild of legacy `bpl_sales.php` (UI in
 * `js/bpl/sales/*`, handler `Bil\Bpl\Sales`), built the way BIL → Sales →
 * Orders is: a New order form as the default view, and a Sales order list of
 * the orders already placed.
 *
 * WHAT DIFFERS FROM BIL
 *
 *  - **The order number is generated, not typed.** BIL's clerk types the order
 *    number; BPL's is `BPL/{ISO}/{label}/{ref}`, with `ref` the customer's next
 *    sequence number — both allocated when the order is saved and shown
 *    read-only. See Modules\Bpl\Support\SalesOrderNumber.
 *  - **Lines are weight, not quantity.** BPL sells rolls by the kilogram.
 *  - **No FOC**, and a product may appear once per order.
 *  - **What a change could break is a proforma or a packing list**, not a
 *    loading. An order with either keeps its customer and company (they are
 *    in its number, and on the document), and cannot be deleted. A line a
 *    proforma has priced cannot be removed or swapped for another product.
 *
 * The product list (4,387 hardroll products, ~243 KB) is NOT part of this
 * component's render: it is fetched once from `products()` by the page's
 * Alpine scope. Embedded, it would travel back and forth on every row added,
 * removed or saved.
 */
#[Layout('core::layouts.admin')]
#[Title('BPL Sales Orders')]
class Orders extends Component
{
    public const PAGE_KEY = 'bpl.sales.orders';

    public const MAX_ADD_ROWS = 50;
    public const MAX_ROWS = 200;

    /** 'form' = placing or editing (the default), 'list' = orders already placed. */
    public string $mode = 'form';

    /* ---------------- Header ---------------- */

    public ?int $editingId = null;

    /** The customer the order was saved under — a change re-numbers it. */
    public ?int $originalCustomerId = null;

    /** Stored ref and number of the order being edited ('' for a new one). */
    public string $ref = '';
    public string $orderno = '';

    public string $username = '';
    public string $company = 'Belpapyrus';
    public ?int $customerid = null;
    public string $dateIso = '';

    /* ---------------- Lines ---------------- */

    /** uid => ['item' => ?int, 'productid' => ?int, 'weight' => ?string]; keyed by uid, never position. */
    public array $rows = [];
    public int $nextRowId = 1;
    public int $addCount = 1;

    /* ---------------- The list ---------------- */

    public string $listDateIso = '';
    public string $listSearch = '';

    public function mount(): void
    {
        $this->startNew();
    }

    /* ---------------- Permissions ---------------- */

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function canDelete(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'delete');
    }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function customers()
    {
        return BplCustomer::query()->orderBy('customername')->get(['id', 'customername', 'customerlabel']);
    }

    public function companies(): array
    {
        return BplSalesOrder::COMPANIES;
    }

    /** Same rule as BIL: an admin may book under anyone holding the page; everyone else under themselves. */
    #[Computed]
    public function orderUsers(): array
    {
        $me = auth()->user();
        $mine = (string) ($me->username ?? '');

        if (! $me?->isAdmin()) {
            return array_values(array_filter([$mine]));
        }

        try {
            $names = User::query()->permission(self::PAGE_KEY . ':view')->orderBy('username')->pluck('username')->all();
        } catch (\Throwable) {
            $names = [];
        }

        return collect($names)->push($mine)->push($this->username)->filter()->unique()->sort()->values()->all();
    }

    /**
     * The product list for the page's Alpine scope, fetched ONCE — see the
     * class note. `grade` rides along for the read-only Grade column.
     */
    public static function productOptions(): array
    {
        return BplProductHardroll::query()
            ->orderBy('productname')
            ->get(['id', 'productname', 'gradetype'])
            ->map(fn ($p) => ['value' => (string) $p->id, 'label' => (string) $p->productname, 'grade' => (string) $p->gradetype])
            ->all();
    }

    /* ---------------- The number ---------------- */

    /**
     * The order number the form shows.
     *
     * Editing an order under its original customer: its stored number, which
     * never changes. A new order, or a customer change on an unlocked one: a
     * preview of the number it WILL get — the ref is only allocated on save,
     * inside the transaction, so another clerk placing an order for the same
     * customer first can move it on by one.
     */
    #[Computed]
    public function numberPreview(): array
    {
        if ($this->editingId && $this->customerid === $this->originalCustomerId && $this->orderno !== '') {
            return ['number' => $this->orderno, 'final' => true, 'problem' => null];
        }

        $customer = $this->customerid ? BplCustomer::find($this->customerid) : null;
        if (! $customer) {
            return ['number' => null, 'final' => false, 'problem' => null];
        }

        if ($problem = SalesOrderNumber::problemWith($customer)) {
            return ['number' => null, 'final' => false, 'problem' => $problem];
        }

        $max = DB::connection('bpl')->table('bpl_sales')->where('customerid', $customer->id)
            ->max(DB::connection('bpl')->raw('CAST(`ref` AS UNSIGNED)'));
        $ref = str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);

        return ['number' => SalesOrderNumber::compose($customer, $ref), 'final' => false, 'problem' => null];
    }

    public function updatedCustomerid(): void
    {
        unset($this->numberPreview);
    }

    /* ---------------- Rows ---------------- */

    public function startNew(): void
    {
        $this->editingId = null;
        $this->originalCustomerId = null;
        $this->ref = '';
        $this->orderno = '';
        $this->company = 'Belpapyrus';
        $this->customerid = null;
        $this->dateIso = now()->format('Y-m-d');
        $this->username = (string) (auth()->user()->username ?? '');
        $this->rows = [];
        $this->nextRowId = 1;
        $this->addCount = 1;
        $this->mode = 'form';
        $this->resetErrorBag();
        $this->appendRows(1);
        unset($this->downstream, $this->pricedLines, $this->numberPreview);
    }

    public function addRows(): void
    {
        $n = (int) $this->addCount;

        if ($n < 1 || $n > self::MAX_ADD_ROWS) {
            $this->addError('addCount', 'Add between 1 and ' . self::MAX_ADD_ROWS . ' rows at a time.');

            return;
        }

        $room = self::MAX_ROWS - count($this->rows);
        if ($room <= 0) {
            $this->addError('addCount', 'An order can hold at most ' . self::MAX_ROWS . ' lines.');

            return;
        }

        $this->resetErrorBag('addCount');
        $this->appendRows(min($n, $room));
    }

    protected function appendRows(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->rows[$this->nextRowId++] = ['item' => null, 'productid' => null, 'weight' => null];
        }
    }

    public function removeRow(int $uid): void
    {
        $row = $this->rows[$uid] ?? null;
        if (! $row) {
            return;
        }

        if ($this->rowPriced($row)) {
            session()->flash('err', 'That line is priced on a proforma — it cannot be removed.');

            return;
        }

        unset($this->rows[$uid]);

        if ($this->rows === []) {
            $this->appendRows(1);
        }
    }

    /* ---------------- What a change could break ---------------- */

    /** ['proforma' => bool, 'packing' => bool] for the order being edited. */
    #[Computed]
    public function downstream(): array
    {
        return $this->editingId ? BplSalesOrder::downstreamOf($this->editingId) : ['proforma' => false, 'packing' => false];
    }

    /** Once a proforma or packing list exists, customer and company are fixed. */
    #[Computed]
    public function orderLocked(): bool
    {
        return $this->downstream['proforma'] || $this->downstream['packing'];
    }

    #[Computed]
    public function pricedLines(): array
    {
        return $this->editingId ? BplSalesOrderItem::pricedLines($this->editingId) : [];
    }

    public function rowPriced(array $row): bool
    {
        return $row['item'] !== null && isset($this->pricedLines[(int) $row['item']]);
    }

    /* ---------------- Saving ---------------- */

    public function save(): void
    {
        if (! $this->canBackdate()) {
            $this->dateIso = now()->format('Y-m-d');
        }

        $this->validate([
            'username' => ['required', 'string', 'max:50'],
            'company' => ['required', 'in:' . implode(',', BplSalesOrder::COMPANIES)],
            'customerid' => ['required', 'integer'],
            'dateIso' => ['required', 'date', 'before_or_equal:today'],
        ], [], [
            'customerid' => 'customer',
            'dateIso' => 'date of order',
        ]);

        $customer = BplCustomer::find($this->customerid);
        if (! $customer) {
            $this->addError('customerid', 'That customer no longer exists.');

            return;
        }

        $renumber = ! $this->editingId || $this->customerid !== $this->originalCustomerId;

        if ($renumber && ($problem = SalesOrderNumber::problemWith($customer))) {
            $this->addError('customerid', $problem);

            return;
        }

        $me = auth()->user();
        if (! $me?->isAdmin()) {
            $this->username = (string) ($me->username ?? '');
        }

        $lines = $this->collectLines();
        if ($lines === null) {
            return;
        }

        $this->editingId ? $this->updateOrder($customer, $lines, $renumber) : $this->createOrder($customer, $lines);
    }

    /** The row grid as lines to write, or null after adding errors. A row with no product is a spare. */
    protected function collectLines(): ?array
    {
        $lines = [];
        $seen = [];
        $position = 0;
        $ok = true;

        foreach ($this->rows as $uid => $row) {
            $position++;
            $productid = ($row['productid'] ?? null) !== null && $row['productid'] !== '' ? (int) $row['productid'] : null;
            $raw = $row['weight'] ?? null;
            $weight = ($raw === null || $raw === '') ? null : (float) $raw;

            if ($productid === null) {
                if ($weight !== null || $row['item']) {
                    $this->addError('rows.' . $uid . '.productid', 'Row ' . $position . ' needs a product.');
                    $ok = false;
                }

                continue;
            }

            if (! BplProductHardroll::whereKey($productid)->exists()) {
                $this->addError('rows.' . $uid . '.productid', 'Row ' . $position . ': that product is not in the hardroll catalog.');
                $ok = false;

                continue;
            }

            if ($weight === null || $weight <= 0) {
                $this->addError('rows.' . $uid . '.weight', 'Row ' . $position . ' needs a weight above zero.');
                $ok = false;

                continue;
            }

            if (isset($seen[$productid])) {
                $this->addError('rows.' . $uid . '.productid', 'Row ' . $position . ' repeats row ' . $seen[$productid] . ' — same product.');
                $ok = false;

                continue;
            }
            $seen[$productid] = $position;

            $lines[] = [
                'item' => $row['item'] ? (int) $row['item'] : null,
                'productid' => $productid,
                'weight' => round($weight, 2),
            ];
        }

        if (! $ok) {
            return null;
        }

        if ($lines === []) {
            session()->flash('err', 'Add at least one product before placing the order.');

            return null;
        }

        return $lines;
    }

    /**
     * Allocate the ref and write the order, retrying once on the (ref,
     * customerid) unique index — two clerks placing an order for the same
     * customer at the same moment is the one way `nextRef()` can be beaten.
     */
    protected function createOrder(BplCustomer $customer, array $lines): void
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $order = DB::connection('bpl')->transaction(function () use ($customer, $lines) {
                    $ref = SalesOrderNumber::nextRef($customer->id);

                    $order = BplSalesOrder::create([
                        'ref' => $ref,
                        'orderno' => SalesOrderNumber::compose($customer, $ref),
                        'username' => $this->username,
                        'customerid' => $customer->id,
                        'company' => $this->company,
                        'date' => BplSalesOrder::toLegacyDate($this->dateIso),
                    ]);

                    foreach ($lines as $line) {
                        BplSalesOrderItem::create([
                            'order_id' => $order->id,
                            'productid' => $line['productid'],
                            'weight' => $line['weight'],
                        ]);
                    }

                    return $order;
                });

                $n = count($lines);
                session()->flash('ok', 'Sales order ' . $order->orderno . ' placed — ' . $n . ' ' . ($n === 1 ? 'line' : 'lines') . '.');
                $this->startNew();

                return;
            } catch (QueryException $e) {
                // 1062 = duplicate key: somebody took this ref first. Once more.
                if ($attempt === 2 || ($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
            }
        }
    }

    protected function updateOrder(BplCustomer $customer, array $lines, bool $renumber): void
    {
        $order = BplSalesOrder::find($this->editingId);
        if (! $order) {
            session()->flash('err', 'That order no longer exists.');
            $this->startNew();

            return;
        }

        // Re-read, rather than trusting what the page loaded: a proforma raised
        // while this form was open must not be undone by this save.
        $downstream = BplSalesOrder::downstreamOf($order->id);
        $locked = $downstream['proforma'] || $downstream['packing'];

        if ($locked && ($renumber || $this->company !== $order->company)) {
            session()->flash('err', 'A proforma or packing list has been raised against this order — its customer and company can no longer change. Reload the order.');

            return;
        }

        $priced = BplSalesOrderItem::pricedLines($order->id);
        $existing = BplSalesOrderItem::where('order_id', $order->id)->pluck('productid', 'id')->all();
        $kept = array_values(array_filter(array_column($lines, 'item')));

        foreach (array_diff(array_keys($existing), $kept) as $droppedId) {
            if (isset($priced[$droppedId])) {
                session()->flash('err', 'A line removed here is priced on a proforma — it cannot be removed. Reload the order.');

                return;
            }
        }

        foreach ($lines as $line) {
            if ($line['item'] && isset($priced[$line['item']]) && (int) ($existing[$line['item']] ?? 0) !== $line['productid']) {
                session()->flash('err', 'A line priced on a proforma cannot be changed to a different product.');

                return;
            }
        }

        $dropped = array_values(array_diff(array_keys($existing), $kept));

        DB::connection('bpl')->transaction(function () use ($order, $customer, $lines, $dropped, $renumber) {
            $header = [
                'username' => $this->username,
                'customerid' => $customer->id,
                'company' => $this->company,
                'date' => BplSalesOrder::toLegacyDate($this->dateIso),
            ];

            if ($renumber) {
                $ref = SalesOrderNumber::nextRef($customer->id);
                $header['ref'] = $ref;
                $header['orderno'] = SalesOrderNumber::compose($customer, $ref);
            } elseif (! $order->orderno) {
                // An order placed on the legacy screen after the cut-over has
                // no stored number; give it the one legacy would show.
                $header['orderno'] = SalesOrderNumber::compose($customer, $order->ref);
            }

            $order->update($header);

            if ($dropped !== []) {
                BplSalesOrderItem::whereIn('id', $dropped)->delete();
            }

            foreach ($lines as $line) {
                $data = ['productid' => $line['productid'], 'weight' => $line['weight']];

                $line['item']
                    ? BplSalesOrderItem::whereKey($line['item'])->update($data)
                    : BplSalesOrderItem::create($data + ['order_id' => $order->id]);
            }
        });

        session()->flash('ok', 'Sales order ' . $order->fresh()->orderno . ' updated.');
        $this->startNew();
    }

    /* ---------------- The list ---------------- */

    public function showList(): void
    {
        $this->mode = 'list';
        $this->resetErrorBag();
    }

    public function showForm(): void
    {
        $this->mode = 'form';
    }

    /**
     * Orders already placed. The number falls back to the legacy formula for
     * one placed on the legacy screen (no stored `orderno`), so the list and
     * the legacy screens agree.
     */
    #[Computed]
    public function orders()
    {
        $term = trim($this->listSearch);
        $numberExpr = "COALESCE(s.orderno, REPLACE(CONCAT('BPL/', co.iso, '/', c.customerlabel, '/', s.ref), ' ', ''))";

        return DB::connection('bpl')->table('bpl_sales as s')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_sales_items as i', 'i.order_id', '=', 's.id')
            ->whereNull('s.deleted_at')
            ->when($this->listDateIso !== '', fn ($q) => $q->where('s.date', BplSalesOrder::toLegacyDate($this->listDateIso)))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("{$numberExpr} LIKE ?", ['%' . $term . '%'])
                ->orWhere('c.customername', 'like', '%' . $term . '%')))
            ->groupBy('s.id', 's.ref', 's.orderno', 's.date', 's.company', 's.username', 'c.customername', 'c.customerlabel', 'co.iso')
            ->orderByDesc('s.id')
            ->limit(200)
            ->selectRaw("s.id, {$numberExpr} as number, s.date, s.company, s.username, c.customername,"
                . ' COUNT(i.id) as line_count, ROUND(COALESCE(SUM(i.weight), 0), 2) as total_weight')
            ->get();
    }

    /** Order ids in the list that have a proforma or packing list — not deletable. */
    #[Computed]
    public function listLocked(): array
    {
        $ids = $this->orders->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $bpl = DB::connection('bpl');

        return collect($bpl->table('bpl_proforma')->whereIn('order_id', $ids)->whereNull('deleted_at')->pluck('order_id'))
            ->merge($bpl->table('bpl_packing_list')->whereIn('order_id', $ids)->pluck('order_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function editOrder(int $id): void
    {
        $order = BplSalesOrder::find($id);
        if (! $order) {
            session()->flash('err', 'That order no longer exists.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $order->id;
        $this->originalCustomerId = (int) $order->customerid;
        $this->ref = (string) $order->ref;
        $this->orderno = (string) ($order->orderno ?? '');
        $this->username = (string) $order->username;
        $this->company = in_array($order->company, BplSalesOrder::COMPANIES, true) ? $order->company : 'Belpapyrus';
        $this->customerid = (int) $order->customerid;
        $this->dateIso = BplSalesOrder::fromLegacyDate($order->date);

        $this->rows = [];
        $this->nextRowId = 1;
        foreach (BplSalesOrderItem::where('order_id', $order->id)->orderBy('id')->get() as $item) {
            $this->rows[$this->nextRowId++] = [
                'item' => (int) $item->id,
                'productid' => $item->productid !== null ? (int) $item->productid : null,
                'weight' => (string) $item->weight,
            ];
        }
        if ($this->rows === []) {
            $this->appendRows(1);
        }

        unset($this->downstream, $this->pricedLines, $this->numberPreview);
        $this->mode = 'form';
    }

    /** Soft delete, as the legacy screen did — refused once a proforma or packing list exists. */
    public function deleteOrder(int $id): void
    {
        if (! $this->canDelete()) {
            session()->flash('err', 'You do not have permission to delete a sales order.');

            return;
        }

        $order = BplSalesOrder::find($id);
        if (! $order) {
            return;
        }

        $downstream = BplSalesOrder::downstreamOf($order->id);
        if ($downstream['proforma'] || $downstream['packing']) {
            session()->flash('err', 'Unable to delete — a ' . ($downstream['packing'] ? 'packing list' : 'proforma')
                . ' has been raised against ' . ($order->orderno ?: 'this order') . '.');

            return;
        }

        $order->delete();

        if ($this->editingId === $id) {
            $this->startNew();
            $this->mode = 'list';
        }

        unset($this->orders, $this->listLocked);
        session()->flash('ok', 'Sales order ' . ($order->orderno ?: $order->ref) . ' deleted.');
    }

    public function render()
    {
        return view('bpl::livewire.sales.orders');
    }
}
