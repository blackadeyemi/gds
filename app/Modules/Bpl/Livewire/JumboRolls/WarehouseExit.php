<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplSoftrollWarehouseEntry as SoftrollReceipt;
use Modules\Bpl\Models\BplSoftrollWarehouseExit as SoftrollRelease;
use Modules\Bpl\Models\BplWarehouseEntry as HardrollReceipt;
use Modules\Bpl\Models\BplWarehouseExit as HardrollRelease;
use Modules\Bpl\Support\JumboRollStock;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * BPL → Jumbo Rolls → Store Exit.
 *
 * Rebuilt from the legacy `bpl_store_exit.php` AND `softroll_store_exit.php`.
 * One screen; the barcode decides the stream (Modules\Bpl\Support\RollResolver).
 *
 * A roll may be released when it is IN a store and has not already been
 * released. Unlike the two screens before it, this one also cares WHERE: a roll
 * can only be released from the store it is actually in. The legacy said
 * "Barcode not found in your store location", which does not tell the operator
 * where to go looking; this names the store.
 *
 * ⚠️ **This screen MOVES STOCK, downwards.** It takes the roll's weight back
 * off `bpl_hardroll_stock` / `bpl_softroll_stock`, and stamps the warehouse-entry row's
 * `status` so the roll stops counting as held. All three writes are one
 * transaction.
 */
class WarehouseExit extends Component
{
    public const PAGE_KEY = 'bpl.jumbo_rolls.warehouse_exit';

    public const MAX_SCAN = 10;

    public const MODULE = 'jumbo-rolls';

    public string $dateIso = '';
    public ?int $gateId = null;
    public string $scan = '';

    /** Pending rolls: RollResolver candidates, each carrying its store. */
    public array $items = [];

    public string $scanError = '';

    /** A barcode held in BOTH streams' stores, until the operator says which. */
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

    /** Outbound gates on the jumbo-roll stores, as granted to this user. */
    #[Computed]
    public function gates()
    {
        return GateAccess::warehouseGates(auth()->user(), self::MODULE, WarehouseGate::OUT);
    }

    /** The store currently selected, resolved from the granted set. */
    protected function selectedGate()
    {
        return $this->gates()->firstWhere('id', $this->gateId);
    }

    public function selectedStoreName(): string
    {
        return (string) ($this->selectedGate()?->warehouse?->name ?? '');
    }

    protected function selectedLocationId(): ?int
    {
        $id = $this->selectedGate()?->warehouse?->legacy_location_id;

        return $id ? (int) $id : null;
    }

    /**
     * Changing the store invalidates anything queued from the previous one.
     *
     * Without this, an operator who scans a trolley, notices the wrong store
     * and corrects it would release those rolls from a store they were never
     * in — the stock would come off the wrong place and nothing would say so.
     */
    public function updatedGateId(): void
    {
        $this->ambiguous = [];

        $locationId = $this->selectedLocationId();
        $before = count($this->items);

        $this->items = array_values(array_filter(
            $this->items,
            fn ($item) => (int) $item['location_id'] === $locationId
        ));

        $dropped = $before - count($this->items);
        $this->scanError = $dropped > 0
            ? $dropped . ' scanned roll' . ($dropped === 1 ? '' : 's') . ' removed — not in ' . $this->selectedStoreName() . '.'
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

        if (! $this->selectedLocationId()) {
            $this->scanError = 'Choose a store first.';

            return;
        }

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

    /** Queue a candidate, unless it is sitting in a different store. */
    protected function queue(array $candidate): void
    {
        if ((int) $candidate['location_id'] !== $this->selectedLocationId()) {
            $this->scanError = 'That roll is in ' . $this->storeName((int) $candidate['location_id'])
                . ', not ' . $this->selectedStoreName() . '.';

            return;
        }

        $this->items[] = $candidate;
    }

    /** A legacy store location's name, for an error that names where to look. */
    protected function storeName(int $locationId): string
    {
        return (string) (DB::connection('bpl')->table('bpl_stock_locations')
            ->where('id', $locationId)->value('location') ?? 'another store');
    }

    protected function missMessage(string $barcode): string
    {
        $miss = RollResolver::explainWarehouseExitMiss($barcode);

        return match ($miss['reason']) {
            'already_out' => 'Entry already made for ' . $barcode . ($miss['date'] ? ' on ' . $miss['date'] : '') . '.',
            'not_received' => 'That roll is not in a store.',
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

        // It could have been released on another terminal meanwhile.
        $stillHeld = collect(RollResolver::inWarehouse($chosen['barcode']))->firstWhere('stream', $stream);

        if (! $stillHeld) {
            $this->scanError = 'That roll has since left the store.';

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

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        $gate = $this->selectedGate();
        $locationId = $this->selectedLocationId();

        if ($this->items === [] || ! $gate) {
            return;
        }

        if (! $locationId) {
            session()->flash('err', 'That store has no legacy location id — the legacy BPL stock reports would not see these releases.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : now()->format('Y/m/d');
        $username = (string) (auth()->user()?->username ?? '');
        $saved = 0;

        try {
            DB::connection('bpl')->transaction(function () use ($locationId, $date, $username, &$saved) {
                foreach ($this->items as $item) {
                    // Belt and braces: the queue is filtered on scan and on
                    // store change, but stock coming off the wrong place is
                    // invisible afterwards, so it is checked once more here.
                    if ((int) $item['location_id'] !== $locationId) {
                        continue;
                    }

                    $this->release($item, $locationId, $date, $username);
                    $saved++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — the release could not be recorded. Please try again.');

            return;
        }

        $this->items = [];
        $this->scanError = '';

        session()->flash('ok', $saved . ' roll' . ($saved === 1 ? '' : 's') . ' released from ' . $gate->warehouse->name . '.');
    }

    /**
     * One roll's release: the row, the store's stock, and the receipt's status.
     *
     * All three inside the caller's transaction. The receipt status is what the
     * Jumbo Rolls Stock page reads to decide a roll is still held, so a release
     * that wrote the row but not the status would leave the roll counted in two
     * places at once.
     */
    protected function release(array $item, int $locationId, string $date, string $username): void
    {
        $isSoft = $item['stream'] === RollResolver::SOFTROLL;
        $releaseModel = $isSoft ? SoftrollRelease::class : HardrollRelease::class;
        $receiptModel = $isSoft ? SoftrollReceipt::class : HardrollReceipt::class;

        $row = [
            'user' => $username,
            'location_id' => $locationId,
            'date' => $date,
            'status' => null,
            'created_at' => now(),
            'deleted_at' => null,
        ];

        // `barcode` is UNIQUE on both tables, so a roll whose release was
        // deleted is released again IN PLACE.
        $existing = $releaseModel::withTrashed()->where('barcode', $item['barcode'])->first();

        if ($existing) {
            $existing->forceFill($row)->save();
        } else {
            $releaseModel::create($row + ['barcode' => $item['barcode']]);
        }

        JumboRollStock::apply($locationId, $item, -(float) $item['weight']);

        $receiptModel::where('barcode', $item['barcode'])->update(['status' => 'Exited']);
    }

    #[Layout('core::layouts.admin')]
    #[Title('BPL Warehouse Exit')]
    public function render()
    {
        return view('bpl::livewire.jumbo-rolls.warehouse-exit');
    }
}
