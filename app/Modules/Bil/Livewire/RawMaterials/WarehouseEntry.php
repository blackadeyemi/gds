<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialDelivery;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Support\RawMaterialsStock;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * Raw Materials → Warehouse Entry. Rebuilt from the legacy Store Entrance
 * (rawmaterial_store_entrance + Bpl\Rawmaterial_StoreEntrance).
 *
 * Scan delivered barcodes (validated against the delivery-staging table) into
 * a batch of up to 10, then Save promotes each into the live `rawmaterials`
 * table and adds it to the warehouse's stock.
 *
 * The gate is chosen rather than hard-coded: the legacy screen booked straight
 * against store 1 (Ogba), so a second store could never be used. Gates come from
 * `warehouse_gates` filtered to raw-materials warehouses and inbound direction,
 * and narrowed to the ones this user has been granted — see GateAccess.
 *
 * Stock lands in `raw_materials_warehouse_stock`, keyed by warehouse. gds no
 * longer writes the legacy `rawmaterials_stock`; see the 2026-08-12 cut-over in
 * docs/DEPLOYMENT.md. `location_id` on the item row IS still written, because
 * the legacy app reads it.
 */
class WarehouseEntry extends Component
{
    public const MAX_SCAN = 10;

    public ?int $gate_id = null;
    public string $dateIso = '';
    public string $scan = '';

    /** Scanned rows pending save: [['barcode','productname','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->gate_id = $this->gates()->first()?->id;
    }

    /** Inbound gates on raw-materials warehouses this user may use. */
    #[Computed]
    public function gates()
    {
        return GateAccess::warehouseGates(auth()->user(), 'raw-materials', WarehouseGate::IN);
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /** Whether the current user may change the date (backdate). */
    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo('bil.raw_materials.warehouse_entry', 'backdate');
    }

    /** The 10 most recent delivered barcodes not yet entered into the warehouse. */
    #[Computed]
    public function pending()
    {
        $scanned = collect($this->items)->pluck('barcode')->all();

        return DB::connection('bil')->table('rawmaterials_supplier_deliveries as d')
            ->leftJoin('rawmaterials_warehouse_entry as w', 'd.barcode', '=', 'w.barcode')
            ->leftJoin('rawmaterials_products as p', 'd.productid', '=', 'p.id')
            ->whereNull('w.barcode')
            ->when($scanned, fn ($q) => $q->whereNotIn('d.barcode', $scanned))
            ->orderByDesc('d.id')
            ->limit(10)
            ->get(['d.barcode', 'd.weight', 'd.dateofcreation', 'p.productname']);
    }

    /** Click a pending row to add it to the scan list. */
    public function addBarcode(string $barcode): void
    {
        $this->scan = $barcode;
        $this->addScan();
    }

    /** Validate a scanned barcode and add it to the batch. */
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

        // Match the legacy order: already-in-store is checked before we even
        // confirm the barcode exists in deliveries.
        if (RawMaterialItem::where('barcode', $barcode)->exists()) {
            $this->scanError = 'Item already in store.';

            return;
        }

        $delivery = RawMaterialDelivery::with('product')->where('barcode', $barcode)->first();
        if (! $delivery) {
            $this->scanError = 'Barcode not found.';

            return;
        }

        $exited = DB::connection('bil')->table('rawmaterials_warehouse_exit')
            ->where('barcode', $barcode)->whereNull('status')->exists();
        if ($exited) {
            $this->scanError = 'Item already exited.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productname' => $delivery->product->productname ?? '—',
            'weight' => $delivery->weight,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /** Promote every scanned barcode into live stock + update the aggregate. */
    public function save(): void
    {
        if ($this->items === []) {
            return;
        }

        // Re-resolve from the granted set, so a stale or tampered id cannot
        // book stock into a warehouse this user was never given.
        $gate = $this->gates()->firstWhere('id', $this->gate_id);
        if (! $gate) {
            session()->flash('err', 'That entrance is no longer available to you.');

            return;
        }

        $warehouse = $gate->warehouse;
        $legacyLocationId = $warehouse?->legacy_location_id;
        if (! $legacyLocationId) {
            // The legacy app reads `location_id`; writing a row without one
            // would make the item invisible to it.
            session()->flash('err', 'That warehouse has no legacy store id — it cannot take raw-material entries yet.');

            return;
        }

        // Without the backdate permission the date is forced to today,
        // regardless of what the client submitted.
        $dateIso = $this->canBackdate() ? $this->dateIso : now()->format('Y-m-d');
        $date = str_replace('-', '/', $dateIso);
        $username = auth()->user()?->username ?? '';

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_warehouse_entry', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another entry is being saved right now — please try again in a moment.');

            return;
        }

        $stored = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];

                // Skip anything already promoted (belt-and-braces re-check).
                if (RawMaterialItem::where('barcode', $barcode)->exists()) {
                    continue;
                }

                // Re-derive product/weight from the barcode server-side (don't
                // trust the client-held items array).
                $delivery = RawMaterialDelivery::where('barcode', $barcode)->first();
                if (! $delivery) {
                    continue;
                }

                RawMaterialItem::create([
                    'barcode' => $barcode,
                    'username' => $username,
                    'productid' => $delivery->productid,
                    'weight' => $delivery->weight,
                    // Both: the store for the legacy app, the gate for gds.
                    'location_id' => $legacyLocationId,
                    'gate_id' => $gate->id,
                    'dateofcreation' => $date,
                    'status' => null,
                ]);

                RawMaterialsStock::apply(
                    (int) $warehouse->id,
                    (int) $delivery->productid,
                    1,
                    (float) $delivery->weight
                );

                $stored++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_warehouse_entry')");
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $stored . ' item' . ($stored === 1 ? '' : 's')
            . ' entered into ' . ($warehouse->name ?? 'store') . '.');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Warehouse Entry')]
    public function render()
    {
        return view('bil::livewire.raw-materials.warehouse-entry');
    }
}
