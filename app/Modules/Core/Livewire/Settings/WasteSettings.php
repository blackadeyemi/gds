<?php

namespace Modules\Core\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Models\WasteCause;
use Modules\Core\Models\WasteOrigin;

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

    public const PAGE_KEY = 'settings.waste';

    public function mount(): void
    {
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

        $this->mount();
        session()->flash('ok', 'Waste settings saved.');
    }

    protected function rules(): array
    {
        $rules = [];

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
        $attrs = [];
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
        return DB::connection('core')->table('conversion_waste_entries')
            ->groupBy('cause_id')->selectRaw('cause_id, COUNT(*) n')
            ->pluck('n', 'cause_id')->all();
    }

    public function originUsage(): array
    {
        return DB::connection('core')->table('conversion_waste_entries')
            ->groupBy('origin_id')->selectRaw('origin_id, COUNT(*) n')
            ->pluck('n', 'origin_id')->all();
    }

    private function causeUseCount(int $id): int
    {
        return (int) DB::connection('core')->table('conversion_waste_entries')
            ->where('cause_id', $id)->count();
    }

    private function originUseCount(int $id): int
    {
        return (int) DB::connection('core')->table('conversion_waste_entries')
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
