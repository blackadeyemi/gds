<?php

namespace Modules\Bil\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\ConversionSetup;
use Modules\Bil\Models\JumboRollFactoryEntrance;
use Modules\Bil\Models\JumboRollFactoryUsage;
use Modules\Core\Concerns\EnforcesShift;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;

/**
 * Jumbo Rolls → Consumption. Rebuilt from the legacy
 * `factory_usage.php?type=jumboreel` screen (`Barcode\Usage@jumboreel` for the
 * scan, `Factory\Jumboreel\Usage@processEntry` for the save).
 *
 * Choose shift + factory → line → machine, scan the reels being unwound, then
 * Save records each in `factory_usage_reel`, advances the entrance row's
 * status, and takes the weight off the factory floor stock.
 *
 * The one thing this screen has that Raw Materials Consumption does not is
 * SLICES. A reel whose product has `slice` > 1 is cut and consumed a slice at a
 * time: the scanned barcode carries the slice number as a sixth segment
 * (`26-08-05-M3-025-3`) and each slice weighs the reel's weight divided by the
 * slice count. The reel stays 'mid' in the entrance until every slice — and any
 * returned remainder — is accounted for, and only then does it leave the floor
 * stock count.
 */
class Consumption extends Component
{
    use EnforcesShift;

    /** Gated by the Jumbo Rolls Consumption shift window. */
    public function shiftKey(): ?string
    {
        return self::PAGE_KEY;
    }

    public const PAGE_KEY = 'bil.jumbo_rolls.consumption';

    public const MAX_SCAN = 10; // barcodes per submit

    /** @see FactoryEntrance::STOCK_SITE — the floor stock these reels sit on. */
    private const STOCK_SITE = 'Ogba';

    /** A reel barcode is five dash-separated segments; a slice adds a sixth. */
    private const CODE_SEGMENTS = 5;

    public string $dateIso = '';
    public string $shift = 'day';
    public ?int $factoryId = null;
    public ?int $lineId = null;
    public ?int $projectId = null;
    public string $scan = '';

    /** Scanned rows pending save: [['barcode','productname','product_id','weight'], …]. */
    public array $items = [];

    public string $scanError = '';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->shift = now()->hour >= 7 && now()->hour < 19 ? 'day' : 'night';
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function maxScan(): int
    {
        return self::MAX_SCAN;
    }

    /* ---------------- Factory > line > machine ---------------- */

