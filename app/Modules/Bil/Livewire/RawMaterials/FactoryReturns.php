<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialFactoryEntrance;
use Modules\Bil\Models\RawMaterialFactoryUsage;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Models\RawMaterialProduct;
use Modules\Bil\Models\RawMaterialReturnApproval;
use Modules\Bil\Models\RawMaterialStock;
use Modules\Bil\Models\RawMaterialWarehouseExit;

/**
 * Raw Materials → Factory Returns. Rebuilt from the legacy Raw Material Return
 * (rawmaterial_return_rawmaterial + Bpl\RawmaterialReturn) and its approval
 * screen (awaiting_approval_rw_return).
 *
 * Two stages on one page:
 *   1. Entry — an operator scans factory-floor barcodes to return to the store,
 *      choosing a return type, and submits them for approval. This only writes
 *      pending `return_approval` rows; no stock/status changes yet.
 *        • Non-Consumed      — a whole item that entered the factory but was
 *          never used; the full weight goes back.
 *        • Partially Consumed — the unused remainder of a consumed item; the
 *          operator enters the leftover weight (< original).
 *   2. Approval — a user with `approve-raw-materials` approves or rejects each
 *      pending request. Approval is where stock and statuses actually move:
 *        • Non-Consumed: item goes back into store (warehouse-entry status→NULL,
 *          factory-entrance & warehouse-exit status→'return'), stock +1/+weight.
 *        • Partially Consumed: the leftover becomes a NEW child barcode in the
 *          store (parent stays consumed), stock +1/+leftover.
 *
 * Stock is re-added on approval because gds decrements it once at Warehouse
 * Exit (the legacy decremented at consumption). Serialized by GET_LOCK.
 */
class FactoryReturns extends Component
{
    public const TYPE_NON_CONSUMED = 'Non-Consumed';
    public const TYPE_PARTIAL = 'Partially Consumed';

    public const LOCATION_ID = 1; // Ogba — the raw-materials store

    public string $dateIso = '';
    public string $returnType = self::TYPE_NON_CONSUMED;
    public string $scan = '';

    /** Scanned rows pending submission: [['barcode','productname','weight','returnWeight'], …]. */
    public array $items = [];

    public string $scanError = '';

