<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialFactoryEntrance;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Models\RawMaterialWarehouseExit;
use Modules\Core\Concerns\EnforcesShift;

/**
 * Raw Materials → Factory Entrance. Rebuilt from the legacy
 * rawmaterial_newfactory_entrance page (raw_material_entrance_tr +
 * submit_rawmaterials_entrance).
 *
 * Scan barcodes that have been exited from the warehouse into a factory
 * location, then Save records each in `factory_entrance_rawmaterials`. A
 * previously-returned item ('return' status) is re-activated (status → NULL)
 * and its consumption/return-approval rows are cleared.
 */
class FactoryEntrance extends Component
{
    use EnforcesShift;

    /** This entry page is gated by the Factory Entrance shift window. */
    public function shiftKey(): ?string
    {
        return 'bil.factory_entrance';
    }

    /** Factory lines not offered for raw-material entrance (legacy exclusions). */
    protected const EXCLUDED = ['PM2', 'PM3', 'Oregun Store'];

    public const MAX_SCAN = 10; // barcodes per submit

    public string $dateIso = '';
    public ?int $locationId = null;
    public string $scan = '';

    /** Scanned rows pending save: [['barcode','productname','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->locationId = $this->locations()->first()->id ?? null;
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->can('backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /** Selectable factory locations. */
    #[Computed]
    public function locations()
    {
        return DB::connection('bil')->table('factoryentrance_details')
            ->whereNotIn('factoryname', self::EXCLUDED)
            ->orderBy('id')
            ->get(['id', 'factoryname']);
    }

    /** Legacy shift date: a scan before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    /** Validate a scanned barcode (exited from store, not already in factory). */
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

        // Must have a store-exit record at all.
        if (! RawMaterialWarehouseExit::where('barcode', $barcode)->exists()) {
            $this->scanError = 'Barcode not found in store exit.';

            return;
        }

        // Factory-entrance state gates re-entry.
        $fe = RawMaterialFactoryEntrance::where('barcode', $barcode)->first();
        if ($fe) {
            if ($fe->status === null) {
                $this->scanError = 'Item already scanned into factory.';

                return;
            }
            if ($fe->status === 'consumed') {
                $this->scanError = 'Item has already been consumed.';

                return;
            }
            // 'return' → allowed to re-enter below.
        }

        // Must currently be exited from the warehouse (active exit) + resolve product/weight.
        $row = $this->exitedItem($barcode);
        if (! $row) {
            $this->scanError = 'Item has not been exited from store.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productname' => $row->productname ?? '—',
            'weight' => $row->weight,
        ];
    }

    /** The stock item for a barcode that is currently exited from the warehouse. */
    protected function exitedItem(string $barcode)
    {
        return DB::connection('bil')->table('rawmaterials_warehouse_exit as se')
            ->join('rawmaterials_warehouse_entry as r', 'r.barcode', '=', 'se.barcode')
            ->leftJoin('rawmaterials_products as p', 'r.productid', '=', 'p.id')
            ->where('r.barcode', $barcode)
            ->whereNull('se.status')
            ->select('r.productid', 'r.weight', 'p.productname')
            ->first();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /** Record each scanned barcode's factory entrance. */
    public function save(): void
    {
        if ($this->items === [] || $this->locationId === null || ! $this->ensureShiftOpen()) {
            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : $this->shiftDate();
        $username = auth()->user()?->username ?? '';

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_factory_entrance', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another entrance is being saved right now — please try again in a moment.');

            return;
        }

        $entered = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];
                $fe = RawMaterialFactoryEntrance::where('barcode', $barcode)->first();

                if ($fe && $fe->status === 'return') {
                    // Re-activate a returned item and clear its downstream rows.
                    RawMaterialFactoryEntrance::where('barcode', $barcode)->update(['status' => null]);
                    $conn->table('factory_usage_rawmaterials')->where('barcode', $barcode)->delete();
                    $conn->table('return_approval')->where('barcode', $barcode)->delete();
                } elseif (! $fe) {
                    $record = RawMaterialItem::where('barcode', $barcode)->first();
                    RawMaterialFactoryEntrance::create([
                        'user_name' => $username,
                        'location_id' => $this->locationId,
                        'entrance_date' => $date,
                        'barcode' => $barcode,
                        'product_id' => $record?->productid,
                        'weight' => $record?->weight,
                    ]);
                }

                $entered++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_factory_entrance')");
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $entered . ' item' . ($entered === 1 ? '' : 's') . ' entered into the factory.');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Factory Entrance')]
    public function render()
    {
        return view('bil::livewire.raw-materials.factory-entrance');
    }
}
