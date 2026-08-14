<?php

namespace Modules\Bil\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\JumboRollFactoryEntrance;
use Modules\Core\Concerns\EnforcesShift;
use Modules\Core\Models\FactoryGate;
use Modules\Core\Support\GateAccess;

/**
 * Jumbo Rolls → Factory Entrance. Rebuilt from the legacy
 * `factory_entrance.php?type=jumboreel` screen (Barcode\Entrance@jumboreel for
 * the scan, Factory\Jumboreel\Entrance@processEntry for the save).
 *
 * Jumbo rolls are made by Belpapyrus on the paper machines and sold to
 * Belimpex; this is the moment they arrive at a Belimpex factory. Scan the
 * reel barcodes at the gate, then Save writes one row per reel to
 * `factory_entrance_reel` and puts its weight onto the factory floor stock.
 *
 * A scan is accepted when the barcode is a Belimpex reel in BPL production and
 * has not already been entered.
 */
class FactoryEntrance extends Component
{
    use EnforcesShift;

    /** Gated by the Jumbo Rolls Factory Entrance shift window. */
    public function shiftKey(): ?string
    {
        return 'bil.jumbo_rolls.factory_entrance';
    }

    public const PAGE_KEY = 'bil.jumbo_rolls.factory_entrance';

    public const MAX_SCAN = 10; // barcodes per submit

    /**
     * Where received reels land in `jumboreel_stock`.
     *
     * The legacy code mapped the entrance location to a SITE rather than a
     * factory — "Oregun Store" to Oregun, everything else to Ogba. All three
     * Belimpex factories (Bil-1, Bil-2, Gambini) are on the Ogba site, and
     * Oregun is a store rather than a factory, so it is not a gate this screen
     * can offer.
     */
    private const STOCK_SITE = 'Ogba';

    public string $dateIso = '';
    public ?int $gateId = null;
    public string $scan = '';

    /** Scanned reels pending save: [['barcode','hardrollnumber','productname','product_id','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

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
     * Inbound gates of the Belimpex factories, as granted to this user.
     *
     * The legacy dropdown listed every row of `factoryentrance_details`,
     * including the PM2/PM3 gates — but those belong to the Belpapyrus paper
     * machines that MAKE the reels, so a reel can never arrive there. Only
     * this company's gates are offered.
     */
    #[Computed]
    public function gates()
    {
        return GateAccess::factoryGates(auth()->user(), FactoryGate::IN, config('bil.company_code'));
    }

    /** Legacy shift date: a scan before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    /** Validate a scanned reel barcode and add it to the pending list. */
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
            $this->scanError = 'You can only scan ' . self::MAX_SCAN . ' barcodes per submit.';

