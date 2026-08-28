<?php

namespace Modules\Core\Livewire\Settings;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Support\ConversionWaste as Waste;
use Modules\Core\Models\WasteCause;
use Modules\Core\Models\WasteOrigin;
use Modules\Core\Support\Settings;

/**
 * Settings → Waste. The vocabulary the Conversion Waste screen is built from:
 * the CAUSES waste is attributed to, and the ORIGINS it came from.
 *
 * Every cause is offered under every origin — a bad cut is a bad cut whichever
 * material it spoiled — so the two lists are independent and edited side by
 * side rather than nested.
 *
 * An origin's SOURCE is what makes it more than a label: it names the lookup
 * the entry is then classified against (jumbo roll grade types, or raw
 * materials groups). The choices are fixed in code because each one maps to a
 * query; see WasteOrigin::SOURCES.
 *
 * Removing something already used does NOT delete it. A cause that has been
 * recorded against real waste is part of the record, so it is retired instead —
 * gone from the entry form, still readable in every report that shows it.
 */
#[Layout('core::layouts.admin')]
#[Title('Waste Settings')]
class WasteSettings extends Component
{
    /** list of ['uid','id','name','sort_order','is_active'] */
    public array $causes = [];

    /** list of ['uid','id','key','label','source','sort_order','is_active'] */
    public array $origins = [];

    /** The confirmation cut-over, as an ISO date. */
    public string $cutover = '';

    public const PAGE_KEY = 'settings.waste';

    public const CUTOVER_KEY = 'waste.confirmation_start';

    public function mount(): void
    {
        $this->cutover = (string) Settings::get(self::CUTOVER_KEY, '');
        $this->causes = WasteCause::ordered()->get()->map(fn ($c) => [
            'uid' => 'c' . $c->id,
            'id' => $c->id,
            'name' => $c->name,
            'sort_order' => $c->sort_order,
            'is_active' => (bool) $c->is_active,
        ])->all();

        $this->origins = WasteOrigin::ordered()->get()->map(fn ($o) => [
            'uid' => 'o' . $o->id,
            'id' => $o->id,
            'key' => $o->key,
            'label' => $o->label,
            'source' => $o->source,
            'sort_order' => $o->sort_order,
            'is_active' => (bool) $o->is_active,
        ])->all();
    }

    /* ---------------- Permissions ---------------- */

