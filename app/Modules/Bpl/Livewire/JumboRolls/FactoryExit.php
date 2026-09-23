<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplFactoryExit as HardrollExit;
use Modules\Bpl\Models\BplSoftrollFactoryExit as SoftrollExit;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\FactoryGate;
use Modules\Core\Support\GateAccess;

/**
 * BPL → Jumbo Rolls → Factory Exit.
 *
 * Rebuilt from the legacy `bpl_factory_exit.php` AND `softroll_factory_exit.php`
 * — TWO screens in the flat app, writing to two tables, with nothing but the
 * operator's choice of menu item deciding which. A scan on the wrong page
 * succeeded silently against the twin roll, because the two streams shared a
 * barcode namespace.
 *
 * This is ONE screen. The barcode decides the stream
 * (Modules\Bpl\Support\RollResolver), and on the rare occasion it genuinely
 * cannot, the operator is asked with both rolls in front of them rather than
 * guessing from a menu.
 *
 * A reel leaving writes its exit row and flips the production row's `status` to
 * 'Exited' — the same two writes the legacy Bpl\factoryExit made, so the flat
 * app's stock reports stay true.
 */
class FactoryExit extends Component
{
    public const PAGE_KEY = 'bpl.jumbo_rolls.factory_exit';

    /** Barcodes per submit, matching the other scanning screens. */
    public const MAX_SCAN = 10;

    public string $dateIso = '';
    public ?int $gateId = null;
    public string $scan = '';

    /** Pending rolls: RollResolver rows, plus nothing else. */
    public array $items = [];

    public string $scanError = '';

    /**
     * A scanned barcode that matched a roll in BOTH streams, held until the
     * operator says which. Empty the rest of the time.
     *
     * @var array<int, array>
     */
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
     * Outbound gates on the Belpapyrus paper machines, as granted to this user.
     *
     * Replaces the legacy `userlevel === 42 ? 'PM2' : 'PM3'` filter that was
     * hard-coded into the page's JavaScript.
     */
    #[Computed]
    public function gates()
    {
        return GateAccess::factoryGates(auth()->user(), FactoryGate::OUT, 'BPL');
    }

    /**
     * How many softrolls still on the floor carry a pre-cut-over `M` barcode.
     *
     * Surfaced because it is the whole reason this screen has a disambiguation
     * step: when it reaches zero the barcode alone is always enough, and the
     * fallback can be removed. Nobody would otherwise notice.
     */
    #[Computed]
    public function legacyBacklog(): int
    {
        return RollResolver::softrollBacklog();
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

        $candidates = RollResolver::onFloor($barcode);

        if (count($candidates) === 1) {
            $this->items[] = $candidates[0];

            return;
        }

        if (count($candidates) > 1) {
            // Both streams have a roll of this barcode still standing. Nothing
            // in the code can choose; the weights are wildly different, so the
            // operator can.
            $this->ambiguous = $candidates;

            return;
        }

        $miss = RollResolver::explainMiss($barcode);
        $this->scanError = $miss['reason'] === 'exited'
            ? 'Entry already made for ' . $barcode . ($miss['date'] ? ' on ' . $miss['date'] : '') . '.'
            : 'Barcode not found in production.';
    }

    /** Resolve an ambiguous scan the operator's way. */
    public function chooseStream(string $stream): void
    {
        $chosen = collect($this->ambiguous)->firstWhere('stream', $stream);
        $this->ambiguous = [];

        if (! $chosen) {
            return;
        }

        // It could have been exited on another terminal while the question was
        // on screen, so check it is still standing rather than trusting the
        // held copy.
        $stillThere = collect(RollResolver::onFloor($chosen['barcode']))->firstWhere('stream', $stream);

        if (! $stillThere) {
            $this->scanError = 'That roll has since left the factory.';

            return;
        }

        $this->items[] = $stillThere;
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
        // reels out of a machine this user was never given.
        $gate = $this->gates()->firstWhere('id', $this->gateId);

        if ($this->items === [] || ! $gate) {
            return;
        }

        $locationId = $this->legacyLocationId($gate);
        if (! $locationId) {
            session()->flash('err', 'That gate does not map to a BPL stock location — the legacy BPL screens would not see these exits.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : now()->format('Y/m/d');
        $username = (string) (auth()->user()?->username ?? '');
        $saved = 0;

        try {
            DB::connection('bpl')->transaction(function () use ($locationId, $date, $username, &$saved) {
                foreach ($this->items as $item) {
                    $this->recordExit($item, $locationId, $date, $username);
                    $saved++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — the exit could not be recorded. Please try again.');

            return;
        }

        $this->items = [];
        $this->scanError = '';
        unset($this->legacyBacklog);

        session()->flash('ok', $saved . ' roll' . ($saved === 1 ? '' : 's') . ' booked out of ' . $gate->factory->name . '.');
    }

    /**
     * One roll's exit: the exit row, then the production row's status.
     *
     * `barcode` is UNIQUE on both exit tables, so a roll whose exit was deleted
     * is re-exited IN PLACE — an insert would collide. That is the legacy
     * Bpl\Movement::save() contract, kept because the flat app still deletes
     * exits.
     */
    protected function recordExit(array $item, int $locationId, string $date, string $username): void
    {
        $isSoft = $item['stream'] === RollResolver::SOFTROLL;

        $exitModel = $isSoft ? SoftrollExit::class : HardrollExit::class;
        $productionTable = $isSoft ? 'bpl_softroll_production' : 'bpl_production';

        $row = [
            'user' => $username,
            'location_id' => $locationId,
            'date' => $date,
            'created_at' => now(),
            'deleted_at' => null,
        ];

        $existing = $exitModel::withTrashed()->where('barcode', $item['barcode'])->first();

        if ($existing) {
            $existing->forceFill($row)->save();
        } else {
            $exitModel::create($row + ['barcode' => $item['barcode']]);
        }

        DB::connection('bpl')->table($productionTable)
            ->where('id', $item['id'])
            ->update(['status' => 'Exited']);
    }

    /**
     * The `bpl_stock_locations.id` a gate stands for.
     *
     * `bpl_factoryexit.location_id` holds that legacy id and the flat PHP app
     * reads it, so the gate has to resolve back to it. The gate's `legacy_name`
     * carries the legacy location name ('PM2' / 'PM3'), set by migration
     * 2026_09_16_100000; the factory code is the fallback for a gate created by
     * hand without one.
     */
    protected function legacyLocationId(FactoryGate $gate): ?int
    {
        $name = $gate->legacy_name ?: $gate->factory?->code;

        if (! $name) {
            return null;
        }

        $id = DB::connection('bpl')->table('bpl_stock_locations')
            ->where('location', $name)
            ->where('type', 0)
            ->value('id');

        return $id ? (int) $id : null;
    }

    #[Layout('core::layouts.admin')]
    #[Title('BPL Factory Exit')]
    public function render()
    {
        return view('bpl::livewire.jumbo-rolls.factory-exit');
    }
}
