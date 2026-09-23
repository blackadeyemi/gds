<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplSoftrollWarehouseEntry as SoftrollReceipt;
use Modules\Bpl\Models\BplWarehouseEntry as HardrollReceipt;
use Modules\Bpl\Support\JumboRollStock;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * BPL → Jumbo Rolls → Store Entrance.
 *
 * Rebuilt from the legacy `bpl_store_entrance.php` AND
 * `softroll_storeentrance.php` — again two screens in the flat app, again with
 * nothing but the menu deciding which table a scan landed in. One screen here;
 * the barcode decides (Modules\Bpl\Support\RollResolver).
 *
 * A roll may be received once it has LEFT the factory and is not already in a
 * store. That is the legacy rule kept verbatim, including its "Barcode not
 * found in factory exit" refusal.
 *
 * ⚠️ **This screen MOVES STOCK.** Unlike Factory Exit, which only records where
 * a roll went, receiving adds the roll's weight to a maintained aggregate
 * (`bpl_hardroll_stock` / `bpl_softroll_stock`) that nothing recomputes. The receipt row and
 * the stock movement are written in one transaction for that reason — see
 * Modules\Bpl\Support\JumboRollStock.
 */
class WarehouseEntry extends Component
{
    public const PAGE_KEY = 'bpl.jumbo_rolls.warehouse_entry';

    public const MAX_SCAN = 10;

    /** The warehouse module these stores hold — config/warehouses.php. */
    public const MODULE = 'jumbo-rolls';

    public string $dateIso = '';
    public ?int $gateId = null;
    public string $scan = '';

    /** Pending rolls: RollResolver candidates. */
    public array $items = [];

    public string $scanError = '';

    /** A barcode awaiting a store in BOTH streams, held until the operator says which. */
    public array $ambiguous = [];

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->gateId = $this->gates()->first()?->id;
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /**
     * Inbound gates on the jumbo-roll stores, as granted to this user.
     *
     * Replaces the legacy JavaScript filter — userlevel 45 saw only PM2 Store,
     * 46 only PM3 Store, 44 only Waste Paper Store.
     */
    #[Computed]
    public function gates()
    {
        return GateAccess::warehouseGates(auth()->user(), self::MODULE, WarehouseGate::IN);
    }

    /* ---------------- Scanning ---------------- */

    public function addScan(): void
    {
        $this->scanError = '';
        $this->ambiguous = [];

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
            $this->scanError = 'You can only scan ' . self::MAX_SCAN . ' barcodes per submit.';

            return;
        }

        $candidates = RollResolver::awaitingWarehouse($barcode);

        if (count($candidates) === 1) {
            $this->items[] = $candidates[0];

            return;
        }

        if (count($candidates) > 1) {
            $this->ambiguous = $candidates;

            return;
        }

        $this->scanError = $this->missMessage($barcode);
    }

    /**
     * Three distinct refusals where the legacy screen gave two — "not found in
     * factory exit" covered both a roll that never left and a barcode nobody
     * has ever heard of, which are different problems for the operator.
     */
    protected function missMessage(string $barcode): string
    {
        $miss = RollResolver::explainWarehouseEntryMiss($barcode);

        return match ($miss['reason']) {
            'in_store' => 'Entry already made for ' . $barcode . ($miss['date'] ? ' on ' . $miss['date'] : '') . '.',
            'not_exited' => 'That roll has not been booked out of the factory yet.',
            default => 'Barcode not found in production.',
        };
    }

    public function chooseStream(string $stream): void
    {
        $chosen = collect($this->ambiguous)->firstWhere('stream', $stream);
        $this->ambiguous = [];

        if (! $chosen) {
            return;
        }

        // It could have been received on another terminal while the question
        // was on screen.
        $stillWaiting = collect(RollResolver::awaitingWarehouse($chosen['barcode']))->firstWhere('stream', $stream);

        if (! $stillWaiting) {
            $this->scanError = 'That roll has since been received into a store.';

            return;
        }

        $this->items[] = $stillWaiting;
    }

    public function cancelAmbiguous(): void
    {
        $this->ambiguous = [];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function totalWeight(): float
    {
        return (float) array_sum(array_column($this->items, 'weight'));
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        // Re-resolve from the granted set: a stale or tampered id must not book
        // stock into a store this user was never given.
        $gate = $this->gates()->firstWhere('id', $this->gateId);

        if ($this->items === [] || ! $gate) {
            return;
        }

        $warehouse = $gate->warehouse;
        $locationId = $warehouse?->legacy_location_id;

        if (! $locationId) {
            session()->flash('err', 'That store has no legacy location id — the legacy BPL stock reports would not see these receipts.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : now()->format('Y/m/d');
        $username = (string) (auth()->user()?->username ?? '');
        $saved = 0;

        try {
            DB::connection('bpl')->transaction(function () use ($locationId, $date, $username, &$saved) {
                foreach ($this->items as $item) {
                    $this->receive($item, (int) $locationId, $date, $username);
                    $saved++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — the receipt could not be recorded. Please try again.');

            return;
        }

        $this->items = [];
        $this->scanError = '';

        session()->flash('ok', $saved . ' roll' . ($saved === 1 ? '' : 's') . ' received into ' . $warehouse->name . '.');
    }

    /**
     * One roll's receipt: the row, then the store's stock.
     *
     * Both inside the caller's transaction. A receipt written without its stock
     * movement is drift nothing can find later, which is why this is not two
     * separate calls from save().
     */
    protected function receive(array $item, int $locationId, string $date, string $username): void
    {
        $isSoft = $item['stream'] === RollResolver::SOFTROLL;
        $model = $isSoft ? SoftrollReceipt::class : HardrollReceipt::class;

        $row = [
            'user' => $username,
            'location_id' => $locationId,
            'date' => $date,
            'status' => null,
            'created_at' => now(),
            'deleted_at' => null,
        ];

        // `barcode` is UNIQUE on both tables, so a roll whose receipt was
        // deleted is received again IN PLACE — an insert would collide.
        $existing = $model::withTrashed()->where('barcode', $item['barcode'])->first();

        if ($existing) {
            $existing->forceFill($row)->save();
        } else {
            $model::create($row + ['barcode' => $item['barcode']]);
        }

        JumboRollStock::apply($locationId, $item, (float) $item['weight']);
    }

    #[Layout('core::layouts.admin')]
    #[Title('BPL Warehouse Entry')]
    public function render()
    {
        return view('bpl::livewire.jumbo-rolls.warehouse-entry');
    }
}