            return;
        }

        // Already on a factory floor (or used there): a reel enters once.
        $entrance = JumboRollFactoryEntrance::where('barcode', $barcode)
            ->where('is_deleted', 0)->first(['timestamp']);
        if ($entrance) {
            $this->scanError = 'Entry already made for ' . $barcode
                . ' on ' . date('d/m/Y', (int) $entrance->timestamp) . '.';

            return;
        }

        $reel = $this->productionReel($barcode);
        if (! $reel) {
            $this->scanError = 'Barcode not found in production.';

            return;
        }

        $this->items[] = [
            'barcode' => $barcode,
            'hardrollnumber' => $reel->hardrollnumber ?? '—',
            'productname' => $reel->productname ?? '—',
            'product_id' => (int) $reel->product_id,
            'weight' => (float) $reel->weight,
        ];
    }

    /** The BPL production record for a reel sold to this company. */
    protected function productionReel(string $barcode)
    {
        return DB::connection('bpl')->table('bpl_production as prod')
            ->leftJoin('bpl_products_hardroll as p', 'prod.product_id', '=', 'p.id')
            ->where('prod.barcode', $barcode)
            ->where('prod.customer_id', (int) config('bil.jumbo_roll_customer_id'))
            ->whereNull('prod.deleted_at')
            ->select('prod.product_id', 'prod.weight', 'prod.hardrollnumber', 'p.productname')
            ->first();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /** Total weight of the pending reels, for the operator to sanity-check. */
    public function totalWeight(): float
    {
        return (float) array_sum(array_column($this->items, 'weight'));
    }

    /** Record each scanned reel's factory entrance. */
    public function save(): void
    {
        // Re-resolve from the granted set: a stale or tampered id must not be
        // able to book reels into a factory this user was never given.
        $gate = $this->gates()->firstWhere('id', $this->gateId);

        if ($this->items === [] || ! $gate || ! $this->ensureShiftOpen()) {
            return;
        }

        // What the legacy screens read: the factory NAME, from the gate's factory.
        $location = $gate->factory?->code;
        if (! $location) {
            session()->flash('err', 'That gate has no factory code — the legacy jumbo screens would not see these entries.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : $this->shiftDate();
        $username = auth()->user()?->username ?? '';
        $now = time();

        $conn = DB::connection('bil');
        $entered = 0;

        try {
            $conn->transaction(function () use ($conn, $gate, $location, $date, $username, $now, &$entered) {
                foreach ($this->items as $item) {
                    $row = [
                        'user' => $username,
                        'location' => $location,
                        'gate_id' => $gate->id,
                        'dateofentrance' => $date,
                        'timestamp' => $now,
                        'is_deleted' => 0,
                    ];

                    // A reel whose entrance was deleted is re-entered in place:
                    // `barcode` is unique, so a second insert cannot happen.
                    $deleted = JumboRollFactoryEntrance::where('barcode', $item['barcode'])
                        ->where('is_deleted', 1)->exists();

                    if ($deleted) {
                        JumboRollFactoryEntrance::where('barcode', $item['barcode'])->update($row);
                    } else {
                        JumboRollFactoryEntrance::create($row + ['barcode' => $item['barcode']]);
                    }

                    $this->closeBplExit($conn, $item['barcode'], $date);
                    $this->addToFloorStock($conn, $item, $username, $now);
                    $entered++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — the entrance could not be recorded. Please try again.');

            return;
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $entered . ' reel' . ($entered === 1 ? '' : 's') . ' entered into ' . $gate->factory->name . '.');
    }

    /**
     * Close the handshake on the BPL side: mark the reel's factory exit as
     * received here.
     *
     * BPL records a reel leaving; BIL records it arriving; until now nothing
     * joined the two, so "shipped but never received" could only be found by an
     * anti-join over 130k exits and no row ever said it was outstanding. This
     * is what keeps the In Transit position on the Stock page true — without
     * it, every reel this screen receives would stay in transit forever.
     *
     * Best-effort and non-fatal: a reel can legitimately have no exit row (the
     * gate scan is the record that matters here), and a reel that never left
     * BPL on paper must still be receivable.
     */
    protected function closeBplExit($conn, string $barcode, string $date): void
    {
        $conn->table('bpl_factoryexit')
            ->where('barcode', $barcode)
            ->whereNull('received_at')
            ->whereNull('deleted_at')
            ->update(['received_at' => str_replace('/', '-', $date)]);
    }

    /**
     * Put a reel's weight onto the factory floor stock (`jumboreel_stock`).
     *
     * Still maintained rather than derived, unlike the raw-material stock:
     * jumbo factory usage is still a legacy screen and it SUBTRACTS from this
     * aggregate, so an entrance that did not add would drive it negative. The
     * (location, productid) unique key makes the upsert race-free, and each
     * movement appends to the row's `modification` audit exactly as the legacy
     * code did.
     */
    protected function addToFloorStock($conn, array $item, string $username, int $now): void
    {
        $note = json_encode([
            'description' => 'Update from factory entrance',
            'weight' => $item['weight'],
            'user' => $username,
            'timestamp' => $now,
        ]);

        $conn->statement(
            'INSERT INTO `jumboreel_stock` (`location`, `productid`, `quantity`, `weight`, `modification`) '
            . 'VALUES (?, ?, 1, ?, JSON_ARRAY(CAST(? AS JSON))) '
            . 'ON DUPLICATE KEY UPDATE `quantity` = `quantity` + 1, `weight` = `weight` + ?, '
            . "`modification` = JSON_ARRAY_APPEND(IF(JSON_VALID(`modification`), `modification`, JSON_ARRAY()), '$', CAST(? AS JSON))",
            [self::STOCK_SITE, $item['product_id'], $item['weight'], $note, $item['weight'], $note]
        );
    }

    #[Layout('core::layouts.admin')]
    #[Title('Jumbo Roll Factory Entrance')]
    public function render()
    {
        return view('bil::livewire.jumbo-rolls.factory-entrance');
    }
}
