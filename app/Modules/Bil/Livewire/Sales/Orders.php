<?php

namespace Modules\Bil\Livewire\Sales;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Bil\Models\SalesCustomer;
use Modules\Bil\Models\SalesOrder;
use Modules\Bil\Models\SalesOrderDetail;
use Modules\Core\Models\User;
use Modules\Core\Models\Warehouse;

/**
 * BIL → Sales → Orders. Rebuild of the legacy `sales_order.php` (whose whole UI
 * lived in `js/sales_order.js`) and its `Bil\Sales\Order` handler.
 *
 * An order is a header plus product lines, both written to the legacy tables
 * unchanged, because every downstream sales screen — Loading, Delivery, Waybill,
 * the balance and invoice reports — still reads them. See SalesOrder for the
 * column contracts that must not drift.
 *
 * WHAT CHANGED FROM LEGACY, deliberately:
 *
 *  - **Order Location comes from the core warehouse model**, not from
 *    `bil.sales_warehouse`. The three depots are registered as finished-goods
 *    warehouses under Belimpex carrying their legacy code, so gds still writes
 *    '01'/'02'/'03' and legacy's L/K/A load barcode keeps working.
 *  - **Rows are added N at a time.** Legacy opened with 8 fixed empty rows and a
 *    "+" that added exactly one, and only when no empty row was left. Here the
 *    form opens with one row and the operator says how many more to add.
 *  - **A delivered line is protected.** Legacy's modify pass deleted every
 *    detail id missing from the submitted form and updated the rest with no
 *    checks, so clearing a line that had already been loaded orphaned the
 *    `sales_loading` rows pointing at its `sod_id`, and an order could be
 *    revised below what had physically gone out. Both are refused here.
 */
#[Layout('core::layouts.admin')]
#[Title('Sales Orders')]
class Orders extends Component
{
    public const PAGE_KEY = 'bil.sales.orders';

    /** Rows the operator may add in one go, and the size of one order. */
    public const MAX_ADD_ROWS = 50;
    public const MAX_ROWS = 200;

    /** 'form' = placing or editing an order, 'list' = finding one to edit. */
    public string $mode = 'form';

    /* ---------------- Header ---------------- */

    /** `sales_order.id` being edited, null when placing a new order. */
    public ?int $editingId = null;

    /** The order number as it stands in the DB — the key rows join on. */
    public string $originalNumber = '';

    public string $number = '';
    public string $username = '';
    public ?int $warehouseId = null;
    public ?int $customerid = null;
    public string $dateIso = '';

    /* ---------------- Lines ---------------- */

    /**
     * uid => ['item' => ?int, 'productid' => ?int, 'quantity' => ?int, 'foc' => bool]
     *
     * Keyed by a running uid rather than by position, so removing a line does
     * not renumber the rest. The wire paths and wire:keys of every other row
     * stay put, which is what keeps each row's product combobox showing the
     * product it actually holds.
     */
    public array $rows = [];
    public int $nextRowId = 1;

    /** How many blank lines the "Add" button appends. Legacy always added one. */
    public int $addCount = 1;

    /* ---------------- Finding an order ---------------- */

    public string $listDateIso = '';
    public string $listSearch = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->listDateIso = $this->dateIso;
        $this->username = (string) (auth()->user()->username ?? '');
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

    /**
     * Depots an order may be placed against.
     *
     * Only warehouses carrying a legacy sales code, because that code is what
     * goes into `sales_order.warehousecode`. Inactive ones (Kano) are hidden,
     * except the one an order being edited already sits on — an old Kano order
     * must still show its own location rather than silently re-homing itself.
     */
    #[Computed]
    public function warehouses()
    {
        return Warehouse::query()
            ->salesDepot()
            ->where(fn ($q) => $q->where('is_active', true)
                ->when($this->warehouseId, fn ($w) => $w->orWhere('id', $this->warehouseId)))
            ->ordered()
            ->get();
    }

    #[Computed]
    public function customers()
    {
        return SalesCustomer::query()->ordered()->get(['id', 'customercode', 'customername']);
    }

    #[Computed]
    public function products()
    {
        return FinishedGoodsProduct::query()->active()
            ->orderBy('productname')
            ->get(['productid', 'productcode', 'productname']);
    }

    /** productid => productcode, for the read-only Product Code column. */
    #[Computed]
    public function productCodes(): array
    {
        return $this->products->pluck('productcode', 'productid')->all();
    }

