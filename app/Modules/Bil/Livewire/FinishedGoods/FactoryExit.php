<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\FactoryConversion;
use Modules\Bil\Models\FactoryExit as FactoryExitModel;
use Modules\Core\Models\FactoryGate;
use Modules\Core\Support\GateAccess;

/**
 * BIL → Finished Goods → Factory Exit. Rebuild of the legacy
 * factory_exit_beta.php ("Item Send (to Warehouse)").
 *
 * Pallets are scanned as they pass the gate — the middle step of a pallet's
 * life: Conversion Output mints it, Factory Exit sends it, Warehouse Entrance
 * receives it.
 *
 * A barcode is accepted only if it exists in `factory_conversion` (it was
 * actually produced) and is not already in `factory_exit` (UNIQUE — a pallet
 * leaves once). Product and bundle count are read off the pallet, never typed.
 *
 * Gates come from `factory_gates` (direction `out`) and are limited to the
 * ones this user has been granted — see GateAccess. The legacy screen
 * hard-coded that by user level; it is now ticked per user in the user editor.
 * The exit writes both `exit_location_id` and the legacy `exitlocation` name,
 * because the legacy app and the 1.2M historic rows both read the name.
 */
#[Layout('core::layouts.admin')]
#[Title('Factory Exit')]
class FactoryExit extends Component
{
    public const PAGE_KEY = 'bil.finished_goods.factory_exit';

    /** The legacy screen laid out 25 barcode boxes per submit. */
    public const MAX_SCAN = 25;

    public ?int $exit_location_id = null;
    public string $dateIso = '';
    public string $scan = '';

    /** Scanned pallets pending save: [['barcode','productid','productname','bundles'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->exit_location_id = $this->locations()->first()?->id;
    }

    /** Exit gates this user may use, grouped under their factory in the picker. */
    #[Computed]
    public function locations()
    {
        return GateAccess::factoryGates(auth()->user(), FactoryGate::OUT);
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

    /** Validate a scanned barcode: produced, and not already out of the factory. */
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

        $exit = FactoryExitModel::where('barcode', $barcode)->first();
        if ($exit) {
            $when = $exit->timestamp
                ? Carbon::createFromTimestamp($exit->timestamp)->format('d M Y')
                : $exit->dateofexit;
            $this->scanError = 'Already exited on ' . $when . '.';

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
            ['exit_location_id' => 'required|integer'],
            ['exit_location_id.required' => 'Pick the gate the pallets are leaving through.']
        );

        // Re-resolve from the granted set, so a stale or tampered id can't book
        // an exit through a gate this user was never given.
        $location = $this->locations()->firstWhere('id', $this->exit_location_id);
        if (! $location) {
            session()->flash('err', 'That exit location is no longer available to you.');

            return;
        }

        // Non-backdaters always book against the shift date.
        $date = $this->canBackdate()
            ? str_replace('-', '/', $this->dateIso)
            : $this->shiftDate();
        $username = (string) (auth()->user()?->username ?? auth()->user()?->name ?? '');
        $now = now()->getTimestamp();

        $conn = DB::connection('bil');
        $saved = 0;
        $skipped = [];

        $conn->transaction(function () use ($conn, $location, $date, $username, $now, &$saved, &$skipped) {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];

                // Re-check server-side: the list was built when the pallet was
                // scanned, and another station may have booked it out since.
                // `barcode` is UNIQUE, so without this the whole batch would
                // roll back on one stale row.
                if (FactoryExitModel::where('barcode', $barcode)->exists()) {
                    $skipped[] = $barcode;

                    continue;
                }

                $pallet = FactoryConversion::where('barcode', $barcode)->first();
                if (! $pallet) {
                    $skipped[] = $barcode;

                    continue;
                }

                $received = $conn->table('store_entrance')->where('barcode', $barcode)->exists()
                    || DB::connection('core')->table('finished_goods_warehouse_receipts')
                        ->where('barcode', $barcode)->exists();

                FactoryExitModel::create([
                    'username' => $username,
                    'productid' => (int) $pallet->productid,
                    // The legacy name stays authoritative for the legacy app and
                    // for the 1.2M historic rows; the id is the new link.
                    'exitlocation' => $location->legacy_name ?: $location->name,
                    'exit_location_id' => $location->id,
                    'barcode' => $barcode,
                    'bundles' => (int) $pallet->bundles,
                    'dateofexit' => $date,
                    'status' => $received ? FactoryExitModel::RECEIVED : null,
                    'timestamp' => $now,
                ]);

                $pallet->update(['status' => 'yes']);

                $saved++;
            }
        });

        $this->items = [];
        $this->scanError = '';

        if ($saved > 0) {
            session()->flash('ok', $saved . ' pallet' . ($saved === 1 ? '' : 's') . ' sent to the warehouse.');
        }
        if ($skipped !== []) {
            session()->flash('err', count($skipped) . ' skipped (already exited or no longer in conversion output): '
                . implode(', ', $skipped));
        }
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.factory-exit');
    }
}
