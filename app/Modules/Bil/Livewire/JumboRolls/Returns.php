<?php

namespace Modules\Bil\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\JumboRollFactoryEntrance;
use Modules\Core\Concerns\EnforcesShift;

/**
 * Jumbo Rolls → Returns. Sending a jumbo roll back to BPL.
 *
 * Rebuilt from the legacy `factory_event.php?s=jumboreel` screen with the
 * "Return" option (`Barcode\Unused\Jumboreel@getBarcodeInfo` for the scan,
 * `Factory\Jumboreel\Returned@Add` for the save).
 *
 * A reel goes back in one of two shapes, and the barcode decides which:
 *
 *   WHOLE      an untouched reel on a BIL factory floor — its entrance row is
 *              still `status IS NULL`, nothing has been unwound off it. The
 *              full production weight goes back.
 *   REMAINDER  what was left of a part-used reel, logged at the end of a shift
 *              as a `factory_event` 'remain' row under its own barcode. Sending
 *              it back flips that row's event from 'remain' to 'return' rather
 *              than writing a second one.
 *
 * Either way the entrance row becomes 'return' — which is what stops the
 * Consumption screen accepting the reel and drops it out of the Stock page —
 * and the weight comes off the factory floor stock. The reel's COUNT only
 * leaves the floor when nothing more of it remains, exactly as consumption
 * does, so a returned remainder does not double-decrement a reel that was
 * already counted out.
 *
 * Nothing is recorded about where it goes on the BPL side: the legacy return
 * captured no destination, and no table on the BPL side is written by this act.
 */
class Returns extends Component
{
    use EnforcesShift;

    public function shiftKey(): ?string
    {
        return self::PAGE_KEY;
    }

    public const PAGE_KEY = 'bil.jumbo_rolls.returns';

    public const MAX_SCAN = 10; // barcodes per submit

    /** @see FactoryEntrance::STOCK_SITE */
    private const STOCK_SITE = 'Ogba';

    /** A reel barcode is five dash-separated segments; a slice or remainder adds a sixth. */
    private const CODE_SEGMENTS = 5;

    public const WHOLE = 'whole';
    public const REMAINDER = 'part';

    public string $scan = '';

    /** The day the reels actually went back, not the day this was typed. */
    public string $dateIso = '';

    /**
     * Why these are going back. Optional — the operator at the gate often does
     * not know, and a required field there collects noise rather than reasons.
     * One reason covers the submit: a batch handed back together went back for
     * the same reason, and per-row entry would slow the scanning down for a
     * field that is usually blank.
     */
    public string $reason = '';

    /** Pending returns: [['barcode','productname','product_id','weight','state','event_id']]. */
    public array $items = [];

    public string $scanError = '';

    public const REASON_MAX = 255;

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /** Legacy shift date: a return logged before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    /* ---------------- Scanning ---------------- */

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

        if ($this->event($barcode, 'return')) {
            $this->scanError = 'This has already been returned.';

            return;
        }

        $parent = $this->parentCode($barcode);

        // A logged remainder goes back as itself — unless it has since been used.
        if ($remainder = $this->event($barcode, 'remain')) {
            if ($this->isConsumed($barcode)) {
                $this->scanError = 'Barcode already in use.';

                return;
            }

            $this->items[] = [
                'barcode' => $barcode,
                'productname' => $remainder->productname,
                'product_id' => $this->productIdFor($parent),
                'weight' => (float) $remainder->weight,
                'state' => self::REMAINDER,
                'event_id' => (int) $remainder->id,
            ];

            return;
        }

        // Otherwise it must be a whole reel still standing untouched on a floor.
        $entrance = JumboRollFactoryEntrance::where('barcode', $parent)
            ->where('is_deleted', 0)->whereNull('status')->exists();

        if (! $entrance) {
            $this->scanError = 'Barcode does not exist, or is already in use.';

            return;
        }

        $reel = $this->productionReel($parent);
        if (! $reel) {
            $this->scanError = 'Barcode not found in production.';

            return;
        }

