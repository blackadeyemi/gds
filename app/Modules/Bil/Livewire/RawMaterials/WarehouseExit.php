<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Models\RawMaterialWarehouseExit;
use Modules\Bil\Support\RawMaterialsStock;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * Raw Materials → Warehouse Exit. Rebuilt from the legacy Store Exit
 * (rawmaterial_store_exit + Bpl\Rawmaterial_StoreExit).
 *
 * Scan in-store barcodes (validated: present in stock and not already exited),
 * then Save marks each item `Exited` in `rawmaterials_warehouse_entry`, records
 * a row in `rawmaterials_warehouse_exit`, and decrements the `rawmaterials_stock`
 * aggregate (quantity −1, weight − the item's weight, clamped at 0). The legacy
 * Store Exit did NOT decrement stock; this does, so the aggregate stays correct.
 *
 * Date: users with the `backdate` permission pick any date; everyone else
 * gets the shift date (a scan before 07:00 counts as the previous day).
 */
class WarehouseExit extends Component
{
    public const MAX_SCAN = 10;        // barcodes per submit

    public ?int $gate_id = null;
    public string $dateIso = '';
    public string $scan = '';

    /** Scanned rows pending exit: [['barcode','productname','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->gate_id = $this->gates()->first()?->id;
    }

    /** Outbound gates on raw-materials warehouses this user may use. */
    #[Computed]
    public function gates()
    {
        return GateAccess::warehouseGates(auth()->user(), 'raw-materials', WarehouseGate::OUT);
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo('bil.raw_materials.warehouse_exit', 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /** Validate a scanned barcode (must be in stock, not already exited). */
    public function addScan(): void
    {
        $this->scanError = '';
        $barcode = trim($this->scan);
        $this->scan = '';

        if ($barcode === '') {
            return;
        }

        if (collect($this->items)->contains('barcode', $barcode)) {
            $this->scanError = 'Barcode already scanned.';

            return;
        }

        if (count($this->items) >= self::MAX_SCAN) {
            $this->scanError = 'You can only scan ' . self::MAX_SCAN . ' barcodes per submit.';

            return;
        }

        $item = RawMaterialItem::with('product')->where('barcode', $barcode)->first();
        if (! $item) {
            $this->scanError = 'Barcode not found.';

            return;
        }

        if ($item->status !== null) {
            $this->scanError = 'Item already exited.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productname' => $item->product->productname ?? '—',
            'weight' => $item->weight,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /** Legacy shift date: a scan before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    /** Mark every scanned barcode as exited + record the exit rows. */
    public function save(): void
    {
        if ($this->items === []) {
            return;
        }

        // Re-resolve from the granted set, so a stale or tampered id cannot
        // issue stock out of a warehouse this user was never given.
        $gate = $this->gates()->firstWhere('id', $this->gate_id);
        if (! $gate) {
            session()->flash('err', 'That exit is no longer available to you.');

            return;
        }

        $warehouse = $gate->warehouse;
        $legacyLocationId = $warehouse?->legacy_location_id;
        if (! $legacyLocationId) {
            session()->flash('err', 'That warehouse has no legacy store id — it cannot issue raw material yet.');

            return;
        }

        $date = $this->canBackdate()
            ? str_replace('-', '/', $this->dateIso)
            : $this->shiftDate();
        $username = auth()->user()?->username ?? '';

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_warehouse_exit', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another exit is being saved right now — please try again in a moment.');

            return;
        }

        $exited = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];

                // Re-derive product/weight from the barcode server-side.
                $record = RawMaterialItem::where('barcode', $barcode)->first();

                // Re-exit of a previously reverted item: flip its exit record
                // back to NULL rather than inserting a duplicate.
                $reentry = RawMaterialWarehouseExit::where('barcode', $barcode)->whereNotNull('status');
                if ($reentry->clone()->exists()) {
                    $reentry->update(['status' => null]);
                } else {
                    RawMaterialWarehouseExit::create([
                        'user' => $username,
                        'barcode' => $barcode,
                        'dateofcreation' => $date,
                        // Both: the store for the legacy app, the gate for gds.
                        'location_id' => $legacyLocationId,
                        'gate_id' => $gate->id,
                        'status' => null,
                    ]);
                }

                RawMaterialItem::where('barcode', $barcode)->update(['status' => 'Exited']);

                // Take it back out of the warehouse's stock. Not clamped at
                // zero: the totals derive from the barcodes, so a negative is a
                // real discrepancy worth seeing rather than hiding.
                if ($record) {
                    RawMaterialsStock::apply(
                        (int) $warehouse->id,
                        (int) $record->productid,
                        -1,
                        -(float) $record->weight
                    );
                }

                $exited++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_warehouse_exit')");
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $exited . ' item' . ($exited === 1 ? '' : 's')
            . ' exited from ' . ($warehouse->name ?? 'store') . '.');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Warehouse Exit')]
    public function render()
    {
        return view('bil::livewire.raw-materials.warehouse-exit');
    }
}
