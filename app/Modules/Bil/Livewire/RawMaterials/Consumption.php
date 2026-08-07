<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialFactoryEntrance;
use Modules\Bil\Models\RawMaterialFactoryUsage;
use Modules\Bil\Models\RawMaterialProduct;
use Modules\Core\Concerns\EnforcesShift;

/**
 * Raw Materials → Consumption. Rebuilt from the legacy Rawmaterial Consumption
 * page (Bpl\RawmaterialConsumption).
 *
 * Choose shift + factory → machine (line), scan factory-floor barcodes, then
 * Save records each in `factory_usage_rawmaterials` and flips the factory-
 * entrance row to 'consumed'. It does NOT decrement `rawmaterials_stock` —
 * that already happened at Warehouse Exit (the legacy decremented here instead
 * and not at exit; we decrement once, at exit).
 */
class Consumption extends Component
{
    use EnforcesShift;

    /** Gated by the Consumption shift window. */
    public function shiftKey(): ?string
    {
        return 'bil.raw_materials.consumption';
    }

    public const MAX_SCAN = 10; // barcodes per submit

    public string $dateIso = '';
    public string $shift = 'Day';
    public string $factory = '';
    public string $machine = '';
    public string $scan = '';

    /** Scanned rows pending consumption: [['barcode','productname','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo('bil.raw_materials.consumption', 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    #[Computed]
    public function factories()
    {
        return DB::connection('bil')->table('factory_details')
            ->select('location')->distinct()->orderBy('location')->pluck('location');
    }

    /** Machines (sub-lines) for the chosen factory. */
    #[Computed]
    public function machines()
    {
        if ($this->factory === '') {
            return collect();
        }

        return DB::connection('bil')->table('factory_details')
            ->where('location', $this->factory)->orderBy('sublinename')->pluck('sublinename');
    }

    /** The product the chosen machine is currently set up to convert (if any). */
    public function productOnLine(): string
    {
        if ($this->machine === '') {
            return '';
        }

        return (string) DB::connection('bil')->table('conversion_setup')
            ->where('linename', $this->machine)->orderByDesc('id')->value('productname');
    }

    public function updatedFactory(): void
    {
        $this->machine = '';
        $this->items = [];
        $this->scanError = '';
    }

    public function updatedMachine(): void
    {
        $this->items = [];
        $this->scanError = '';
    }

    /** Validate a scanned barcode (on the factory floor, not yet consumed). */
    public function addScan(): void
    {
        $this->scanError = '';
        $barcode = trim($this->scan);
        $this->scan = '';

        if ($this->factory === '' || $this->machine === '') {
            $this->scanError = 'Select a factory and machine first.';

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

        $fe = RawMaterialFactoryEntrance::where('barcode', $barcode)->first();
        if (! $fe) {
            $this->scanError = 'Barcode does not exist in factory entrance.';

            return;
        }
        if ($fe->status !== null) {
            $this->scanError = 'Item has been ' . $fe->status . '.';

            return;
        }

        $pendingReturn = DB::connection('bil')->table('return_approval')
            ->where('barcode', $barcode)->where('status', 'pending')->exists();
        if ($pendingReturn) {
            $this->scanError = 'Product awaits approval on return.';

            return;
        }

        if (RawMaterialFactoryUsage::where('barcode', $barcode)->exists()) {
            $this->scanError = 'Item is already consumed.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productname' => RawMaterialProduct::whereKey($fe->product_id)->value('productname') ?? '—',
            'weight' => $fe->weight,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /** Record consumption for every scanned barcode. */
    public function save(): void
    {
        if ($this->items === [] || $this->factory === '' || $this->machine === '' || ! $this->ensureShiftOpen()) {
            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : $this->shiftDate();
        $username = auth()->user()?->username ?? '';
        $product = $this->productOnLine();

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_consumption', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another consumption is being saved right now — please try again in a moment.');

            return;
        }

        $consumed = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];
                $fe = RawMaterialFactoryEntrance::where('barcode', $barcode)->first();
                if (! $fe) {
                    continue;
                }

                RawMaterialFactoryUsage::create([
                    'user' => $username,
                    'barcode' => $barcode,
                    'shift' => $this->shift,
                    'location' => $this->factory,
                    'linename' => $this->machine,
                    'project' => $product !== '' ? $product : $this->machine,
                    'pre_productname' => $product !== '' ? $product : null,
                    'weight' => $fe->weight,
                    'dateofuse' => $date,
                    'timestamp' => now()->timestamp,
                ]);

                RawMaterialFactoryEntrance::where('barcode', $barcode)->update(['status' => 'consumed']);
                $consumed++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_consumption')");
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $consumed . ' item' . ($consumed === 1 ? '' : 's') . ' consumed.');
    }

    /** Legacy shift date: a scan before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Consumption')]
    public function render()
    {
        return view('bil::livewire.raw-materials.consumption');
    }
}