        $this->items[] = [
            'barcode' => $parent,
            'productname' => $reel->productname ?? '—',
            'product_id' => (int) $reel->product_id,
            'weight' => (float) $reel->weight,
            'state' => self::WHOLE,
            'event_id' => null,
        ];
    }

    /** A `factory_event` row of one kind for this exact barcode. */
    protected function event(string $barcode, string $event)
    {
        return DB::connection('bil')->table('factory_event')
            ->where('barcode', $barcode)->where('event', $event)
            ->first(['id', 'productname', 'weight']);
    }

    /**
     * Has this exact barcode been put on a machine?
     *
     * Matched exactly rather than by the legacy `LIKE '<barcode>%'`: Consumption
     * stores the scanned barcode verbatim, so a remainder is consumed under its
     * own code, and an exact match uses the index instead of scanning 287k rows.
     */
    protected function isConsumed(string $barcode): bool
    {
        return DB::connection('bil')->table('factory_usage_reel')
            ->where('barcode', $barcode)->where('is_deleted', 0)->exists();
    }

    protected function productionReel(string $parent)
    {
        return DB::connection('bpl')->table('bpl_production as prod')
            ->leftJoin('bpl_products_hardroll as p', 'prod.product_id', '=', 'p.id')
            ->where('prod.barcode', $parent)
            ->select('prod.product_id', 'prod.weight', 'p.productname')
            ->first();
    }

    protected function productIdFor(string $parent): int
    {
        return (int) DB::connection('bpl')->table('bpl_production')
            ->where('barcode', $parent)->value('product_id');
    }

    protected function parentCode(string $barcode): string
    {
        return implode('-', array_slice(explode('-', $barcode), 0, self::CODE_SEGMENTS));
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

    /* ---------------- Saving ---------------- */

    public function save(): void
    {
        if ($this->items === [] || ! $this->ensureShiftOpen()) {
            return;
        }

        $username = auth()->user()?->username ?? '';
        $now = time();
        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : $this->shiftDate();
        $reason = mb_substr(trim($this->reason), 0, self::REASON_MAX) ?: null;
        $conn = DB::connection('bil');
        $returned = 0;

        try {
            $conn->transaction(function () use ($conn, $username, $now, $date, $reason, &$returned) {
                foreach ($this->items as $item) {
                    if ($item['state'] === self::REMAINDER) {
                        // The remainder was already logged; it changes hands, not
                        // shape — but the date becomes the day it went BACK, not
                        // the day it was set aside.
                        $conn->table('factory_event')->where('id', $item['event_id'])
                            ->update(['event' => 'return', 'reason' => $reason, 'date' => $date]);
                    } else {
                        $conn->table('factory_event')->insert([
                            'barcode' => $item['barcode'],
                            'productname' => $item['productname'],
                            'weight' => $item['weight'],
                            'event' => 'return',
                            'reason' => $reason,
                            'date' => $date,
                            'timestamp' => $now,
                        ]);
                    }

                    $parent = $this->parentCode($item['barcode']);

                    JumboRollFactoryEntrance::where('barcode', $parent)->where('is_deleted', 0)
                        ->update(['status' => 'return']);

                    $this->takeFromFloorStock($conn, $item, $this->remainingWeight($conn, $parent), $username, $now);
                    $returned++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — ' . $e->getMessage());

            return;
        }

        $this->items = [];
        $this->scanError = '';
        $this->reason = '';
        session()->flash('ok', $returned . ' item' . ($returned === 1 ? '' : 's') . ' returned to BPL.');
    }

    /** @see Consumption::remainingWeight() — one formula for both screens. */
    protected function remainingWeight($conn, string $parent): float
    {
        $weight = (float) DB::connection('bpl')->table('bpl_production')
            ->where('barcode', $parent)->value('weight');

        $used = (float) $conn->table('factory_usage_reel')
            ->where('reel_barcode', $parent)->where('is_deleted', 0)->sum('weight');

        $sentBack = (float) $conn->table('factory_event')
            ->where('reel_barcode', $parent)->where('event', 'return')->sum('weight');

        return $weight - ($used + $sentBack);
    }

    /** @see Consumption::takeFromFloorStock() — same aggregate, same rules. */
    protected function takeFromFloorStock($conn, array $item, float $remaining, string $username, int $now): void
    {
        $note = json_encode([
            'description' => 'Update from return entry',
            'weight' => -$item['weight'],
            'user' => $username,
            'timestamp' => $now,
        ]);

        $affected = $conn->affectingStatement(
            'UPDATE `jumboreel_stock` SET `quantity` = `quantity` + ?, `weight` = `weight` - ?, '
            . "`modification` = JSON_ARRAY_APPEND(IF(JSON_VALID(`modification`), `modification`, JSON_ARRAY()), '$', CAST(? AS JSON)) "
            . 'WHERE `location` = ? AND `productid` = ?',
            [$remaining > 1 ? 0 : -1, $item['weight'], $note, self::STOCK_SITE, $item['product_id']]
        );

        if ($affected < 1) {
            throw new \RuntimeException($item['productname'] . ' was not found in the factory floor stock.');
        }
    }

    #[Layout('core::layouts.admin')]
    #[Title('Jumbo Roll Returns')]
    public function render()
    {
        return view('bil::livewire.jumbo-rolls.returns');
    }
}
