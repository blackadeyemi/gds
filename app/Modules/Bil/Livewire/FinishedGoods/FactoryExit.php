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
use Modules\Bil\Models\FactoryExitLocation;

/**
 * BIL → Finished Goods → Factory Exit. Rebuild of the legacy
 * factory_exit_beta.php ("Item Send (to Warehouse)").
 *
 * Pallets are scanned as they pass the gate. A barcode is only accepted if it
 * exists in `factory_conversion` (it was actually produced) and is not already
 * in `factory_exit` (a pallet leaves once — the column is UNIQUE). Product and
 * bundle count are read off the conversion row, never typed.
 *
 * Saving does what the legacy Factory\Out::insertScanning did:
 *   - insert a `factory_exit` row per pallet, barcode upper-cased;
 *   - stamp `status` = 'yes' when the store already holds the barcode, which
 *     only happens on an out-of-order scan;
 *   - flip `factory_conversion.status` to 'yes' so the production screens stop
 *     showing the pallet as still on the floor.
 *
 * Date: `backdate` holders pick any date; everyone else books against the shift
 * date, where a scan before 07:00 still belongs to the previous day.
 */
#[Layout('core::layouts.admin')]
#[Title('Factory Exit')]
class FactoryExit extends Component
{
    public const PAGE_KEY = 'bil.finished_goods.factory_exit';

    /** The legacy screen laid out 25 barcode boxes per submit. */
    public const MAX_SCAN = 25;

    public string $exitlocation = '';
    public string $dateIso = '';
    public string $scan = '';

    /** Scanned pallets pending save: [['barcode','productname','bundles'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->exitlocation = (string) ($this->locations()->keys()->first() ?? '');
    }

    /** Gate names, grouped under their factory for the picker. */
    #[Computed]
    public function locations()
    {
        return FactoryExitLocation::orderBy('factoryname')->orderBy('exitlocation')
            ->get()->mapWithKeys(fn ($l) => [
                $l->exitlocation => $l->factoryname . ' — ' . $l->exitlocation,
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
     * Validate a scanned barcode: produced, and not already out of the factory.
     * Mirrors the legacy Barcode\Send::finishGoods lookup.
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
            ['exitlocation' => 'required|string'],
            ['exitlocation.required' => 'Pick the gate the pallets are leaving through.']
        );

        if (! $this->locations()->has($this->exitlocation)) {
            session()->flash('err', 'That exit location no longer exists.');

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

        $conn->transaction(function () use ($conn, $date, $username, $now, &$saved, &$skipped) {
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

                // Re-derive the pallet rather than trusting the posted state.
                $pallet = FactoryConversion::where('barcode', $barcode)->first();
                if (! $pallet) {
                    $skipped[] = $barcode;

                    continue;
                }

                $received = $conn->table('store_entrance')->where('barcode', $barcode)->exists();

                FactoryExitModel::create([
                    'username' => $username,
                    'productid' => (int) $pallet->productid,
                    'exitlocation' => $this->exitlocation,
                    'barcode' => $barcode,
                    'bundles' => (int) $pallet->bundles,
                    'dateofexit' => $date,
                    // Only set when the store somehow received it first; the
                    // store-entrance screen fills it in the normal order.
                    'status' => $received ? FactoryExitModel::RECEIVED : null,
                    'timestamp' => $now,
                ]);

                // The pallet is no longer on the factory floor.
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
