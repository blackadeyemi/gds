<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\ConversionSetup;
use Modules\Bil\Models\FactoryConversion;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Bil\Support\ConversionWaste as Waste;
use Modules\Core\Concerns\EnforcesShift;
use Modules\Core\Models\MachineLine;

/**
 * BIL → Finished Goods → Conversion Output. Rebuild of the legacy
 * factory_production.php ("Factory Barcode Creation"): records pallets coming
 * off a converting line and prints their CODE93 labels.
 *
 * The line list is limited to lines that are ACTIVE in Conversion Setup — a
 * line with no product set up isn't converting anything, so there is nothing to
 * book against it. Picking a line fills in the product and bundles-per-pallet
 * from that setup, exactly as the legacy screen did.
 *
 * Contracts the legacy app still depends on, reproduced exactly:
 *   - barcode `{yy}-{mm}-{dd}-{FAC}-{nnn}`, FAC = B1/B2/GB, and `nnn` a
 *     sequence that is GLOBAL PER DAY across factories (verified against the
 *     live data: today's 78 pallets run 001-078 with B1 and GB interleaved).
 *   - `linename`/`sublinename` name pair, using the line's legacy_alias.
 *   - `specs` = {"netweight":…,"grossweight":…} per BUNDLE; the row's
 *     netweight/grossweight are those multiplied by the bundle count.
 */
#[Layout('core::layouts.admin')]
#[Title('Conversion Output')]
class ConversionOutput extends Component
{
    use EnforcesShift;

    /**
     * Shift windows for this area, configurable in Settings → Shifts.
     *
     * Registering the context does two things: it lets an admin ENFORCE the
     * windows (off by default — the area stays open anytime until they turn it
     * on), and it makes the window times the single definition of what "day"
     * and "night" mean for a converting line. The waste run is keyed on that
     * same value, so both screens read the one configuration.
     */
    public function shiftKey(): ?string
    {
        return self::PAGE_KEY;
    }

    public ?int $line_id = null;
    public string $shift = 'day';
    public string $dateIso = '';
    public int $pallets = 1;

    /** The legacy screen capped a single run at 20 labels. */
    public const MAX_PALLETS = 20;

    public const PAGE_KEY = 'bil.finished_goods.conversion_output';

    public function mount(): void
    {
        // Both from the configured shift windows, so the pallet is stamped with
        // the same date and shift the waste queue will key its run on.
        $current = Waste::currentRun();
        $this->dateIso = $current['date'];
        $this->shift = $current['shift'];
    }

    /* ---------------- Options ---------------- */

    /**
     * Lines currently set up to convert something. A line whose setup says
     * "None" is idle, so it is not offered.
     */
    #[Computed]
    public function activeLines()
    {
        $setups = ConversionSetup::query()
            ->whereNotNull('line_id')
            ->whereNotNull('productname')
            ->where('productname', '<>', '')
            ->where('productname', '<>', ConversionSetup::IDLE)
            ->get()->keyBy('line_id');

        return MachineLine::query()->whereIn('id', $setups->keys())->treeOrder()->get()
            ->map(fn ($l) => [
                'id' => (string) $l->id,
                'label' => $l->name . ' — ' . $setups[$l->id]->productname,
            ])->values();
    }

    /** The chosen line's setup row. */
    #[Computed]
    public function setup(): ?ConversionSetup
    {
        return $this->line_id
            ? ConversionSetup::where('line_id', $this->line_id)->first()
            : null;
    }

    /** The finished-goods product that setup names. */
    #[Computed]
    public function product(): ?FinishedGoodsProduct
    {
        $name = $this->setup?->productname;

        return $name ? FinishedGoodsProduct::where('productname', $name)->first() : null;
    }

