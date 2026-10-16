<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplSoftrollWarehouseEntry as SoftrollReceipt;
use Modules\Bpl\Models\BplWaitingAreaEntry;
use Modules\Bpl\Models\BplWaitingAreaExit;
use Modules\Bpl\Models\BplWarehouseEntry as HardrollReceipt;
use Modules\Bpl\Support\JumboRollStock;
use Modules\Bpl\Support\RollResolver;
use Modules\Core\Models\WarehouseGate;
use Modules\Core\Support\GateAccess;

/**
 * BPL → Jumbo Rolls → Waiting Area.
 *
 * Rebuilt from the legacy `bpl_quarantine.php` and `bpl_quarantine_exit.php`
 * ("BPL Quarantine Entrance" / "Exit"), whose tables were renamed to say what
 * they hold — migration 2026_09_25_100000.
 *
 * WHAT IT IS. A roll that has LEFT a store — released at Warehouse Exit, onto
 * a truck that did not take it, or back from one — has to stand somewhere
 * until it goes out again. Booking it into the waiting area says which store
 * it is standing at and since when, and puts its weight back on that store's
 * stock; releasing it takes the weight off again. That is the legacy rule,
 * kept verbatim: the entry check refused anything not in the store-exit table
 * ("Barcode not found in store exit").
 *
 * ⚠️ **This screen MOVES STOCK, both ways.** Holding adds the roll's weight to
 * `bpl_hardroll_stock` / `bpl_softroll_stock`; releasing takes it off and
 * stamps the warehouse-entry row `Exited`, exactly as the legacy did. Each is
 * one transaction with its own row.
 *
 * Who may do it is a gate grant rather than a user level: holding needs an IN
 * gate on the store, releasing an OUT gate — the same grants Warehouse Entry
 * and Warehouse Exit use, replacing the legacy's hard-coded levels (45/46/44
 * on the way in, 38/39/40 on the way out).
 *
 * The legacy screens wrote exactly one row, in October 2025, and deleted it
 * the same day.
 */
#[Layout('core::layouts.admin')]
#[Title('BPL Waiting Area')]
class WaitingArea extends Component
{
    public const PAGE_KEY = 'bpl.jumbo_rolls.waiting_area';

    public const MAX_SCAN = 20;

    /** The warehouse module these stores hold — config/warehouses.php. */
    public const MODULE = 'jumbo-rolls';

    /** 'in' = holding rolls, 'out' = releasing them, 'held' = what is there now. */
    public string $mode = 'in';

    public string $dateIso = '';
    public ?int $gateId = null;
    public string $scan = '';

    /** Pending rolls: RollResolver candidates. */
    public array $items = [];

    public string $scanError = '';

    /** A barcode that reads as both streams, until the operator says which. */
    public array $ambiguous = [];

    public string $heldSearch = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->gateId = $this->gates()->first()?->id;

