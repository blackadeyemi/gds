<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplSoftrollWarehouseEntry as SoftrollReceipt;
use Modules\Bpl\Models\BplWarehouseEntry as HardrollReceipt;
use Modules\Bpl\Models\BplWarehouseTransfer;
use Modules\Bpl\Support\JumboRollStock;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * BPL → Jumbo Rolls → Warehouse Transfer.
 *
 * Moving a roll from one BPL warehouse to another. Rebuilt from the legacy
 * `bpl_store_transfer.php`, with three deliberate differences:
 *
 * 1. **No "current location" picker.** The legacy screen made the operator
 *    choose where the roll was coming FROM and then refused any barcode that
 *    was not there. The system already knows where every roll is — it is on the
 *    receipt row — so the scan tells us the source and the operator only says
 *    where it is going. That also means a trolley of rolls from two different
 *    warehouses can be moved in one submit; each is debited from its own.
 *
 * 2. **The move is recorded.** The legacy overwrote the receipt's
 *    `location_id` and left no trace the roll had ever been anywhere else.
 *    Every move now writes a `bpl_warehouse_transfer` row as well.
 *
 * 3. **Both streams.** The legacy had a hardroll screen only; a softroll in a
 *    warehouse is just as movable, and RollResolver already tells them apart.
 *
 * ⚠️ **This screen MOVES STOCK — twice per roll**, off the source and onto the
 * destination. Receipt update, both stock legs and the log row are one
 * transaction.
 */
class WarehouseTransfer extends Component
{
    public const PAGE_KEY = 'bpl.jumbo_rolls.warehouse_transfer';

    public const MAX_SCAN = 10;

    public const MODULE = 'jumbo-rolls';

    public string $dateIso = '';

    /** The destination gate. There is no source picker — see the class note. */
    public ?int $gateId = null;

    public string $scan = '';

    /** Pending rolls: RollResolver candidates, each carrying the warehouse it is in. */
    public array $items = [];

    public string $scanError = '';

    /** A barcode held in BOTH streams' warehouses, until the operator says which. */
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
     * Where a roll may be sent: the inbound gates on the jumbo-roll warehouses,
     * as granted to this user. Receiving into a warehouse is the same right
     * whether the roll came off a truck or off another warehouse's floor.
     */
    #[Computed]
    public function gates()
    {
        return GateAccess::warehouseGates(auth()->user(), self::MODULE, WarehouseGate::IN);
    }

    protected function destinationGate()
    {
        return $this->gates()->firstWhere('id', $this->gateId);
    }

    public function destinationName(): string
    {
        return (string) ($this->destinationGate()?->warehouse?->name ?? '');
    }

    protected function destinationLocationId(): ?int
    {
        $id = $this->destinationGate()?->warehouse?->legacy_location_id;

        return $id ? (int) $id : null;
    }

    /**
     * Changing the destination drops anything already queued that is now a
     * no-op — a roll cannot be transferred to the warehouse it is already in.
     */
    public function updatedGateId(): void
    {
        $this->ambiguous = [];

        $destination = $this->destinationLocationId();
        $before = count($this->items);

        $this->items = array_values(array_filter(
            $this->items,
            fn ($item) => (int) $item['location_id'] !== $destination
        ));

        $dropped = $before - count($this->items);
        $this->scanError = $dropped > 0
            ? $dropped . ' scanned roll' . ($dropped === 1 ? '' : 's') . ' removed — already in ' . $this->destinationName() . '.'
            : '';
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

        if (! $this->destinationLocationId()) {
            $this->scanError = 'Choose a destination first.';

            return;
        }

        // The same predicate Warehouse Exit uses: a roll in a warehouse and not
        // yet released. Transferring is a move between warehouses, so a roll
        // that has left them entirely is not a candidate.
        $candidates = RollResolver::inWarehouse($barcode);

        if (count($candidates) === 1) {
            $this->queue($candidates[0]);

            return;
        }

        if (count($candidates) > 1) {
            $this->ambiguous = $candidates;

            return;
        }

        $this->scanError = $this->missMessage($barcode);
    }

    /** Queue a candidate, unless it is already where it is being sent. */
    protected function queue(array $candidate): void
    {
        if ((int) $candidate['location_id'] === $this->destinationLocationId()) {
            $this->scanError = 'That roll is already in ' . $this->destinationName() . '.';

            return;
        }

        $this->items[] = $candidate;
    }

    protected function missMessage(string $barcode): string
    {
        $miss = RollResolver::explainWarehouseExitMiss($barcode);

        return match ($miss['reason']) {
            'already_out' => 'That roll left the warehouse on ' . ($miss['date'] ?: 'an earlier date') . ' — it cannot be transferred.',
            'not_received' => 'That roll is not in a warehouse.',
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

        $stillHeld = collect(RollResolver::inWarehouse($chosen['barcode']))->firstWhere('stream', $stream);

        if (! $stillHeld) {
            $this->scanError = 'That roll has since left the warehouse.';

            return;
        }

        $this->queue($stillHeld);
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

    /** The warehouse a queued roll is coming from, for the table's From column. */
    public function sourceName(int $locationId): string
    {
        return (string) (DB::connection('bpl')->table('bpl_stock_locations')
            ->where('id', $locationId)->value('location') ?? 'Location #' . $locationId);
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        $gate = $this->destinationGate();
        $destination = $this->destinationLocationId();

        if ($this->items === [] || ! $gate) {
            return;
        }

        if (! $destination) {
            session()->flash('err', 'That warehouse has no legacy location id — the legacy BPL stock reports would not see these moves.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : now()->format('Y/m/d');
        $username = (string) (auth()->user()?->username ?? '');
        $moved = 0;

        try {
            DB::connection('bpl')->transaction(function () use ($destination, $date, $username, &$moved) {
                foreach ($this->items as $item) {
                    // Belt and braces: a roll already at the destination would
                    // move stock off and back onto the same row for nothing.
                    if ((int) $item['location_id'] === $destination) {
                        continue;
                    }

                    $this->move($item, $destination, $date, $username);
                    $moved++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — the transfer could not be recorded. Please try again.');

            return;
        }

        $this->items = [];
        $this->scanError = '';

        session()->flash('ok', $moved . ' roll' . ($moved === 1 ? '' : 's') . ' transferred to ' . $gate->warehouse->name . '.');
    }

    /**
     * One roll's move: the receipt's location, both stock legs, and the log.
     *
     * All four inside the caller's transaction. The stock legs in particular
     * must not be separable — a debit without its credit is stock that has
     * vanished, and nothing recomputes these aggregates.
     */
    protected function move(array $item, int $destination, string $date, string $username): void
    {
        $source = (int) $item['location_id'];
        $isSoft = $item['stream'] === RollResolver::SOFTROLL;
        $receiptModel = $isSoft ? SoftrollReceipt::class : HardrollReceipt::class;

        $receiptModel::where('barcode', $item['barcode'])
            ->whereNull('deleted_at')
            ->update(['location_id' => $destination]);

        JumboRollStock::apply($source, $item, -(float) $item['weight']);
        JumboRollStock::apply($destination, $item, (float) $item['weight']);

        BplWarehouseTransfer::create([
            'stream' => $item['stream'],
            'barcode' => $item['barcode'],
            'from_location_id' => $source,
            'to_location_id' => $destination,
            'weight' => (float) $item['weight'],
            'user' => $username,
            'date' => $date,
            'created_at' => now(),
        ]);
    }

    #[Layout('core::layouts.admin')]
    #[Title('BPL Warehouse Transfer')]
    public function render()
    {
        return view('bpl::livewire.jumbo-rolls.warehouse-transfer');
    }
}
