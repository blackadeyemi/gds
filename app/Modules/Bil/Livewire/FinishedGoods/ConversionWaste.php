<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\ConversionWasteEntry;
use Modules\Bil\Models\ConversionWasteRun;
use Modules\Bil\Support\ConversionWaste as Waste;
use Modules\Core\Concerns\EnforcesShift;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\WasteCause;
use Modules\Core\Models\WasteOrigin;

/**
 * BIL → Finished Goods → Conversion Waste. Rebuild of the legacy
 * factory_production_waste.php, on a different shape.
 *
 * The legacy screen asked the operator to describe the run from scratch — pick
 * a factory, a line, a machine, a product, a date, a shift — and then post up to
 * twenty cause+weight pairs with a single origin radio for the lot. Nothing
 * connected what was typed to what had actually been produced, and nothing said
 * when an entry was finished.
 *
 * Here the runs come from production. A run is one line converting one product
 * in one shift; it appears on this screen because pallets were booked against
 * it, and it leaves when its waste is confirmed. There is nothing to type in
 * that the factory has not already told us.
 *
 * The list is ordered OLDEST FIRST and it is a queue, not a menu: while an
 * earlier run on the same line is open, that is the one that has to be dealt
 * with — the same rule that stops Conversion Output starting the next run.
 * Holders of the bypass ability can work out of order.
 *
 * Origin is per ROW now, not per form, because it decides what the row is
 * classified against: a jumbo roll's grade type, or a raw materials group. All
 * causes stay available under both.
 */
#[Layout('core::layouts.admin')]
#[Title('Conversion Waste')]
class ConversionWaste extends Component
{
    use EnforcesShift;

    /**
     * Its own context, separate from Conversion Output's: waste is often
     * weighed at the end of a shift, so the window that suits recording it is
     * not necessarily the window production runs in. Off by default.
     */
    public function shiftKey(): ?string
    {
        return self::PAGE_KEY;
    }

    /** Identity of the run being worked on — see Waste::key(). */
    public ?string $runKey = null;

    /** Unsaved entry rows: ['uid','origin_id','origin_ref','cause_id','weight'] */
    public array $rows = [];

    /** Run-list filters. */
    public string $filterLine = '';
    public bool $showConfirmed = false;

    /** Confirmation dialog. */
    public bool $confirming = false;
    public string $confirmNote = '';

    public const PAGE_KEY = 'bil.finished_goods.conversion_waste';

    public function mount(): void
    {
        // Land on the run that most needs attention: the oldest still open.
        $open = $this->openRuns;
        if ($open !== []) {
            $this->runKey = Waste::keyOf($open[0]);
            $this->resetRows();
        }
    }

    /* ---------------- Permissions ---------------- */

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    public function canConfirm(): bool
    {
        return $this->mayDo('confirm');
    }

    public function canReopen(): bool
    {
        return $this->mayDo('reopen');
    }

    /** Whether this user may work a run out of turn. */
    public function canBypass(): bool
    {
        return $this->mayDo('bypass-waste-lock');
    }

    /* ---------------- Runs ---------------- */

    #[Computed]
    public function openRuns(): array
    {
        return Waste::openRuns($this->lineFilterIds());
    }

    /**
     * Confirmed runs, newest first — shown only on request. They are the
     * finished work, so they are out of the way by default but reachable when
     * something needs correcting.
     */
    #[Computed]
    public function confirmedRuns(): array
    {
        if (! $this->showConfirmed) {
            return [];
        }

        $runs = Waste::recentRuns($this->lineFilterIds());
        $stored = Waste::storedFor($runs);

        return array_slice(array_values(array_filter(
            $runs,
            fn ($r) => ($stored[Waste::keyOf($r)] ?? null)?->isConfirmed()
        )), 0, 50);
    }

    /** null = all lines. */
    protected function lineFilterIds(): ?array
    {
        return $this->filterLine === '' ? null : [(int) $this->filterLine];
    }

    /** The run currently being worked on, straight from production. */
    #[Computed]
    public function run(): ?array
    {
        if (! $this->runKey) {
            return null;
        }

        foreach ([...$this->openRuns, ...$this->confirmedRuns] as $r) {
            if (Waste::keyOf($r) === $this->runKey) {
                return $r;
            }
        }

        // Selected from a filter that has since changed, or already confirmed
        // while the confirmed list is hidden — rebuild it from its key.
        [$lineId, $date, $shift, $productid] = explode('|', $this->runKey) + [null, null, null, null];

        foreach (Waste::runsForLine((int) $lineId) as $r) {
            if (Waste::keyOf($r) === $this->runKey) {
                return $r;
            }
        }

        return null;
    }

    /** The stored row for the selected run, if anyone has touched it yet. */
    #[Computed]
    public function storedRun(): ?ConversionWasteRun
    {
        $run = $this->run;

        return $run ? (Waste::storedFor([$run])[$this->runKey] ?? null) : null;
    }