    /** Where the chosen line sits: [factory, lineName, subLineName, code]. */
    #[Computed]
    public function placement(): ?array
    {
        if (! $this->line_id) {
            return null;
        }

        $line = MachineLine::with('factory')->find($this->line_id);
        if (! $line) {
            return null;
        }

        $parent = $line->parent_id ? MachineLine::find($line->parent_id) : null;
        $factory = $line->factory ?? ($parent?->factory);

        // The legacy reports GROUP BY these names, so write the spelling they
        // already hold (FACIAL, Handkerchief) rather than the canonical one.
        $legacy = fn (?MachineLine $l) => $l ? ($l->legacy_alias ?: $l->name) : null;

        return [
            'factory' => $factory,
            'code' => $factory ? (FactoryConversion::FACTORY_CODES[$factory->name] ?? null) : null,
            // A top-level line is its own "line"; a sub-line reports its parent.
            'linename' => $legacy($parent ?? $line),
            'sublinename' => $legacy($line),
        ];
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function updatedLineId(): void
    {
        // Recompute everything that hangs off the line.
        unset($this->setup, $this->product, $this->placement);
    }

    /* ---------------- Waste confirmation ---------------- */

    /**
     * Why this line may not start a run right now, or null.
     *
     * A run is one line converting one product in one shift, and its waste has
     * to be confirmed before the next run on that line can begin — including
     * when the "next run" is the same shift with a different product, because a
     * changeover ends a run too. See Support\ConversionWaste.
     *
     * Holders of the waste bypass are never stopped: production must not halt
     * because a supervisor is unavailable, so the escape hatch is a permission
     * rather than an override anyone can click.
     */
    protected function wasteBlocker(int $productid, string $date): ?string
    {
        if (auth()->user()?->canDo(ConversionWaste::PAGE_KEY, 'bypass-waste-lock')) {
            return null;
        }

        $blocker = Waste::blockingRun((int) $this->line_id, $date, $this->shift, $productid);

        if (! $blocker) {
            return null;
        }

        return sprintf(
            'Waste has not been confirmed for the previous run on this line — %s, %s shift, %s. '
            . 'Record it on Conversion Waste and confirm that run before booking more pallets.',
            $blocker['line_name'] ?: ('line #' . $blocker['line_id']),
            strtolower($blocker['shift']),
            \Illuminate\Support\Carbon::parse($blocker['date'])->format('d/m/Y')
        );
    }

    /**
     * The same check, for the view — so the screen says the run is blocked
     * before the operator fills the form in, not after they press Generate.
     */
    #[Computed]
    public function wasteBlock(): ?string
    {
        if (! $this->line_id || ! $this->product) {
            return null;
        }

        $date = $this->canBackdate() ? $this->dateIso : Waste::currentRun()['date'];

        return $this->wasteBlocker((int) $this->product->productid, $date);
    }

    /* ---------------- Generate ---------------- */

    public function generate(): void
    {
        if (! $this->ensureShiftOpen()) {
            return;
        }

        $setup = $this->setup;
        $product = $this->product;
        $place = $this->placement;

        $this->validate([
            'line_id' => 'required|integer',
            'shift' => 'required|in:day,night',
            'pallets' => 'required|integer|min:1|max:' . self::MAX_PALLETS,
        ]);

        if (! $setup || $setup->isIdle()) {
            session()->flash('err', 'That line has no product set up — set it in Conversion Setup first.');
            return;
        }

        if (! $product) {
            session()->flash('err', 'The product on that line ("' . $setup->productname . '") is not in the finished-goods master.');
            return;
        }

        if (! $place || ! $place['code']) {
            // Better to refuse than to mint a barcode with the wrong factory code.
            session()->flash('err', 'That line has no factory with a known barcode code (B1 / B2 / GB).');
            return;
        }

        // Non-backdaters always book against the CURRENT PRODUCTION date —
        // which between midnight and the shift boundary is yesterday, not
        // today. Using the calendar date there would file the pallet under a
        // run the waste queue is not tracking.
        $date = $this->canBackdate() ? $this->dateIso : Waste::currentRun()['date'];
        $dateSlash = str_replace('-', '/', $date);
        $bundles = (int) $setup->bundles;

        // A line cannot start a new run while the previous one still owes its
        // waste. Checked HERE — after validation but before the sequence lock —
        // so nothing is minted and no barcode number is consumed by a run that
        // is about to be refused.
        if ($blocker = $this->wasteBlocker($product->productid, $date)) {
            session()->flash('err', $blocker);

            return;
        }

        // Per-bundle spec, snapshotted onto the row like the legacy screen.
        $specs = json_encode([
            'netweight' => (string) $product->netweight,
            'grossweight' => (string) $product->grossweight,
        ]);

        $prefix = substr($date, 2, 2) . '-' . substr($date, 5, 2) . '-' . substr($date, 8, 2)
            . '-' . $place['code'] . '-';

        $conn = DB::connection('bil');
        // The daily sequence is read-then-written and shared by every factory,
        // so two stations printing at once could take the same number. Serialize
        // the read+insert batch behind a named lock, released in finally.
        $got = (int) ($conn->selectOne("SELECT GET_LOCK('fg_conversion_seq', 10) AS l")->l ?? 0);
        if ($got !== 1) {
            session()->flash('err', 'Another pallet run is being generated right now — please try again in a moment.');
            return;
        }

        $ids = [];

        try {
            $last = FactoryConversion::where('dateofproduction', $dateSlash)
                ->orderByDesc('id')->value('barcode');
            $seq = 0;
            if ($last) {
                $parts = explode('-', $last);
                $seq = isset($parts[4]) ? (int) $parts[4] : 0;
            }

            for ($i = 0; $i < $this->pallets; $i++) {
                $seq++;
                $row = FactoryConversion::create([
                    'username' => (string) (auth()->user()->username ?? auth()->user()->name ?? 'gds'),
                    'shift' => $this->shift,
                    'productid' => $product->productid,
                    'factory' => $place['factory']->name,
                    'linename' => $place['linename'],
                    'sublinename' => $place['sublinename'],
                    'barcode' => $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                    'bundles' => $bundles,
                    'specs' => $specs,
                    'netweight' => (float) $product->netweight * $bundles,
                    'grossweight' => (float) $product->grossweight * $bundles,
                    'dateofproduction' => $dateSlash,
                    // What the system says today is, regardless of any backdate.
                    'actualdate' => now()->format('Y/m/d'),
                    'factory_id' => $place['factory']->id,
                    'line_id' => $this->line_id,
                ]);
                $ids[] = $row->id;
            }
        } finally {
            $conn->select("SELECT RELEASE_LOCK('fg_conversion_seq')");
        }

        session()->put('fg_conversion_print_ids', $ids);

        $count = count($ids);
        session()->flash('ok', $count . ' pallet' . ($count === 1 ? '' : 's') . ' recorded — opening labels…');
        $this->dispatch('print-labels', url: route('bil.finished-goods.conversion-output.print'));
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.conversion-output');
    }
}
