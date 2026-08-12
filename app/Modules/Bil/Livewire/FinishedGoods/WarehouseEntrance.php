<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\FactoryExit as FactoryExitModel;
use Modules\Bil\Models\FgWarehouseReceipt;
use Modules\Bil\Support\FinishedGoodsStock;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * BIL → Finished Goods → Warehouse Entrance. Rebuilt from the legacy
 * store_entrance_beta.php ("Item Receive"), onto the new warehouse model.
 *
 * THE PIPELINE IS NOW A STRICT CHAIN
 * Conversion Output → Factory Exit → Warehouse Entrance. Factory Exit validates
 * against `factory_conversion`; this validates against **`factory_exit`**. A
 * pallet that never left the factory cannot be received, so the three stages can
 * no longer disagree about where a pallet is.
 *
 * That is a deliberate change from the legacy screen, which validated against
 * production and let the warehouse receive a pallet whose gate scan was missed —
 * the reason `factory_exit.status` had an out-of-order case at all. Missed exits
 * must now be scanned at the gate first.
 *
 * RECEIVING MOVES STOCK. Bundles land in `finished_goods_warehouse_stock` for the
 * warehouse behind the chosen entrance. Unlike the legacy totals this one is
 * derivable — every bundle has a receipt behind it — so `bil:reconcile-fg-stock`
 * can prove or repair it. See Modules\Bil\Support\FinishedGoodsStock.
 *
 * `date_of_entrance` keeps the legacy rule: the pallet's EXIT date, so a pallet
 * scanned in the next morning still lands on the day it left the factory. With
 * the exit now mandatory that date always exists.
 */
#[Layout('core::layouts.admin')]
#[Title('Warehouse Entrance')]
class WarehouseEntrance extends Component
{
    public const PAGE_KEY = 'bil.finished_goods.warehouse_entrance';

    /** The legacy screen laid out 25 barcode boxes per submit. */
    public const MAX_SCAN = 25;

    public ?int $entrance_id = null;
    public string $dateIso = '';
    public string $scan = '';

    /** Scanned pallets pending save: [['barcode','productid','productname','bundles','exitDate']]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->entrance_id = $this->entrances()->first()?->id;
    }

    /** Entrances this user may receive through, each with its warehouse. */
    #[Computed]
    public function entrances()
    {
        return GateAccess::warehouseGates(auth()->user(), 'finished-goods', WarehouseGate::IN);
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
     * Validate a scanned barcode: it must have LEFT THE FACTORY, and not have
     * been received already — in the new receipts table or the legacy one, since
     * both are real during the cut-over.
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

        $receipt = FgWarehouseReceipt::where('barcode', $barcode)->first();
        if ($receipt) {
            $this->scanError = 'Already received on ' . $receipt->date_of_entrance->format('d M Y') . '.';

            return;
        }

        // Receipts made by the legacy app still count as received.
        $legacy = DB::connection('bil')->table('store_entrance')
            ->where('barcode', $barcode)->value('timestamp');
        if ($legacy !== null) {
            $this->scanError = 'Already received on ' . Carbon::createFromTimestamp((int) $legacy)->format('d M Y')
                . ' (legacy screen).';

            return;
        }

        $exit = FactoryExitModel::with('product')->where('barcode', $barcode)->first();
        if (! $exit) {
            // Say which step is missing rather than just "not found" — the
            // operator can then get it scanned out and come back.
            $produced = DB::connection('bil')->table('factory_conversion')
                ->where('barcode', $barcode)->exists();

            $this->scanError = $produced
                ? 'Not yet sent from the factory — scan it at Factory Exit first.'
                : 'Barcode not found in conversion output.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productid' => (int) $exit->productid,
            'productname' => $exit->product->productname ?? '—',
            'bundles' => (int) $exit->bundles,
            'exitDate' => (string) $exit->dateofexit,
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

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y-m-d');
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        if ($this->items === []) {
            return;
        }

        $this->validate(
            ['entrance_id' => 'required|integer'],
            ['entrance_id.required' => 'Pick the entrance the pallets are coming in through.']
        );

        // Re-resolve from the granted set, so a stale or tampered id can't
        // receive through an entrance this user was never given — and cannot
        // move a warehouse's stock they have no business touching.
        $entrance = $this->entrances()->firstWhere('id', $this->entrance_id);
        if (! $entrance) {
            session()->flash('err', 'That entrance is no longer available to you.');

            return;
        }

        // `usable()` already guarantees this, but the stock movement depends on
        // it, so it is asserted rather than assumed.
        if (! $entrance->warehouse_id) {
            session()->flash('err', 'That entrance is not attached to a warehouse yet.');

            return;
        }

        $fallbackDate = $this->canBackdate() ? $this->dateIso : $this->shiftDate();
        $user = auth()->user();
        $username = (string) ($user?->username ?? $user?->name ?? '');

        $saved = 0;
        $skipped = [];

        DB::connection('core')->transaction(function () use ($entrance, $fallbackDate, $user, $username, &$saved, &$skipped) {
            foreach ($this->items as $item) {
                $barcode = $item['barcode'];

                // Re-check server-side: another station may have received it
                // since it was scanned. `barcode` is UNIQUE, so without this the
                // whole batch would roll back on one stale row.
                if (FgWarehouseReceipt::where('barcode', $barcode)->exists()) {
                    $skipped[] = $barcode;

                    continue;
                }

                // Re-derive from the exit rather than trusting the posted state:
                // the bundle count drives the stock total.
                $exit = FactoryExitModel::where('barcode', $barcode)->first();
                if (! $exit) {
                    $skipped[] = $barcode;

                    continue;
                }

                $productid = (int) $exit->productid;
                $bundles = (int) $exit->bundles;

                FgWarehouseReceipt::create([
                    'barcode' => $barcode,
                    'entrance_id' => $entrance->id,
                    // Denormalised so the receipt keeps pointing at the right
                    // stock even if the entrance later moves warehouse.
                    'warehouse_id' => $entrance->warehouse_id,
                    'productid' => $productid,
                    'bundles' => $bundles,
                    // The exit date is authoritative; the form date is only a
                    // fallback, which the mandatory exit makes near-unreachable.
                    'date_of_entrance' => $this->exitDate($exit->dateofexit) ?? $fallbackDate,
                    'user_id' => $user?->userid,
                    'username' => $username,
                ]);

                FinishedGoodsStock::apply($entrance->warehouse_id, $productid, $bundles);

                // Keep the legacy pipeline columns honest: the pallet is
                // received and no longer on the factory floor.
                DB::connection('bil')->table('factory_exit')
                    ->where('barcode', $barcode)->update(['status' => 'yes']);
                DB::connection('bil')->table('factory_conversion')
                    ->where('barcode', $barcode)->update(['status' => 'yes']);

                $saved++;
            }
        });

        $this->items = [];
        $this->scanError = '';

        if ($saved > 0) {
            session()->flash('ok', $saved . ' pallet' . ($saved === 1 ? '' : 's')
                . ' received into ' . ($entrance->warehouse?->name ?? 'the warehouse') . '.');
        }
        if ($skipped !== []) {
            session()->flash('err', count($skipped) . ' skipped (already received, or the exit was removed): '
                . implode(', ', $skipped));
        }
    }

    /** Legacy `Y/m/d` exit date → the ISO date the new column stores. */
    protected function exitDate(?string $legacy): ?string
    {
        $legacy = trim((string) $legacy);
        if ($legacy === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y/m/d', $legacy)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.warehouse-entrance');
    }
}