        // ?mode=held opens on what is standing there (the dashboard links here).
        if (request()->query('mode') === 'held') {
            $this->switchMode('held');
        }
    }

    /** Rolls held in a waiting area now — the count behind the dashboard card. */
    public static function heldQuery()
    {
        return DB::connection('bpl')->table('bpl_waiting_area_entry as w')
            ->whereNull('w.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_waiting_area_exit as x')
                ->whereColumn('x.barcode', 'w.barcode')->whereNull('x.deleted_at'));
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /* ---------------- Where ---------------- */

    /**
     * The stores this user may work at, on the side they are working.
     *
     * Holding is a receipt into the store (IN), releasing is a release from it
     * (OUT) — so the grants are the same ones the two store screens use.
     */
    #[Computed]
    public function gates()
    {
        return GateAccess::warehouseGates(
            auth()->user(),
            self::MODULE,
            $this->mode === 'out' ? WarehouseGate::OUT : WarehouseGate::IN
        );
    }

    protected function selectedGate()
    {
        return $this->gates()->firstWhere('id', $this->gateId);
    }

    public function storeName(?int $locationId = null): string
    {
        if ($locationId === null) {
            return (string) ($this->selectedGate()?->warehouse?->name ?? '');
        }

        return (string) (DB::connection('bpl')->table('bpl_stock_locations')
            ->where('id', $locationId)->value('location') ?? 'another store');
    }

    protected function selectedLocationId(): ?int
    {
        $id = $this->selectedGate()?->warehouse?->legacy_location_id;

        return $id ? (int) $id : null;
    }

    /* ---------------- Switching sides ---------------- */

    public function switchMode(string $mode): void
    {
        if (! in_array($mode, ['in', 'out', 'held'], true) || $mode === $this->mode) {
            return;
        }

        // Anything scanned for one side means nothing on the other, and the
        // gates are a different set — the store picked has to be chosen again.
        $this->items = [];
        $this->ambiguous = [];
        $this->scanError = '';
        $this->mode = $mode;

        unset($this->gates);
        $this->gateId = $this->gates()->first()?->id;
    }

    /**
     * Changing the store drops rolls scanned at the one before it.
     *
     * Without this, an operator who scans a trolley, notices the wrong store
     * and corrects it would move that stock at a store the rolls were never
     * in, and nothing afterwards would say so.
     */
    public function updatedGateId(): void
    {
        $this->ambiguous = [];

        $locationId = $this->selectedLocationId();
        $before = count($this->items);

        $this->items = array_values(array_filter(
            $this->items,
            fn ($item) => ($item['location_id'] ?? null) === null || (int) $item['location_id'] === $locationId
        ));

        $dropped = $before - count($this->items);
        $this->scanError = $dropped > 0
            ? $dropped . ' scanned roll' . ($dropped === 1 ? '' : 's') . ' removed — not at ' . $this->storeName() . '.'
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

        $candidates = $this->mode === 'out'
            ? RollResolver::inWaitingArea($barcode)
            : RollResolver::awaitingWaitingArea($barcode);

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

    /** Queue a candidate — releasing checks it is standing at THIS store. */
    protected function queue(array $candidate): void
    {
        if ($this->mode === 'out' && (int) ($candidate['location_id'] ?? 0) !== $this->selectedLocationId()) {
            $this->scanError = 'That roll is being held at ' . $this->storeName((int) $candidate['location_id'])
                . ', not ' . $this->storeName() . '.';

            return;
        }

        $this->items[] = $candidate;
    }

    protected function missMessage(string $barcode): string
    {
        $miss = RollResolver::explainWaitingAreaMiss($barcode, $this->mode === 'out');

        return match ($miss['reason']) {
            'already_held' => 'That roll is already in a waiting area' . ($miss['date'] ? ' since ' . $miss['date'] : '') . '.',
            'not_held' => 'That roll is not in a waiting area.',
            'not_out_of_store' => 'That roll has not left a store — only a roll released at Warehouse Exit waits here.',
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

        // It could have moved on another terminal while the question was up.
        $still = $this->mode === 'out'
            ? collect(RollResolver::inWaitingArea($chosen['barcode']))->firstWhere('stream', $stream)
            : collect(RollResolver::awaitingWaitingArea($chosen['barcode']))->firstWhere('stream', $stream);

        if (! $still) {
            $this->scanError = 'That roll has since moved.';

            return;
        }

        $this->queue($still);
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
        return round((float) array_sum(array_column($this->items, 'weight')), 2);
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
            session()->flash('err', 'That store has no legacy location id — the legacy BPL stock reports would not see this.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : now()->format('Y/m/d');
        $username = (string) (auth()->user()?->username ?? '');
        $releasing = $this->mode === 'out';
        $saved = 0;

        try {
            DB::connection('bpl')->transaction(function () use ($releasing, $locationId, $date, $username, &$saved) {
                foreach ($this->items as $item) {
                    // Belt and braces: the queue is filtered on scan and on
                    // store change, but stock moved at the wrong place is
                    // invisible afterwards, so it is checked once more here.
                    if ($releasing && (int) ($item['location_id'] ?? 0) !== $locationId) {
                        continue;
                    }

                    $releasing
                        ? $this->release($item, $locationId, $date, $username)
                        : $this->hold($item, $locationId, $date, $username);

                    $saved++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — please try again.');

            return;
        }

        $this->items = [];
        $this->scanError = '';
        unset($this->held);

        session()->flash('ok', $saved . ' roll' . ($saved === 1 ? '' : 's')
            . ($releasing ? ' released from the waiting area at ' : ' held in the waiting area at ')
            . $gate->warehouse->name . '.');
    }

    /**
     * Hold a roll: the row, and the store's stock.
     *
     * `barcode` is UNIQUE on both waiting-area tables, so a roll coming back
     * again rewrites its rows rather than colliding — and its previous release
     * has to go with it, or the roll would read as released the moment it was
     * held.
     */
    protected function hold(array $item, int $locationId, string $date, string $username): void
    {
        $this->writeRow(BplWaitingAreaEntry::class, $item['barcode'], $locationId, $date, $username);

        BplWaitingAreaExit::withTrashed()->where('barcode', $item['barcode'])->forceDelete();

        JumboRollStock::apply($locationId, $item, (float) $item['weight']);
    }

    /**
     * Release a roll: the row, the store's stock, and the receipt's status.
     *
     * The status stamp is what the legacy did on its way out
     * (`bpl_storeentrance.status = 'Exited'`), and it is what BPL Warehouse
     * Stock reads to decide a roll is no longer held.
     */
    protected function release(array $item, int $locationId, string $date, string $username): void
    {
        $this->writeRow(BplWaitingAreaExit::class, $item['barcode'], $locationId, $date, $username);

        BplWaitingAreaEntry::where('barcode', $item['barcode'])
            ->update(['status' => BplWaitingAreaEntry::RELEASED, 'updated_at' => now()]);

        JumboRollStock::apply($locationId, $item, -(float) $item['weight']);

        $receipt = $item['stream'] === RollResolver::SOFTROLL ? SoftrollReceipt::class : HardrollReceipt::class;
        $receipt::where('barcode', $item['barcode'])->update(['status' => 'Exited']);
    }

    /** One waiting-area row, written in place when the barcode is already there. */
    protected function writeRow(string $model, string $barcode, int $locationId, string $date, string $username): void
    {
        $row = [
            'user' => $username,
            'location_id' => $locationId,
            'date' => $date,
            'status' => null,
            'created_at' => now(),
            'updated_at' => null,
            'deleted_at' => null,
        ];

        $existing = $model::withTrashed()->where('barcode', $barcode)->first();

        $existing
            ? $existing->forceFill($row)->save()
            : $model::create($row + ['barcode' => $barcode]);
    }

    /* ---------------- What is standing there ---------------- */

    /**
     * Rolls in a waiting area now: held, not released.
     *
     * Read across both streams' production tables, because the waiting-area
     * tables know only a barcode.
     */
    #[Computed]
    public function held()
    {
        $term = trim($this->heldSearch);

        $rows = DB::connection('bpl')->table('bpl_waiting_area_entry as w')
            ->leftJoin('bpl_stock_locations as l', 'l.id', '=', 'w.location_id')
            ->leftJoin('bpl_production as h', 'h.barcode', '=', 'w.barcode')
            ->leftJoin('bpl_products_hardroll as hp', 'hp.id', '=', 'h.product_id')
            ->leftJoin('bpl_softroll_production as s', 's.barcode', '=', 'w.barcode')
            ->leftJoin('bpl_grades as sg', 'sg.id', '=', 's.grade_id')
            ->whereNull('w.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_waiting_area_exit as x')
                ->whereColumn('x.barcode', 'w.barcode')->whereNull('x.deleted_at'))
            ->when($term !== '', fn ($q) => $q->where('w.barcode', 'like', '%' . $term . '%'))
            ->orderBy('w.date')
            ->orderBy('w.id')
            ->limit(500)
            ->get([
                'w.id', 'w.barcode', 'w.date', 'w.user', 'w.location_id', 'l.location',
                'h.hardrollnumber', 'h.weight as hardroll_weight', 'hp.productname', 'hp.gradetype',
                's.softrollnumber', 's.weight as softroll_weight', 'sg.type as softroll_grade',
            ]);

        return $rows->map(function ($r) {
            $isSoft = $r->hardrollnumber === null && $r->softrollnumber !== null;

            return (object) [
                'id' => (int) $r->id,
                'barcode' => (string) $r->barcode,
                'stream' => $isSoft ? RollResolver::SOFTROLL : RollResolver::HARDROLL,
                'rollnumber' => (string) ($isSoft ? $r->softrollnumber : $r->hardrollnumber),
                'product' => (string) ($isSoft ? ($r->softroll_grade ?: '—') : ($r->productname ?: '—')),
                'weight' => (float) ($isSoft ? $r->softroll_weight : $r->hardroll_weight),
                'store' => (string) ($r->location ?: '—'),
                'date' => (string) $r->date,
                'user' => (string) $r->user,
                // How long it has been standing there, which is what anyone
                // looking at this list actually wants to know.
                'days' => $r->date
                    ? (int) \Illuminate\Support\Carbon::parse(str_replace('/', '-', $r->date))
                        ->startOfDay()->diffInDays(now()->startOfDay())
                    : null,
            ];
        });
    }

    public function heldWeight(): float
    {
        return round((float) $this->held->sum('weight'), 2);
    }

    public function render()
    {
        return view('bpl::livewire.jumbo-rolls.waiting-area');
    }
}
