<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialDelivery;
use Modules\Bil\Models\RawMaterialProduct;
use Modules\Bil\Models\RawMaterialSupplier;

/**
 * Raw Materials → Supplier Deliveries. Rebuilt from the legacy Rawmaterial
 * Barcode Generator (rawmaterial_barcode_generator + Bpl\RawmaterialNew).
 *
 * Two steps: (1) pick product/supplier/weight and how many barcodes to make;
 * (2) confirm/adjust each barcode's weight. On confirm it creates one row per
 * barcode in `rawmaterials_copy` (the delivery-staging table) and opens the
 * CODE93 label print in a new tab.
 *
 * Barcode format: YY-MM-DD-{subgroup suffix}-{seq} where seq is a global
 * daily counter (across all products), zero-padded to 3 digits.
 */
class SupplierDeliveries extends Component
{
    public const MAX_BARCODES = 50;

    public string $step = 'form';

    /** Bound to the native date picker (Y-m-d); stored as legacy Y/m/d. */
    public string $dateIso = '';
    public string $date = '';
    public ?int $productid = null;
    public string $suppliercode = '';
    public ?float $weight = null;
    public ?int $numBarcode = null;

    /** Per-barcode weights, editable in the confirm step. */
    public array $weights = [];

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    /** Whether the current user may change the date (backdate). */
    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo('bil.raw_materials.supplier_deliveries', 'backdate');
    }

    /**
     * Legacy stores the date as yyyy/mm/dd; derive it from the picker. Without
     * the backdate permission the date is forced to today server-side.
     */
    protected function syncDate(): void
    {
        $iso = $this->canBackdate() ? $this->dateIso : now()->format('Y-m-d');
        $this->date = str_replace('-', '/', $iso);
    }

    /** Exposed for the view (class constants aren't reachable via $this in Blade). */
    #[Computed]
    public function maxBarcodes(): int
    {
        return self::MAX_BARCODES;
    }

    #[Computed]
    public function products()
    {
        return RawMaterialProduct::orderBy('productname')->get(['id', 'productname']);
    }

    #[Computed]
    public function suppliers()
    {
        return RawMaterialSupplier::orderBy('suppliername')->get(['suppliername', 'suppliercode']);
    }

    protected function formRules(): array
    {
        return [
            'dateIso' => ['required', 'date'],
            'productid' => ['required', 'integer'],
            'suppliercode' => ['required', 'string'],
            'weight' => ['required', 'numeric', 'gt:0'],
            'numBarcode' => ['required', 'integer', 'min:1', 'max:' . self::MAX_BARCODES],
        ];
    }

    /** Step 1 → 2: validate the form and seed one weight input per barcode. */
    public function toConfirm(): void
    {
        $this->validate($this->formRules());
        $this->syncDate();
        $this->weights = array_fill(0, $this->numBarcode, $this->weight);
        $this->step = 'confirm';
    }

    public function back(): void
    {
        $this->step = 'form';
    }

    /** Step 2: create the barcodes and open the print tab. */
    public function generate(): void
    {
        $this->validate($this->formRules());
        $this->validate(
            ['weights' => ['required', 'array', 'min:1'], 'weights.*' => ['required', 'numeric', 'gt:0']],
            [],
            ['weights.*' => 'weight']
        );
        $this->syncDate();

        $product = RawMaterialProduct::with('subgroup')->findOrFail($this->productid);
        $subgroupcode = (string) ($product->subgroup->subgroupcode ?? '');
        $suffix = str_contains($subgroupcode, '-') ? explode('-', $subgroupcode)[1] : $subgroupcode;

        // Date is stored as the legacy yyyy/mm/dd string.
        [$year, $month, $day] = array_pad(explode('/', $this->date), 3, '');
        $prefix = substr($year, 2) . '-' . $month . '-' . $day . '-' . $suffix . '-';

        $username = auth()->user()->username ?? '';
        $today = now()->format('Y-m-d');
        $ids = [];

        // The daily sequence is a global counter read-then-written, so two
        // stations generating at once could grab the same number. Serialize
        // the whole read+insert batch behind a MySQL named lock (the table is
        // MyISAM, so DB transactions aren't available). Lock is connection-
        // scoped and released in finally.
        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_delivery_seq', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another delivery is being generated right now — please try again in a moment.');

            return;
        }

        try {
            // Global daily sequence, continued from the last barcode of the day.
            $last = RawMaterialDelivery::where('dateofcreation', $this->date)->orderByDesc('id')->value('barcode');
            $seq = 0;
            if ($last) {
                $parts = explode('-', $last);
                $seq = isset($parts[4]) ? (int) $parts[4] : 0;
            }

            foreach ($this->weights as $w) {
                $seq++;
                $barcode = $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                $row = RawMaterialDelivery::create([
                    'barcode' => $barcode,
                    'suppliercode' => $this->suppliercode,
                    'productid' => $this->productid,
                    'username' => $username,
                    'weight' => $w,
                    'dateofcreation' => $this->date,
                    'location' => 'Ogba',
                    'location_id' => 1,
                    'status' => null,
                    'timestamp' => $today,
                    'sub_barcode' => null,
                ]);
                $ids[] = $row->id;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_delivery_seq')");
        }

        session()->put('rm_delivery_print_ids', $ids);

        $count = count($ids);
        $this->reset(['productid', 'suppliercode', 'weight', 'numBarcode', 'weights']);
        $this->step = 'form';
        session()->flash('ok', $count . ' barcode' . ($count === 1 ? '' : 's') . ' created — opening print…');

        $this->dispatch('print-labels', url: route('bil.raw-materials.supplier-deliveries.print'));
    }

    #[Layout('core::layouts.admin')]
    #[Title('Supplier Deliveries')]
    public function render()
    {
        return view('bil::livewire.raw-materials.supplier-deliveries');
    }
}