    /**
     * The run that has to be dealt with before this one, or null.
     *
     * Same rule as Conversion Output's: an earlier open run on the SAME LINE.
     * Bypass holders see the warning but are not stopped.
     */
    #[Computed]
    public function blocker(): ?array
    {
        $run = $this->run;
        if (! $run) {
            return null;
        }

        return Waste::blockingRun($run['line_id'], $run['date'], $run['shift'], $run['productid']);
    }

    public function isBlocked(): bool
    {
        return $this->blocker !== null && ! $this->canBypass();
    }

    /* ---------------- Lookups ---------------- */

    #[Computed]
    public function causes()
    {
        return WasteCause::active()->ordered()->get();
    }

    #[Computed]
    public function origins()
    {
        return WasteOrigin::active()->ordered()->get();
    }

    /**
     * The classification options for an origin, resolved once per origin per
     * render — every row with the same origin shares one lookup rather than
     * querying the other connection again.
     */
    public function optionsFor($originId): array
    {
        static $cache = [];
        $originId = (int) $originId;

        if ($originId <= 0) {
            return [];
        }

        return $cache[$originId] ??= ($this->origins->firstWhere('id', $originId)?->options() ?? []);
    }

    public function originNeedsRef($originId): bool
    {
        return (bool) $this->origins->firstWhere('id', (int) $originId)?->needsRef();
    }

    #[Computed]
    public function lines()
    {
        return MachineLine::treeOrder()->get();
    }

