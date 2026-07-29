<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Models\RawMaterialStock;
use Modules\Bil\Models\RawMaterialTransfer;

/**
 * Raw Materials → Stock Transfer. Clean rebuild of the legacy
 * rawmaterials_transfer flow (Ogba ⇄ Oregun).
 *
 * Pick an exit location (its destination store is fixed by
 * rawmaterials_transferlocations), scan in-store barcodes at that store, then
 * Save: record each in `rawmaterials_transfer`, move the item's `location_id`
 * to the destination store, and shift the `rawmaterials_stock` aggregate from
 * the source location to the destination (one stock system, unlike the legacy
 * rawmaterials_store/store2 ledgers, which are left untouched).
 */
class StockTransfer extends Component
{
    public const MAX_SCAN = 10; // barcodes per submit

    public string $dateIso = '';
    public string $exitLocation = '';
    public string $scan = '';

    /** Scanned rows pending transfer: [['barcode','productname','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->can('backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /** Exit locations (each maps 1:1 to a destination store). */
    #[Computed]
    public function exitLocations()
    {
        return DB::connection('bil')->table('rawmaterials_transferlocations')
            ->orderBy('exitlocation')->pluck('exitlocation');
    }

    /** The destination for the chosen exit location. */
    public function transferLocation(): string
    {
        if ($this->exitLocation === '') {
            return '';
        }

        return (string) DB::connection('bil')->table('rawmaterials_transferlocations')
            ->where('exitlocation', $this->exitLocation)->value('transferlocation');
    }

    /** Store name inside the parentheses, e.g. "Store 1(Ogba) Gate 1" → "Ogba". */
    protected function storeName(string $location): string
    {
        return preg_match('/\(([^)]+)\)/', $location, $m) ? trim($m[1]) : $location;
    }

    protected function locationId(string $storeName): ?int
    {
        $id = DB::connection('bil')->table('rawmaterial_store_location')
            ->where('location', $storeName)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Clear the scan list when the exit location changes. */
    public function updatedExitLocation(): void
    {
        $this->items = [];
        $this->scanError = '';
    }

    /** Validate a scanned barcode (in stock, at the source store, not exited). */
    public function addScan(): void
    {
        $this->scanError = '';
        $barcode = trim($this->scan);
        $this->scan = '';

        if ($this->exitLocation === '') {
            $this->scanError = 'Select an exit location first.';

            return;
        }
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

        $sourceId = $this->locationId($this->storeName($this->exitLocation));

        $item = RawMaterialItem::with('product')->where('barcode', $barcode)->first();
        if (! $item) {
            $this->scanError = 'Barcode not found.';

            return;
        }
        if ($item->status !== null) {
            $this->scanError = 'Item already exited.';

            return;
        }
        if ((int) $item->location_id !== $sourceId) {
            $this->scanError = 'Item is not at ' . $this->storeName($this->exitLocation) . '.';

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

    /** Record transfers, move each item's location, and shift stock. */
    public function save(): void
    {
        if ($this->items === [] || $this->exitLocation === '') {
            return;
        }

        $transferLoc = $this->transferLocation();
        $source = $this->storeName($this->exitLocation);
        $dest = $this->storeName($transferLoc);
        $destId = $this->locationId($dest);
        if ($destId === null) {
            session()->flash('err', 'Destination store "' . $dest . '" is not set up.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : now()->format('Y/m/d');
        $username = auth()->user()?->username ?? '';

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_stock_transfer', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another transfer is being saved right now — please try again in a moment.');

            return;
        }

        $transferred = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];
                $record = RawMaterialItem::where('barcode', $barcode)->first();

                // Already transferred to this same destination → just refresh
                // who/when (no second stock move), matching the legacy.
                $existing = RawMaterialTransfer::where('barcode', $barcode)->first();
                if ($existing && $existing->transferlocation === $transferLoc) {
                    $existing->update(['username' => $username, 'dateoftransfer' => $date]);

                    continue;
                }

                RawMaterialTransfer::create([
                    'username' => $username,
                    'exitlocation' => $this->exitLocation,
                    'transferlocation' => $transferLoc,
                    'barcode' => $barcode,
                    'dateoftransfer' => $date,
                ]);

                RawMaterialItem::where('barcode', $barcode)->update(['location_id' => $destId]);

                if ($record) {
                    $weight = (float) $record->weight;
                    $pid = $record->productid;

                    $src = RawMaterialStock::where('location', $source)->where('productid', $pid)->first();
                    if ($src) {
                        $src->quantity = max(0, (int) $src->quantity - 1);
                        $src->weight = max(0, (float) $src->weight - $weight);
                        $src->save();
                    }

                    $dst = RawMaterialStock::where('location', $dest)->where('productid', $pid)->first();
                    if ($dst) {
                        $dst->quantity = (int) $dst->quantity + 1;
                        $dst->weight = (float) $dst->weight + $weight;
                        $dst->save();
                    } else {
                        RawMaterialStock::create([
                            'location' => $dest,
                            'productid' => $pid,
                            'quantity' => 1,
                            'weight' => $weight,
                            'modification' => $username,
                        ]);
                    }
                }

                $transferred++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_stock_transfer')");
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $transferred . ' item' . ($transferred === 1 ? '' : 's') . ' transferred to ' . $dest . '.');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Stock Transfer')]
    public function render()
    {
        return view('bil::livewire.raw-materials.stock-transfer');
    }
}