    /** This company's factories — a jumbo roll is only ever unwound at one. */
    #[Computed]
    public function factories()
    {
        return Factory::query()
            ->whereHas('company', fn ($c) => $c->where('code', config('bil.company_code')))
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** The chosen factory's top-level lines. */
    #[Computed]
    public function lines()
    {
        if (! $this->factoryId) {
            return collect();
        }

        return MachineLine::query()->roots()
            ->where('factory_id', $this->factoryId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Machines on the chosen line — its projects and those of its sub-lines,
     * which is exactly what the legacy `factory_projects` view listed for a
     * line name. Sub-projects are not offered; the legacy screen showed roots.
     */
    #[Computed]
    public function machines()
    {
        if (! $this->lineId) {
            return collect();
        }

        $lineIds = MachineLine::where('parent_id', $this->lineId)->pluck('id')->push($this->lineId);

        return MachineProject::query()->roots()
            ->whereIn('line_id', $lineIds)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** The chosen machine. */
    #[Computed]
    public function machine(): ?MachineProject
    {
        return $this->projectId ? $this->machines()->firstWhere('id', $this->projectId) : null;
    }

    /**
     * What the chosen machine's line is set up to convert, if anything.
     *
     * The legacy screen offered every product in a dropdown, pre-selected to
     * the line's setup. Conversion Setup is now the single place a line's
     * product is declared, so this reads it rather than letting the operator
     * type a different answer here.
     */
    public function productOnLine(): string
    {
        $lineId = $this->machine()?->line_id;
        if (! $lineId) {
            return '';
        }

        $setup = ConversionSetup::where('line_id', $lineId)->first();

        return $setup && ! $setup->isIdle() ? (string) $setup->productname : '';
    }

    public function updatedFactoryId(): void
    {
        $this->lineId = null;
        $this->projectId = null;
        $this->resetScans();
    }

    public function updatedLineId(): void
    {
        $this->projectId = null;
        $this->resetScans();
    }

    public function updatedProjectId(): void
    {
        $this->resetScans();
    }

    protected function resetScans(): void
    {
        $this->items = [];
        $this->scanError = '';
    }

    /** True once a factory, line and machine have all been chosen. */
    public function placed(): bool
    {
        return $this->factoryId && $this->lineId && $this->machine() !== null;
    }

    /* ---------------- Scanning ---------------- */

    /** Validate a scanned reel (or slice) and add it to the pending list. */
    public function addScan(): void
    {
        $this->scanError = '';
        $barcode = strtoupper(trim($this->scan));
        $this->scan = '';

        if (! $this->placed()) {
            $this->scanError = 'Select a factory, line and machine first.';

            return;
        }
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

        $used = JumboRollFactoryUsage::where('barcode', $barcode)
            ->where('is_deleted', 0)->first(['timestamp']);
        if ($used) {
            $this->scanError = 'Entry already made for ' . $barcode
                . ' on ' . date('d/m/Y', (int) $used->timestamp) . '.';

            return;
        }

        $segments = explode('-', $barcode);
        if (count($segments) < self::CODE_SEGMENTS) {
            $this->scanError = 'Barcode not found in entrance.';

            return;
        }

        $parent = implode('-', array_slice($segments, 0, self::CODE_SEGMENTS));
        $hasSlice = count($segments) > self::CODE_SEGMENTS;

        // A remainder logged at the end of an earlier shift is re-scanned under
        // its own barcode and consumed at the weight that was left.
        if ($hasSlice && ($remainder = $this->remainderEvent($barcode))) {
            $this->items[] = [
                'barcode' => $barcode,
                'productname' => $remainder->productname,
                'product_id' => $this->productIdFor($parent),
                'weight' => (float) $remainder->weight,
            ];

            return;
        }

        $entrance = JumboRollFactoryEntrance::where('barcode', $parent)
            ->where('is_deleted', 0)->first(['status']);
        if (! $entrance) {
            $this->scanError = 'Barcode not found in entrance.';

            return;
        }

        $blocked = [
            'return' => 'Jumbo roll has been returned.',
            'yes' => 'Slices completely scanned, or the jumbo roll is unstocked.',
            'blocked' => 'Jumbo roll is blocked.',
        ];
        if (isset($blocked[(string) $entrance->status])) {
            $this->scanError = $blocked[(string) $entrance->status];

            return;
        }

        $reel = $this->productionReel($parent);
        if (! $reel) {
            $this->scanError = 'Barcode not found in production.';

            return;
        }

        $slices = (int) ($reel->slice ?: 1);
        $weight = (float) $reel->weight;

        if ($slices > 1) {
            if (! $hasSlice) {
                $this->scanError = 'Barcode does not exist, slice not included.';

                return;
            }

            $number = (int) $segments[self::CODE_SEGMENTS];
            if ($number < 1 || $number > $slices) {
                $this->scanError = 'Barcode does not exist, invalid included slice.';

                return;
            }

            $weight = round($weight / $slices, 2);
        }

        $this->items[] = [
            'barcode' => $barcode,
            'productname' => $reel->productname ?? '—',
            'product_id' => (int) $reel->product_id,
            'weight' => $weight,
        ];
    }

    /** A 'remain' event logged against this exact barcode. */
    protected function remainderEvent(string $barcode)
    {
        return DB::connection('bil')->table('factory_event')
            ->where('barcode', $barcode)->where('event', 'remain')
            ->first(['productname', 'weight']);
    }

    /** The BPL production record for a whole reel, with its product's slicing. */
    protected function productionReel(string $parent)
    {
        return DB::connection('bpl')->table('bpl_production as prod')
            ->leftJoin('bpl_products_hardroll as p', 'prod.product_id', '=', 'p.id')
            ->where('prod.barcode', $parent)
            ->select('prod.product_id', 'prod.weight', 'p.productname', 'p.slice')
            ->first();
    }

    protected function productIdFor(string $parent): int
    {
        return (int) DB::connection('bpl')->table('bpl_production')
            ->where('barcode', $parent)->value('product_id');
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

    /** Record consumption for every scanned reel or slice. */
    public function save(): void
    {
        if ($this->items === [] || ! $this->placed() || ! $this->ensureShiftOpen()) {
            return;
        }

        $machine = $this->machine();
        $factory = $this->factories()->firstWhere('id', $this->factoryId);
        $line = $this->lines()->firstWhere('id', $this->lineId);

        if (! $factory?->code || ! $line) {
            session()->flash('err', 'That factory has no legacy code — the legacy jumbo screens would not see these entries.');

            return;
        }

        $date = $this->canBackdate() ? str_replace('-', '/', $this->dateIso) : $this->shiftDate();
        $product = $this->productOnLine();
        $username = auth()->user()?->username ?? '';
        $now = time();

        $conn = DB::connection('bil');
        $consumed = 0;

        try {
            $conn->transaction(function () use ($conn, $factory, $line, $machine, $date, $product, $username, $now, &$consumed) {
                foreach ($this->items as $item) {
                    $row = [
                        'user' => $username,
                        'shift' => $this->shift,
                        'location' => $factory->code,
                        'linename' => $line->name,
                        'project' => $machine->name,
                        'pre_productname' => $product !== '' ? $product : null,
                        'weight' => $item['weight'],
                        'dateofuse' => $date,
                        'timestamp' => $now,
                        'is_deleted' => 0,
                    ];

                    // (shift, barcode) is unique, so a row whose usage was
                    // deleted is re-used rather than inserted alongside.
                    $deleted = JumboRollFactoryUsage::where('barcode', $item['barcode'])
                        ->where('is_deleted', 1)->exists();

                    if ($deleted) {
                        JumboRollFactoryUsage::where('barcode', $item['barcode'])->update($row);
                    } else {
                        JumboRollFactoryUsage::create($row + ['barcode' => $item['barcode']]);
                    }

                    // Both of these read the row just written, so they follow it.
                    $parent = $this->parentCode($item['barcode']);
                    $remaining = $this->remainingWeight($conn, $parent);

                    JumboRollFactoryEntrance::where('barcode', $parent)->where('is_deleted', 0)
                        ->update(['status' => $remaining > 1 ? 'mid' : 'yes']);

                    $this->takeFromFloorStock($conn, $item, $remaining, $username, $now);
                    $consumed++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('err', 'Nothing was saved — ' . $e->getMessage());

            return;
        }

        $this->items = [];
        $this->scanError = '';
        session()->flash('ok', $consumed . ' reel' . ($consumed === 1 ? '' : 's') . ' consumed on ' . $machine->name . '.');
    }

    protected function parentCode(string $barcode): string
    {
        return implode('-', array_slice(explode('-', $barcode), 0, self::CODE_SEGMENTS));
    }

    /**
     * What is left of a whole reel: its production weight, less everything
     * consumed and everything returned across all of its slices.
     *
     * Matched on `reel_barcode` — the stored generated column holding a slice's
     * parent code — so each rollup is a covering index lookup rather than the
     * unindexable `barcode LIKE 'parent%'` the legacy code used.
     */
    protected function remainingWeight($conn, string $parent): float
    {
        $weight = (float) DB::connection('bpl')->table('bpl_production')
            ->where('barcode', $parent)->value('weight');

        $used = (float) $conn->table('factory_usage_reel')
            ->where('reel_barcode', $parent)->where('is_deleted', 0)->sum('weight');

        $returned = (float) $conn->table('factory_event')
            ->where('reel_barcode', $parent)->where('event', 'return')->sum('weight');

        return $weight - ($used + $returned);
    }

    /**
     * Take a reel's weight off the factory floor stock (`jumboreel_stock`).
     *
     * Mirrors the entrance: the aggregate is still maintained because the
     * legacy jumbo reports read it. The COUNT only drops when the reel is
     * finished — a sliced reel is one unit on the floor until its last slice
     * goes on the machine, so intermediate slices move weight only.
     */
    protected function takeFromFloorStock($conn, array $item, float $remaining, string $username, int $now): void
    {
        $note = json_encode([
            'description' => 'Update from factory usage',
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

    /** Legacy shift date: a scan before 07:00 belongs to the previous day. */
    protected function shiftDate(): string
    {
        $now = now();

        return ($now->hour < 7 ? $now->copy()->subDay() : $now)->format('Y/m/d');
    }

    #[Layout('core::layouts.admin')]
    #[Title('Jumbo Roll Consumption')]
    public function render()
    {
        return view('bil::livewire.jumbo-rolls.consumption');
    }
}