    /**
     * Who the order may be recorded under.
     *
     * Legacy offered every userlevel-12 account to an admin and locked everyone
     * else to themselves. Same rule, but the list is "users who may open this
     * page" rather than a hard-coded level — so granting the page is all it
     * takes to appear here.
     */
    #[Computed]
    public function orderUsers(): array
    {
        $me = auth()->user();
        $mine = (string) ($me->username ?? '');

        if (! $me?->isAdmin()) {
            return array_values(array_filter([$mine]));
        }

        try {
            $names = User::query()
                ->permission(self::PAGE_KEY . ':view')
                ->orderBy('username')
                ->pluck('username')
                ->all();
        } catch (\Throwable) {
            // The permission row does not exist yet (gds:sync-pages not run).
            // Better to offer just the admin than to blank the screen.
            $names = [];
        }

        // Admins are not granted the permission row by row, and an order being
        // edited may name someone who has since lost the page — keep both.
        return collect($names)->push($mine)->push($this->username)
            ->filter()->unique()->sort()->values()->all();
    }

    /* ---------------- Row editing ---------------- */

    public function startNew(): void
    {
        $this->editingId = null;
        $this->originalNumber = '';
        $this->number = '';
        $this->warehouseId = null;
        $this->customerid = null;
        $this->dateIso = now()->format('Y-m-d');
        $this->username = (string) (auth()->user()->username ?? '');
        $this->rows = [];
        $this->nextRowId = 1;
        $this->addCount = 1;
        $this->mode = 'form';
        $this->resetErrorBag();
        $this->appendRows(1);
        unset($this->loadedByDetail);
    }

