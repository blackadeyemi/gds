<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\FactoryConversion;
use Modules\Bil\Models\StoreEntrance;
use Modules\Bil\Models\StoreEntranceLocation;
use Modules\Bil\Support\FinishedGoodsStock;

/**
 * BIL → Finished Goods → Warehouse Entrance. Rebuild of the legacy
 * store_entrance_beta.php ("Item Receive").
 *
 * Pallets are scanned as the warehouse takes them in — the last barcode-level
 * step of a pallet's life. Unlike the other two scanning screens this one MOVES
 * STOCK: every receipt also adds its bundles to the warehouse total
 * (`storebundle`) and to the receiving floor (`storebundle_floor`), both shared
 * live with the legacy app. See Modules\Bil\Support\FinishedGoodsStock.
 *
 * Contracts reproduced from the legacy Store\Entrance::insertScanning:
 *   - a barcode is accepted if it exists in `factory_conversion` and is not
 *     already in `store_entrance` (UNIQUE — a pallet is received once).
 *     Note it is checked against CONVERSION, not factory exit: the warehouse can
 *     receive a pallet whose gate scan was missed or comes later.
 *   - `dateofentrance` is the pallet's EXIT date when it has one, falling back
 *     to the date on the form. The receipt is dated by when the pallet left the
 *     factory, so a next-morning scan still lands on the right day.
 *   - `factory_exit.status` and `factory_conversion.status` are both flipped to
 *     'yes', marking the pallet received and off the floor.
 */
#[Layout('core::layouts.admin')]
#[Title('Warehouse Entrance')]
class WarehouseEntrance extends Component
{
    public const PAGE_KEY = 'bil.finished_goods.warehouse_entrance';

    /** The legacy screen laid out 25 barcode boxes per submit. */
    public const MAX_SCAN = 25;

    public string $entrancelocation = '';
    public string $dateIso = '';
    public string $scan = '';

    /** Scanned pallets pending save: [['barcode','productid','productname','bundles'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->entrancelocation = (string) ($this->locations()->keys()->first() ?? '');
    }

    /** Gates, labelled with the store floor they belong to. */
    #[Computed]
    public function locations()
    {
        return StoreEntranceLocation::orderBy('storefloor')->orderBy('entrancelocation')
            ->get()->mapWithKeys(fn ($l) => [
                $l->entrancelocation => $l->storefloor . ' — ' . $l->entrancelocation,
            ]);
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /* ---------------- Scanning ---------------- */

    /**
     * Validate a scanned barcode: produced, and not already received.
     * Mirrors the legacy Barcode\Entrance::finishGoods lookup.
     */
    public function addScan(): void
    {
        $this->scanError = '';
        $barcode = strtoupper(trim($this->scan));
        $this->scan = '';

        if ($barcode === '') {
            return;
        }

        if (collect($this->items)->contains('barcode', $barcode)) {
            $this->scanError = 'Barcode already scanned.';

            return;
        }

        if (count($this->items) >= self::MAX_SCAN) {
            $this->scanError = 'You can only scan ' . self::MAX_SCAN . ' pallets per submit.';

            return;
        }

        $entrance = StoreEntrance::where('barcode', $barcode)->first();
        if ($entrance) {
            $when = $entrance->timestamp
                ? Carbon::createFromTimestamp($entrance->timestamp)->format('d M Y')
                : $entrance->dateofentrance;
            $this->scanError = 'Already received on ' . $when . '.';

            return;
        }

        $pallet = FactoryConversion::with('product')->where('barcode', $barcode)->first();
        if (! $pallet) {
            $this->scanError = 'Barcode not found in conversion output.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productid' => (int) $pallet->productid,
            'productname' => $pallet->product->productname ?? '—',
            'bundles' => (int) $pallet->bundles,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function totalBundles(): int
    {
        return (int) array_sum(array_column($this->items, 'bundles'));
    }

    /** Legacy production date: a scan before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        if ($this->items === []) {
            return;
        }

        $this->validate(
            ['entrancelocation' => 'required|string'],
            ['entrancelocation.required' => 'Pick the gate the pallets are coming in through.']
        );

        if (! $this->locations()->has($this->entrancelocation)) {
            session()->flash('err', 'That entrance location no longer exists.');

            return;
        }

        // Non-backdaters always book against the shift date.
        $fallbackDate = $this->canBackdate()
            ? str_replace('-', '/', $this->dateIso)
            : $this->shiftDate();
        $username = (string) (auth()->user()?->username ?? auth()->user()?->name ?? '');
        $now = now()->getTimestamp();

        $conn = DB::connection('bil');
        $saved = 0;
        $skipped = [];

        $conn->transaction(function () use ($conn, $fallbackDate, $username, $now, &$saved, &$skipped) {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];

                // Re-check server-side: the list was built when the pallet was
                // scanned, and another station may have received it since.
                // `barcode` is UNIQUE, so without this the whole batch would
                // roll back on one stale row.
                if (StoreEntrance::where('barcode', $barcode)->exists()) {
                    $skipped[] = $barcode;

                    continue;
                }

                // Re-derive the pallet rather than trusting the posted state:
                // the bundle count drives the stock totals.
                $pallet = FactoryConversion::where('barcode', $barcode)->first();
                if (! $pallet) {
                    $skipped[] = $barcode;

                    continue;
                }

                $productid = (int) $pallet->productid;
                $bundles = (int) $pallet->bundles;

                // Date the receipt by when the pallet left the factory, so a
                // pallet scanned in the next morning still lands on its own day.
                $exitDate = $conn->table('factory_exit')
                    ->where('barcode', $barcode)->value('dateofexit');

                StoreEntrance::create([
                    'username' => $username,
                    'productid' => $productid,
                    'entrancelocation' => $this->entrancelocation,
                    'barcode' => $barcode,
                    'bundles' => $bundles,
                    'dateofentrance' => $exitDate ?: $fallbackDate,
                    'timestamp' => $now,
                ]);

                // Stock moves with the receipt, in the same transaction.
                FinishedGoodsStock::apply($productid, $bundles, $this->entrancelocation, $username, $now);

                // The pallet is received, and no longer on the factory floor.
                $conn->table('factory_exit')->where('barcode', $barcode)->update(['status' => 'yes']);
                $pallet->update(['status' => 'yes']);

                $saved++;
            }
        });

        $this->items = [];
        $this->scanError = '';

        if ($saved > 0) {
            session()->flash('ok', $saved . ' pallet' . ($saved === 1 ? '' : 's') . ' received into the warehouse.');
        }
        if ($skipped !== []) {
            session()->flash('err', count($skipped) . ' skipped (already received or no longer in conversion output): '
                . implode(', ', $skipped));
        }
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.warehouse-entrance');
    }
}