    /**
     * Lines for the searchable filter, with an explicit "All lines" entry.
     *
     * The searchable-select has no empty option of its own, so clearing the
     * filter has to be a choosable value rather than the absence of one.
     */
    #[Computed]
    public function lineOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => 'All lines']],
            $this->lines->map(fn ($l) => [
                'value' => (string) $l->id,
                'label' => ($l->parent_id ? '— ' : '') . $l->name,
            ])->all()
        );
    }

    /** Waste already saved against the selected run. */
    #[Computed]
    public function entries()
    {
        $stored = $this->storedRun;

        return $stored
            ? $stored->entries()->with(['cause', 'origin'])->orderBy('id')->get()
            : collect();
    }

    public function savedTotal(): float
    {
        return (float) $this->entries->sum('weight_kg');
    }

    /* ---------------- Selection ---------------- */

    public function selectRun(string $key): void
    {
        $this->runKey = $key;
        $this->resetRows();
        $this->confirming = false;
        $this->confirmNote = '';
        unset($this->run, $this->storedRun, $this->entries, $this->blocker);
    }

    public function updatedFilterLine(): void
    {
        unset($this->openRuns, $this->confirmedRuns);
    }

    public function updatedShowConfirmed(): void
    {
        unset($this->confirmedRuns);
    }

    /* ---------------- Entry rows ---------------- */

    protected function resetRows(): void
    {
        $this->rows = [];
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'uid' => Str::random(6),
            // Pre-set when there is only one origin to choose from; otherwise
            // the operator picks, because it changes the next field.
            'origin_id' => $this->origins->count() === 1 ? (string) $this->origins->first()->id : '',
            'origin_ref' => '',
            'cause_id' => '',
            'weight' => '',
        ];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    /** Changing a row's origin invalidates the classification chosen under the old one. */
    public function updatedRows($value, $name): void
    {
        if (str_ends_with($name, '.origin_id')) {
            $i = (int) explode('.', $name)[0];
            $this->rows[$i]['origin_ref'] = '';
        }
    }

    /* ---------------- Save ---------------- */

    /**
     * Append the filled-in rows to the run.
     *
     * Rows are ADDED, never replaced: waste is weighed as it is produced, so a
     * shift is entered in several visits and an earlier save must not disappear
     * because a later one was smaller.
     */
    public function save(): void
    {
        if (! $this->ensureShiftOpen()) {
            return;
        }

        $run = $this->run;

        if (! $run) {
            session()->flash('err', 'Pick a run first.');

            return;
        }

        if ($this->isBlocked()) {
            session()->flash('err', $this->blockMessage());

            return;
        }

        if ($this->storedRun?->isConfirmed()) {
            session()->flash('err', 'This run is confirmed. Re-open it before adding more waste.');

            return;
        }

        // Only rows the operator actually filled in are considered; a blank
        // spare row at the bottom is not an error.
        $filled = array_values(array_filter(
            $this->rows,
            fn ($r) => ($r['cause_id'] ?? '') !== '' || ($r['weight'] ?? '') !== '' || ($r['origin_id'] ?? '') !== ''
        ));

        if ($filled === []) {
            session()->flash('err', 'Nothing to save — fill in a cause and a weight.');

            return;
        }

        $this->validate($this->rules($filled), [], $this->validationAttributes($filled));

        $model = Waste::findOrCreateRun($run);
        $originsById = $this->origins->keyBy('id');

        DB::connection('core')->transaction(function () use ($filled, $model, $originsById) {
            foreach ($filled as $row) {
                $origin = $originsById[(int) $row['origin_id']] ?? null;
                $ref = $origin?->needsRef() ? ($row['origin_ref'] ?: null) : null;

                ConversionWasteEntry::create([
                    'run_id' => $model->id,
                    'cause_id' => (int) $row['cause_id'],
                    'origin_id' => (int) $row['origin_id'],
                    'origin_ref' => $ref,
                    'origin_ref_id' => $ref ? $origin?->refId($ref) : null,
                    'weight_kg' => (float) $row['weight'],
                    'user_id' => auth()->id(),
                    'username' => auth()->user()?->username ?? auth()->user()?->name,
                ]);
            }
        });

        $this->resetRows();
        unset($this->storedRun, $this->entries);

        session()->flash('ok', count($filled) . ' waste ' . Str::plural('entry', count($filled)) . ' saved.');
    }

    /** Validation is built only for the rows being saved, so indexes line up. */
    protected function rules(array $filled): array
    {
        $rules = [];

        foreach (array_keys($filled) as $i) {
            $rules["rows.$i.origin_id"] = ['required', 'integer', 'exists:core.waste_origins,id'];
            $rules["rows.$i.cause_id"] = ['required', 'integer', 'exists:core.waste_causes,id'];
            // Zero is not waste, and a negative weight is a typo.
            $rules["rows.$i.weight"] = ['required', 'numeric', 'gt:0', 'max:999999'];

            if ($this->originNeedsRef($filled[$i]['origin_id'] ?? 0)) {
                $rules["rows.$i.origin_ref"] = ['required', 'string', 'max:255'];
            }
        }

        return $rules;
    }

    protected function validationAttributes(array $filled): array
    {
        $attrs = [];
        foreach (array_keys($filled) as $i) {
            $n = $i + 1;
            $attrs["rows.$i.origin_id"] = "origin on row {$n}";
            $attrs["rows.$i.cause_id"] = "cause on row {$n}";
            $attrs["rows.$i.weight"] = "weight on row {$n}";
            $attrs["rows.$i.origin_ref"] = "classification on row {$n}";
        }

        return $attrs;
    }

    /* ---------------- Confirm ---------------- */

    public function startConfirm(): void
    {
        if (! $this->canConfirm()) {
            return;
        }
        $this->confirming = true;
    }

    public function cancelConfirm(): void
    {
        $this->confirming = false;
        $this->confirmNote = '';
    }

    /**
     * Close the run.
     *
     * A run with no entries can still be confirmed — some shifts genuinely
     * produce no waste — but it is recorded as a deliberate nil return, because
     * "we checked and there was none" is a different fact from "nobody looked".
     */
    public function confirm(): void
    {
        if (! $this->ensureShiftOpen()) {
            return;
        }

        if (! $this->canConfirm()) {
            session()->flash('err', 'You do not have permission to confirm waste.');

            return;
        }

        $run = $this->run;
        if (! $run) {
            return;
        }

        if ($this->isBlocked()) {
            session()->flash('err', $this->blockMessage());

            return;
        }

        $model = Waste::findOrCreateRun($run);
        $isNil = $model->entries()->count() === 0;

        Waste::confirm($model, $isNil, $this->confirmNote ?: null);

        $this->confirming = false;
        $this->confirmNote = '';
        unset($this->openRuns, $this->confirmedRuns, $this->storedRun, $this->entries, $this->blocker);

        session()->flash('ok', $isNil
            ? 'Run confirmed as a nil return — no waste recorded.'
            : 'Run confirmed. Its waste is now closed.');

        // Move straight on to whatever is next in the queue.
        $open = $this->openRuns;
        $this->runKey = $open !== [] ? Waste::keyOf($open[0]) : null;
        $this->resetRows();
        unset($this->run, $this->storedRun, $this->entries, $this->blocker);
    }

    public function reopen(): void
    {
        if (! $this->canReopen()) {
            session()->flash('err', 'You do not have permission to re-open a confirmed run.');

            return;
        }

        $stored = $this->storedRun;
        if (! $stored) {
            return;
        }

        Waste::reopen($stored);
        unset($this->openRuns, $this->confirmedRuns, $this->storedRun, $this->entries, $this->blocker);

        session()->flash('ok', 'Run re-opened — its waste can be corrected.');
    }

    /** Remove one saved entry (a mistyped weight), while the run is open. */
    public function deleteEntry(int $id): void
    {
        $stored = $this->storedRun;
        if (! $stored || $stored->isConfirmed()) {
            session()->flash('err', 'This run is confirmed. Re-open it before changing its waste.');

            return;
        }

        $stored->entries()->whereKey($id)->delete();
        unset($this->entries);

        session()->flash('ok', 'Entry removed.');
    }

    /* ---------------- Messages ---------------- */

    public function blockMessage(): string
    {
        $b = $this->blocker;

        if (! $b) {
            return '';
        }

        return sprintf(
            'Waste for %s (%s shift, %s) has not been confirmed yet. Clear that run first — it is at the top of the list.',
            $b['line_name'] ?: ('line #' . $b['line_id']),
            strtolower($b['shift']),
            \Illuminate\Support\Carbon::parse($b['date'])->format('d/m/Y')
        );
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.conversion-waste');
    }
}