    /** Barcode of the most recently approved return, offered for a label reprint. */
    public string $printBarcode = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->can('backdate');
    }

    public function canApprove(): bool
    {
        return (bool) auth()->user()?->can('approve-raw-materials');
    }

    public function updatedReturnType(): void
    {
        $this->items = [];
        $this->scanError = '';
    }

    /** Validate a scanned barcode for the selected return type. */
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

        // Already awaiting approval? (either type)
        $pending = RawMaterialReturnApproval::where('barcode', $barcode)
            ->where('status', 'pending')->exists();
        if ($pending) {
            $this->scanError = 'Item is already awaiting approval.';

            return;
        }

        $fe = RawMaterialFactoryEntrance::where('barcode', $barcode)->first();

        if ($this->returnType === self::TYPE_NON_CONSUMED) {
            if (! $fe) {
                $this->scanError = 'Barcode does not exist in factory entrance.';

                return;
            }
            if ($fe->status !== null) {
                $this->scanError = 'Item has been ' . $fe->status . '.';

                return;
            }

            $weight = (float) $fe->weight;
            $this->items[] = [
                'barcode' => $barcode,
                'productname' => $this->productName($fe->product_id),
                'weight' => $weight,
                'returnWeight' => $weight, // whole item — not editable
            ];

            return;
        }

        // Partially Consumed — the item must have been consumed.
        $usage = RawMaterialFactoryUsage::where('barcode', $barcode)->first();
        if (! $usage) {
            $this->scanError = 'Item has not been consumed.';

            return;
        }
        if ($usage->status === 'return') {
            $this->scanError = 'Item has already been returned.';

            return;
        }

        $weight = (float) ($fe->weight ?? $usage->weight);
        $this->items[] = [
            'barcode' => $barcode,
            'productname' => $this->productName($fe->product_id ?? null),
            'weight' => $weight,
            'returnWeight' => '', // operator enters the leftover
        ];
    }

    protected function productName($productId): string
    {
        if (! $productId) {
            return '—';
        }

        return RawMaterialProduct::whereKey($productId)->value('productname') ?? '—';
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

        // return_approval stores dates as legacy `d/m/y` (e.g. 03/07/26).
        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('d/m/y');
    }

    /** Submit every scanned barcode for approval (pending return_approval rows). */
    public function save(): void
    {
        if ($this->items === []) {
            return;
        }

        // Validate partial weights before touching anything.
        if ($this->returnType === self::TYPE_PARTIAL) {
            foreach ($this->items as $item) {
                $rw = (float) $item['returnWeight'];
                if ($rw <= 0 || $rw >= (float) $item['weight']) {
                    $this->scanError = 'Enter a valid returned weight (more than 0 and less than the original) for ' . $item['barcode'] . '.';

                    return;
                }
            }
        }

        $date = $this->canBackdate()
            ? \Carbon\Carbon::createFromFormat('Y-m-d', $this->dateIso)->format('d/m/y')
            : $this->shiftDate();
        $username = auth()->user()?->username ?? '';

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_return', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another return is being saved right now — please try again in a moment.');

            return;
        }

        $submitted = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];
                if (RawMaterialReturnApproval::where('barcode', $barcode)->where('status', 'pending')->exists()) {
                    continue;
                }

                $returnWeight = $this->returnType === self::TYPE_PARTIAL
                    ? (float) $item['returnWeight']
                    : (float) $item['weight'];

                $verb = $this->returnType === self::TYPE_PARTIAL ? 'Partially' : 'Fully';
                $message = 'Kindly confirm the weight of ' . $item['productname'] . ' as ' . $verb
                    . ' returned by ' . $username . ' to be ' . $returnWeight . 'kg';

                RawMaterialReturnApproval::create([
                    'sequence_number' => (string) random_int(100, 1000000),
                    'user' => $username,
                    'barcode' => $barcode,
                    'product' => $item['productname'],
                    'weight' => (int) round($returnWeight),
                    'message' => $message,
                    'dateofcreation' => $date,
                    'type' => $this->returnType,
                    'status' => 'pending',
                ]);
                $submitted++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_return')");
        }

        $this->items = [];
        $this->scanError = '';
        $this->printBarcode = '';
        unset($this->pendingReturns);
        session()->flash('ok', $submitted . ' item' . ($submitted === 1 ? '' : 's') . ' submitted for approval.');
    }

    /** Pending return requests awaiting a decision. */
    #[Computed]
    public function pendingReturns()
    {
        if (! $this->canApprove()) {
            return collect();
        }

        return RawMaterialReturnApproval::where('status', 'pending')
            ->orderByDesc('timestamp')->get();
    }

    /** Approve a pending return: move stock + statuses per the return type. */
    public function approve(int $id): void
    {
        abort_unless($this->canApprove(), 403);

        $req = RawMaterialReturnApproval::whereKey($id)->where('status', 'pending')->first();
        if (! $req) {
            return;
        }

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_return', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Busy — please try again in a moment.');

            return;
        }

        $childBarcode = null;
        try {
            $barcode = $req->barcode;
            $parent = RawMaterialItem::where('barcode', $barcode)->first();

            if ($req->type === self::TYPE_NON_CONSUMED) {
                // Whole item returns to the store under its own barcode, now
                // sourced from the factory rather than the original supplier.
                RawMaterialFactoryEntrance::where('barcode', $barcode)->update(['status' => 'return']);
                RawMaterialWarehouseExit::where('barcode', $barcode)->update(['status' => 'return']);
                RawMaterialItem::where('barcode', $barcode)->update(['status' => null, 'source' => 'factory']);
                RawMaterialFactoryUsage::where('barcode', $barcode)->update(['status' => 'return']);

                if ($parent) {
                    $this->addToStock($parent->productid, (float) $parent->weight);
                }
            } else {
                // Leftover returns as a NEW child barcode; parent stays consumed.
                if ($parent) {
                    $childBarcode = $this->nextChildBarcode($barcode);
                    RawMaterialItem::create([
                        'username' => auth()->user()?->username ?? '',
                        'suppliercode' => $parent->suppliercode,
                        'productid' => $parent->productid,
                        'barcode' => $childBarcode,
                        'weight' => (float) $req->weight,
                        'location_id' => self::LOCATION_ID,
                        'dateofcreation' => now()->format('Y-m-d'),
                        'status' => null,
                        'source' => 'factory',
                    ]);
                    $this->addToStock($parent->productid, (float) $req->weight);
                }
            }

            $req->update(['status' => 'approved', 'authorizer' => auth()->user()?->username]);

            // A partial return makes a brand-new child barcode that needs a
            // physical label; a whole-item return reuses its own. Offer a reprint.
            $this->printBarcode = $childBarcode ?? $barcode;
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_return')");
        }

        unset($this->pendingReturns);
        $msg = 'Return approved.';
        if ($childBarcode) {
            $msg = 'Return approved — new store barcode ' . $childBarcode . ' created.';
        }
        session()->flash('ok', $msg);
    }

    /** Reject a pending return (no stock/status changes were made at entry). */
    public function reject(int $id): void
    {
        abort_unless($this->canApprove(), 403);

        RawMaterialReturnApproval::whereKey($id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'authorizer' => auth()->user()?->username]);

        unset($this->pendingReturns);
        $this->printBarcode = '';
        session()->flash('ok', 'Return rejected.');
    }

    /** Add a returned item back to the stock aggregate for its product. */
    protected function addToStock(int $productId, float $weight): void
    {
        $stock = RawMaterialStock::where('productid', $productId)->first();
        if ($stock) {
            $stock->quantity = (int) $stock->quantity + 1;
            $stock->weight = (float) $stock->weight + $weight;
            $stock->save();
        }
    }

    /** First free `<barcode>-A`, `-B`, … child not already in the store. */
    protected function nextChildBarcode(string $barcode): string
    {
        foreach (range('A', 'Z') as $letter) {
            $candidate = $barcode . '-' . $letter;
            if (! RawMaterialItem::where('barcode', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $barcode . '-' . random_int(100, 999);
    }

    #[Layout('core::layouts.admin')]
    #[Title('Factory Returns')]
    public function render()
    {
        return view('bil::livewire.raw-materials.factory-returns');
    }
}
