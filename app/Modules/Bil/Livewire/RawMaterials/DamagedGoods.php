<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\RawMaterialDamagedGood;
use Modules\Bil\Models\RawMaterialItem;
use Modules\Bil\Models\RawMaterialStock;

/**
 * Raw Materials → Damaged Goods. Rebuilt from the legacy Rawmaterials Damage
 * Goods page (rawmaterials_damagegoods + submit_rawmaterials_damagegoods),
 * with an approval stage added (the legacy wrote straight to stock).
 *
 * Two stages on one page:
 *   1. Entry — scan in-store barcodes (in warehouse-entry, not exited, not
 *      already reported) and submit them. This only writes pending
 *      `damagedgoods_rawmaterial` rows; stock is untouched.
 *   2. Approval — a user with `approve-raw-materials` approves or rejects each
 *      pending report. Approval is where the item actually leaves: warehouse-
 *      entry status→'Exited' and the stock aggregate is decremented
 *      (quantity −1, weight − the item's weight, clamped at 0).
 *
 * Serialized by GET_LOCK. Legacy rows carry a NULL status (already final);
 * those are treated as approved for the "already reported" guard.
 */
class DamagedGoods extends Component
{
    public const MAX_SCAN = 10; // barcodes per submit

    public string $dateIso = '';
    public string $scan = '';

    /** Scanned rows pending submission: [['barcode','product_id','productname','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo('bil.raw_materials.damaged_goods', 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    public function canApprove(): bool
    {
        return (bool) auth()->user()?->canDo('bil.raw_materials.damaged_goods', 'approve');
    }

    /** Validate a scanned barcode (in store, not exited, not already reported). */
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

        // Already reported and not rejected? (NULL status = legacy final)
        $reported = RawMaterialDamagedGood::where('barcode', $barcode)
            ->where(function ($q) {
                $q->whereNull('status')->orWhereIn('status', ['pending', 'approved']);
            })->exists();
        if ($reported) {
            $this->scanError = 'Item is already in damaged goods.';

            return;
        }

        $item = RawMaterialItem::with('product')->where('barcode', $barcode)->first();
        if (! $item) {
            $this->scanError = 'Barcode not found in store.';

            return;
        }
        if ($item->status !== null) {
            $this->scanError = 'Item has already left the store.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'product_id' => $item->productid,
            'productname' => $item->product->productname ?? '—',
            'weight' => (float) $item->weight,
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

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y-m-d');
    }

    /** Submit every scanned barcode for approval (pending damaged-goods rows). */
    public function save(): void
    {
        if ($this->items === []) {
            return;
        }

        $date = $this->canBackdate() ? $this->dateIso : $this->shiftDate();
        $username = auth()->user()?->username ?? '';

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_damaged_goods', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another report is being saved right now — please try again in a moment.');

            return;
        }

        $submitted = 0;
        try {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];
                $exists = RawMaterialDamagedGood::where('barcode', $barcode)
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhereIn('status', ['pending', 'approved']);
                    })->exists();
                if ($exists) {
                    continue;
                }

                RawMaterialDamagedGood::create([
                    'user_name' => $username,
                    'barcode' => $barcode,
                    'location_id' => WarehouseExit::LOCATION_ID,
                    'entrance_date' => $date,
                    'product_id' => $item['product_id'],
                    'weight' => $item['weight'],
                    'status' => 'pending',
                ]);
                $submitted++;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_damaged_goods')");
        }

        $this->items = [];
        $this->scanError = '';
        unset($this->pendingDamaged);
        session()->flash('ok', $submitted . ' item' . ($submitted === 1 ? '' : 's') . ' submitted for approval.');
    }

    /** Pending damaged-goods reports awaiting a decision. */
    #[Computed]
    public function pendingDamaged()
    {
        if (! $this->canApprove()) {
            return collect();
        }

        return RawMaterialDamagedGood::where('status', 'pending')
            ->orderByDesc('id')->get();
    }

    /** Approve a report: remove the item from the store + decrement stock. */
    public function approve(int $id): void
    {
        abort_unless($this->canApprove(), 403);

        $req = RawMaterialDamagedGood::whereKey($id)->where('status', 'pending')->first();
        if (! $req) {
            return;
        }

        $conn = DB::connection('bil');
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('rm_damaged_goods', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Busy — please try again in a moment.');

            return;
        }

        try {
            RawMaterialItem::where('barcode', $req->barcode)->update(['status' => 'Exited']);

            $stock = RawMaterialStock::where('productid', $req->product_id)->first();
            if ($stock) {
                $stock->quantity = max(0, (int) $stock->quantity - 1);
                $stock->weight = max(0, (float) $stock->weight - (float) $req->weight);
                $stock->save();
            }

            $req->update(['status' => 'approved']);
        } finally {
            $conn->select("SELECT RELEASE_LOCK('rm_damaged_goods')");
        }

        unset($this->pendingDamaged);
        session()->flash('ok', 'Damaged item approved and removed from stock.');
    }

    /** Reject a report (no stock change was made at entry). */
    public function reject(int $id): void
    {
        abort_unless($this->canApprove(), 403);

        RawMaterialDamagedGood::whereKey($id)->where('status', 'pending')
            ->update(['status' => 'rejected']);

        unset($this->pendingDamaged);
        session()->flash('ok', 'Damaged-goods report rejected.');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Damaged Goods')]
    public function render()
    {
        return view('bil::livewire.raw-materials.damaged-goods');
    }
}