    public function mayEdit(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'edit');
    }

    /* ---------------- Rows ---------------- */

    public function addCause(): void
    {
        if (! $this->mayEdit()) {
            return;
        }

        $this->causes[] = [
            'uid' => 'n' . Str::random(6),
            'id' => null,
            'name' => '',
            // Ten apart, so a later insert between two needs no renumbering.
            'sort_order' => (count($this->causes) + 1) * 10,
            'is_active' => true,
        ];
    }

    public function addOrigin(): void
    {
        if (! $this->mayEdit()) {
            return;
        }

        $this->origins[] = [
            'uid' => 'n' . Str::random(6),
            'id' => null,
            'key' => '',
            'label' => '',
            'source' => 'none',
            'sort_order' => (count($this->origins) + 1) * 10,
            'is_active' => true,
        ];
    }

    /**
     * Take a row off the form.
     *
     * Anything already recorded against real waste is retired rather than
     * dropped: the entry rows keep pointing at it, so every report that has ever
     * shown it still reads correctly. Only a row nothing has used is actually
     * deleted, which also frees its name to be used again.
     */
    public function removeCause(int $index): void
    {
        if (! $this->mayEdit()) {
            return;
        }

        $row = $this->causes[$index] ?? null;
        if ($row && $row['id']) {
            $cause = WasteCause::find($row['id']);
            if ($cause) {
                if ($this->causeUseCount($cause->id) > 0) {
                    $cause->delete();
                    session()->flash('ok', '“' . $cause->name . '” is used by existing waste entries, so it has been retired rather than deleted. It stays visible in reports.');
                } else {
                    $cause->forceDelete();
                }
            }
        }

        unset($this->causes[$index]);
        $this->causes = array_values($this->causes);
    }

    public function removeOrigin(int $index): void
    {
        if (! $this->mayEdit()) {
            return;
        }

        $row = $this->origins[$index] ?? null;
        if ($row && $row['id']) {
            $origin = WasteOrigin::find($row['id']);
            if ($origin) {
                if ($this->originUseCount($origin->id) > 0) {
                    $origin->delete();
                    session()->flash('ok', '“' . $origin->label . '” is used by existing waste entries, so it has been retired rather than deleted. It stays visible in reports.');
                } else {
                    $origin->forceDelete();
                }
            }
        }

        unset($this->origins[$index]);
        $this->origins = array_values($this->origins);
    }

    /* ---------------- The confirmation cut-over ---------------- */

    /**
     * What moving the cut-over to `$this->cutover` would do to the queue.
     *
     * Shown BEFORE saving, because both directions are consequential and
     * neither is obvious from a date:
     *
     *   later  — unconfirmed runs drop off the queue and stop blocking. A
     *            backlog can be made to disappear by editing a date, which is
     *            exactly why this is previewed and attributed.
     *   earlier — historic production becomes unconfirmed and every affected
     *            line blocks at once.
     *
     * Counted against the live production table, so the numbers are real.
     */
    #[Computed]
    public function cutoverImpact(): ?array
    {
        $candidate = trim($this->cutover);
        $current = (string) Waste::cutover();

        if ($candidate === '' || $candidate === $current) {
            return null;
        }

        try {
            Carbon::parse($candidate);
        } catch (\Throwable) {
            return null;
        }

        $now = count(Waste::openRuns(null, 100000, $current));
        $then = count(Waste::openRuns(null, 100000, $candidate));

        return [
            'direction' => $candidate > $current ? 'later' : 'earlier',
            'now' => $now,
            'then' => $then,
            'delta' => $then - $now,
            'current' => $current,
        ];
    }

    /** Who last changed the cut-over, and when. */
    #[Computed]
    public function cutoverMeta(): ?object
    {
        return Settings::meta(self::CUTOVER_KEY);
    }

    /** The .env value, which is what "revert" restores. */
    public function cutoverConfigured(): string
    {
        return (string) Settings::configured('waste.confirmation_start', '');
    }

    public function cutoverIsOverridden(): bool
    {
        return Settings::isOverridden(self::CUTOVER_KEY);
    }

    /** Drop the override and fall back to whatever .env says. */
    public function revertCutover(): void
    {
        if (! $this->mayEdit()) {
            return;
        }

        Settings::forget(self::CUTOVER_KEY);
        $this->cutover = (string) Settings::get(self::CUTOVER_KEY, '');
        unset($this->cutoverImpact, $this->cutoverMeta);

        session()->flash('ok', 'Cut-over reverted to the environment setting (' . $this->cutoverConfigured() . ').');
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        if (! $this->mayEdit()) {
            session()->flash('err', 'You do not have permission to change waste settings.');

            return;
        }

        $this->validate($this->rules(), [], $this->attributes());

        foreach (array_values($this->causes) as $i => $row) {
            $cause = $row['id'] ? WasteCause::find($row['id']) : new WasteCause();
            if (! $cause) {
                continue;
            }
            $cause->name = trim($row['name']);
            $cause->sort_order = (int) ($row['sort_order'] ?: ($i + 1) * 10);
            $cause->is_active = (bool) ($row['is_active'] ?? false);
            $cause->save();
        }

        foreach (array_values($this->origins) as $i => $row) {
            $origin = $row['id'] ? WasteOrigin::find($row['id']) : new WasteOrigin();
            if (! $origin) {
                continue;
            }
            // The key is the stable handle reports and code match on, so it is
            // set once when the origin is created and never rewritten.
            if (! $origin->exists) {
                $origin->key = Str::slug(trim($row['key']) ?: trim($row['label']), '_');
            }
            $origin->label = trim($row['label']);
            $origin->source = array_key_exists($row['source'], WasteOrigin::SOURCES) ? $row['source'] : 'none';
            $origin->sort_order = (int) ($row['sort_order'] ?: ($i + 1) * 10);
            $origin->is_active = (bool) ($row['is_active'] ?? false);
            $origin->save();
        }

        // The cut-over last, so a validation failure on the lists cannot leave
        // it changed on its own.
        $cutover = trim($this->cutover);
        if ($cutover !== '' && $cutover !== (string) Waste::cutover()) {
            Settings::set(self::CUTOVER_KEY, $cutover);
        }

        $this->mount();
        unset($this->cutoverImpact, $this->cutoverMeta);
        session()->flash('ok', 'Waste settings saved.');
    }

    protected function rules(): array
    {
        // A cut-over in the future is legitimate (it disables the rule until
        // then); one before the app existed is a typo.
        $rules = [
            'cutover' => ['required', 'date_format:Y-m-d', 'after_or_equal:2017-01-01'],
        ];

        foreach (array_keys($this->causes) as $i) {
            $id = $this->causes[$i]['id'] ?? null;
            $rules["causes.$i.name"] = [
                'required', 'string', 'max:255',
                'unique:core.waste_causes,name' . ($id ? ',' . $id : ''),
            ];
            $rules["causes.$i.sort_order"] = ['nullable', 'integer', 'min:0'];
        }

        foreach (array_keys($this->origins) as $i) {
            $id = $this->origins[$i]['id'] ?? null;
            $rules["origins.$i.label"] = ['required', 'string', 'max:255'];
            $rules["origins.$i.source"] = ['required', 'string', 'in:' . implode(',', array_keys(WasteOrigin::SOURCES))];
            $rules["origins.$i.sort_order"] = ['nullable', 'integer', 'min:0'];
            // Only new origins supply a key; existing ones keep theirs.
            if (! $id) {
                $rules["origins.$i.key"] = ['nullable', 'string', 'max:32', 'unique:core.waste_origins,key'];
            }
        }

        return $rules;
    }

    protected function attributes(): array
    {
        $attrs = ['cutover' => 'confirmation cut-over'];
        foreach (array_keys($this->causes) as $i) {
            $attrs["causes.$i.name"] = 'cause';
            $attrs["causes.$i.sort_order"] = 'order';
        }
        foreach (array_keys($this->origins) as $i) {
            $attrs["origins.$i.label"] = 'origin';
            $attrs["origins.$i.source"] = 'classified by';
            $attrs["origins.$i.sort_order"] = 'order';
        }

        return $attrs;
    }

    /* ---------------- Usage counts ---------------- */

    /**
     * How much real waste already refers to each cause / origin.
     *
     * Shown next to every row so an admin can see what retiring one would
     * affect before doing it, and counted in one query per list rather than one
     * per row.
     */
    public function causeUsage(): array
    {
        return DB::connection('bil')->table('conversion_waste_entries')
            ->groupBy('cause_id')->selectRaw('cause_id, COUNT(*) n')
            ->pluck('n', 'cause_id')->all();
    }

    public function originUsage(): array
    {
        return DB::connection('bil')->table('conversion_waste_entries')
            ->groupBy('origin_id')->selectRaw('origin_id, COUNT(*) n')
            ->pluck('n', 'origin_id')->all();
    }

    private function causeUseCount(int $id): int
    {
        return (int) DB::connection('bil')->table('conversion_waste_entries')
            ->where('cause_id', $id)->count();
    }

    private function originUseCount(int $id): int
    {
        return (int) DB::connection('bil')->table('conversion_waste_entries')
            ->where('origin_id', $id)->count();
    }

    public function render()
    {
        return view('core::livewire.settings.waste-settings', [
            'sources' => WasteOrigin::SOURCES,
            'causeUsage' => $this->causeUsage(),
            'originUsage' => $this->originUsage(),
            'retiredCauses' => WasteCause::onlyTrashed()->ordered()->get(),
            'retiredOrigins' => WasteOrigin::onlyTrashed()->ordered()->get(),
        ]);
    }
}