    /** Add the requested number of blank lines. */
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
            $this->rows[$this->nextRowId++] = [
                'item' => null,
                'productid' => null,
                'quantity' => null,
                'foc' => false,
            ];
        }
    }

    public function removeRow(int $uid): void
    {
        $row = $this->rows[$uid] ?? null;
        if (! $row) {
            return;
        }

        if ($this->loadedFor($row) > 0) {
            session()->flash('err', 'That line has already been loaded — it cannot be removed.');

            return;
        }

        unset($this->rows[$uid]);

        // Never leave the operator with no line to type into.
        if ($this->rows === []) {
            $this->appendRows(1);
        }
    }

    /* ---------------- What has already gone out ---------------- */

    /** detail id => quantity already loaded, for the order being edited. */
    #[Computed]
    public function loadedByDetail(): array
    {
        return $this->originalNumber === ''
            ? []
            : SalesOrderDetail::loadedQuantities($this->originalNumber);
    }

    /** Quantity already loaded against a row (0 for a new line). */
    public function loadedFor(array $row): int
    {
        return $row['item'] ? ($this->loadedByDetail[$row['item']] ?? 0) : 0;
    }

    /** Once anything has been loaded, the header is fixed. */
    #[Computed]
    public function orderLocked(): bool
    {
        return $this->loadedByDetail !== [];
    }

    /* ---------------- Saving ---------------- */

    public function save(): void
    {
        $this->number = strtoupper(trim($this->number));

        // A non-backdater's order is dated today, whatever the field says.
        if (! $this->canBackdate()) {
            $this->dateIso = now()->format('Y-m-d');
        }

        $this->validate([
            'number' => ['required', 'string', 'max:20'],
            'username' => ['required', 'string', 'max:200'],
            'warehouseId' => ['required', 'integer'],
            'customerid' => ['required', 'integer'],
            'dateIso' => ['required', 'date', 'before_or_equal:today'],
        ], [], [
            'number' => 'order number',
            'warehouseId' => 'order location',
            'customerid' => 'customer',
            'dateIso' => 'date of order',
        ]);

        $depot = Warehouse::query()->salesDepot()->find($this->warehouseId);
        if (! $depot) {
            $this->addError('warehouseId', 'That location is not a sales depot.');

            return;
        }

        if (! SalesCustomer::whereKey($this->customerid)->exists()) {
            $this->addError('customerid', 'That customer no longer exists.');

            return;
        }

        // Non-admins may only book under their own name — the select offers
        // nothing else, and the server does not trust the select.
        $me = auth()->user();
        if (! $me?->isAdmin()) {
            $this->username = (string) ($me->username ?? '');
        }

        $lines = $this->collectLines();
        if ($lines === null) {
            return;
        }

        // Order numbers are hand-typed and UNIQUE in the table.
        $clash = SalesOrder::where('orderid', $this->number)
            ->when($this->editingId, fn ($q) => $q->where('id', '<>', $this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('number', 'Sales order ' . $this->number . ' already exists.');

            return;
        }

        $this->editingId ? $this->updateOrder($lines) : $this->createOrder($lines);
    }

    /**
     * Turn the row grid into the lines to write, or null after adding errors.
     *
     * A row with no product is simply not a line — legacy's `.filled` rule, so
     * spare rows can be left on the form. A row with a quantity but no product
     * is a mistake, not a spare, and is called out.
     */
    protected function collectLines(): ?array
    {
        $lines = [];
        $seen = [];
        $position = 0;
        $ok = true;

        foreach ($this->rows as $uid => $row) {
            $position++;
            $productid = $row['productid'] !== null && $row['productid'] !== '' ? (int) $row['productid'] : null;
            $qtyRaw = $row['quantity'];
            $qty = ($qtyRaw === null || $qtyRaw === '') ? null : (int) $qtyRaw;
            $foc = ! empty($row['foc']) ? 1 : 0;
            $loaded = $this->loadedFor($row);

            if ($productid === null) {
                if ($qty !== null || $row['item']) {
                    $this->addError('rows.' . $uid . '.productid', 'Row ' . $position . ' needs a product.');
                    $ok = false;
                }

                continue;
            }

            if (! isset($this->productCodes[$productid])) {
                $this->addError('rows.' . $uid . '.productid', 'Row ' . $position . ': that product is not in the finished-goods master.');
                $ok = false;

                continue;
            }

            if ($qty === null || $qty < 1) {
                $this->addError('rows.' . $uid . '.quantity', 'Row ' . $position . ' needs a quantity of at least 1.');
                $ok = false;

                continue;
            }

            if ($loaded > 0 && $qty < $loaded) {
                $this->addError('rows.' . $uid . '.quantity',
                    'Row ' . $position . ': ' . $loaded . ' already loaded — the order cannot be cut below that.');
                $ok = false;

                continue;
            }

            // (product, foc) is the line's identity: the same product may appear
            // once charged and once free, but not twice the same way.
            $key = $productid . '|' . $foc;
            if (isset($seen[$key])) {
                $this->addError('rows.' . $uid . '.productid',
                    'Row ' . $position . ' repeats row ' . $seen[$key] . ' — same product and FOC setting.');
                $ok = false;

                continue;
            }
            $seen[$key] = $position;

            $lines[] = [
                'item' => $row['item'] ? (int) $row['item'] : null,
                'productid' => $productid,
                'quantityordered' => $qty,
                'foc' => $foc,
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

    protected function createOrder(array $lines): void
    {
        $depotCode = Warehouse::find($this->warehouseId)?->legacy_sales_code;

        DB::connection('bil')->transaction(function () use ($lines, $depotCode) {
            SalesOrder::create([
                'username' => $this->username,
                'orderid' => $this->number,
                'warehousecode' => $depotCode,
                'customerid' => (int) $this->customerid,
                'dateoforder' => SalesOrder::toLegacyDate($this->dateIso),
                'timestamp' => time(),
            ]);

            foreach ($lines as $line) {
                SalesOrderDetail::create([
                    'orderid' => $this->number,
                    'productid' => $line['productid'],
                    'quantityordered' => $line['quantityordered'],
                    'foc' => $line['foc'],
                ]);
            }
        });

        $n = count($lines);
        session()->flash('ok', 'Sales order ' . $this->number . ' placed — ' . $n . ' ' . ($n === 1 ? 'line' : 'lines') . '.');
        $this->startNew();
    }

    protected function updateOrder(array $lines): void
    {
        $order = SalesOrder::find($this->editingId);
        if (! $order) {
            session()->flash('err', 'That order no longer exists.');
            $this->startNew();

            return;
        }

        $existing = SalesOrderDetail::where('orderid', $this->originalNumber)->pluck('id')->all();
        $kept = array_values(array_filter(array_column($lines, 'item')));
        $dropped = array_values(array_diff($existing, $kept));

        // Re-read the loadings rather than trusting the computed snapshot: the
        // form may have been open a while, and a loading made in between must
        // not be orphaned by this save.
        $loaded = SalesOrderDetail::loadedQuantities($this->originalNumber);

        foreach ($dropped as $id) {
            if (($loaded[$id] ?? 0) > 0) {
                session()->flash('err', 'A line on this order has been loaded since you opened it — reload the order before saving.');

                return;
            }
        }

        foreach ($lines as $line) {
            if ($line['item'] && ($loaded[$line['item']] ?? 0) > $line['quantityordered']) {
                session()->flash('err', 'A line has been loaded beyond its new quantity since you opened this order — reload it before saving.');

                return;
            }
        }

        $depotCode = Warehouse::find($this->warehouseId)?->legacy_sales_code;
        $number = $this->number;
        $original = $this->originalNumber;

        DB::connection('bil')->transaction(function () use ($order, $lines, $dropped, $depotCode, $number, $original) {
            $order->update([
                'username' => $this->username,
                'orderid' => $number,
                'warehousecode' => $depotCode,
                'customerid' => (int) $this->customerid,
                'dateoforder' => SalesOrder::toLegacyDate($this->dateIso),
            ]);

            if ($dropped !== []) {
                SalesOrderDetail::whereIn('id', $dropped)->delete();
            }

            foreach ($lines as $line) {
                if ($line['item']) {
                    SalesOrderDetail::whereKey($line['item'])->update([
                        'orderid' => $number,
                        'productid' => $line['productid'],
                        'quantityordered' => $line['quantityordered'],
                        'foc' => $line['foc'],
                    ]);
                } else {
                    SalesOrderDetail::create([
                        'orderid' => $number,
                        'productid' => $line['productid'],
                        'quantityordered' => $line['quantityordered'],
                        'foc' => $line['foc'],
                    ]);
                }
            }

            // The order number is the join key; a rename has to carry any line
            // the loop above did not already touch.
            if ($number !== $original) {
                SalesOrderDetail::where('orderid', $original)->update(['orderid' => $number]);
            }
        });

        session()->flash('ok', 'Sales order ' . $number . ' updated.');
        $this->startNew();
    }

    /* ---------------- Finding, editing and removing orders ---------------- */

    public function showList(): void
    {
        $this->mode = 'list';
        $this->resetErrorBag();
    }

    public function showForm(): void
    {
        $this->mode = 'form';
    }

    #[Computed]
    public function orders()
    {
        $term = trim($this->listSearch);

        return SalesOrder::query()
            ->from('sales_order as so')
            ->leftJoin('sales_customers as c', 'so.customerid', '=', 'c.id')
            ->when($this->listDateIso !== '', fn ($q) => $q->where('so.dateoforder', SalesOrder::toLegacyDate($this->listDateIso)))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('so.orderid', 'like', '%' . $term . '%')
                ->orWhere('c.customername', 'like', '%' . $term . '%')))
            ->orderByDesc('so.id')
            ->limit(200)
            ->get(['so.id', 'so.orderid', 'so.username', 'so.warehousecode', 'so.dateoforder',
                   'c.customername']);
    }

    /** legacy sales code => warehouse name, for the list's Location column. */
    #[Computed]
    public function depotNames(): array
    {
        return Warehouse::query()->salesDepot()->pluck('name', 'legacy_sales_code')->all();
    }

    /** Order numbers in the current list that already have a loading. */
    #[Computed]
    public function listLoaded(): array
    {
        $numbers = $this->orders->pluck('orderid')->all();
        if ($numbers === []) {
            return [];
        }

        return DB::connection('bil')->table('sales_loading as l')
            ->join('sales_order_details as sod', 'l.sod_id', '=', 'sod.id')
            ->whereIn('sod.orderid', $numbers)
            ->distinct()
            ->pluck('sod.orderid')
            ->all();
    }

    public function editOrder(int $id): void
    {
        $order = SalesOrder::find($id);
        if (! $order) {
            session()->flash('err', 'That order no longer exists.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $order->id;
        $this->originalNumber = (string) $order->orderid;
        $this->number = (string) $order->orderid;
        $this->username = (string) $order->username;
        $this->customerid = (int) $order->customerid;
        $this->dateIso = SalesOrder::fromLegacyDate($order->dateoforder);
        $this->warehouseId = Warehouse::query()->salesDepot()
            ->where('legacy_sales_code', $order->warehousecode)->value('id');

        $this->rows = [];
        $this->nextRowId = 1;
        foreach (SalesOrderDetail::where('orderid', $order->orderid)->orderBy('id')->get() as $d) {
            $this->rows[$this->nextRowId++] = [
                'item' => (int) $d->id,
                'productid' => (int) $d->productid,
                'quantity' => (int) $d->quantityordered,
                'foc' => (bool) $d->foc,
            ];
        }
        if ($this->rows === []) {
            $this->appendRows(1);
        }

        unset($this->loadedByDetail, $this->warehouses);
        $this->mode = 'form';
    }

    public function deleteOrder(int $id): void
    {
        if (! $this->canDelete()) {
            session()->flash('err', 'You do not have permission to delete a sales order.');

            return;
        }

        $order = SalesOrder::find($id);
        if (! $order) {
            return;
        }

        if (SalesOrderDetail::loadedQuantities($order->orderid) !== []) {
            session()->flash('err', 'Unable to delete — a delivery has been made against order ' . $order->orderid . '.');

            return;
        }

        $number = $order->orderid;

        DB::connection('bil')->transaction(function () use ($order, $number) {
            SalesOrderDetail::where('orderid', $number)->delete();
            $order->delete();
        });

        if ($this->editingId === $id) {
            $this->startNew();
            $this->mode = 'list';
        }

        unset($this->orders, $this->listLoaded);
        session()->flash('ok', 'Sales order ' . $number . ' deleted.');
    }

    /* ---------------- Render ---------------- */

    public function render()
    {
        return view('bil::livewire.sales.orders', [
            'today' => Carbon::now()->format('Y-m-d'),
        ]);
    }
}
