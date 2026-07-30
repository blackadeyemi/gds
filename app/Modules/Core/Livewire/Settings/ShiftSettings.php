<?php

namespace Modules\Core\Livewire\Settings;

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Models\ShiftContext;
use Modules\Core\Models\ShiftWindow;
use Modules\Core\Support\ShiftService;

/**
 * Configure shift windows per area. Each context has a master Active toggle and
 * any number of named windows (Day/Night/…), each with a start/end time that
 * may wrap midnight. Gated by manage-shift-settings (Admin + Operations Manager
 * etc.). Runtime open/closed enforcement reads what's saved here.
 */
#[Layout('core::layouts.admin')]
#[Title('Shift Settings')]
class ShiftSettings extends Component
{
    /** contextId => bool */
    public array $active = [];
    /** contextId => list of ['uid','id','name','start','end','enabled'] */
    public array $windows = [];

    public function mount(): void
    {
        foreach (ShiftContext::with('windows')->get() as $ctx) {
            $this->active[$ctx->id] = $ctx->is_active;
            $this->windows[$ctx->id] = $ctx->windows->map(fn ($w) => [
                'uid' => 'w' . $w->id,
                'id' => $w->id,
                'name' => $w->name,
                'start' => substr((string) $w->start_time, 0, 5),
                'end' => substr((string) $w->end_time, 0, 5),
                'enabled' => (bool) $w->is_enabled,
            ])->all();
        }
    }

    public function addWindow(int $contextId): void
    {
        $this->windows[$contextId][] = [
            'uid' => 'n' . Str::random(6),
            'id' => null,
            'name' => '',
            'start' => '07:00',
            'end' => '19:00',
            'enabled' => true,
        ];
    }

    public function removeWindow(int $contextId, int $index): void
    {
        unset($this->windows[$contextId][$index]);
        $this->windows[$contextId] = array_values($this->windows[$contextId]);
    }

    public function save(): void
    {
        $this->validate($this->rules(), [], $this->attributes());

        foreach ($this->windows as $contextId => $rows) {
            $context = ShiftContext::find($contextId);
            if (! $context) {
                continue;
            }

            $context->update(['is_active' => (bool) ($this->active[$contextId] ?? false)]);

            $keepIds = [];
            foreach (array_values($rows) as $i => $row) {
                $win = $row['id'] ? ShiftWindow::find($row['id']) : new ShiftWindow(['shift_context_id' => $contextId]);
                if (! $win) {
                    continue;
                }
                $win->shift_context_id = $contextId;
                $win->name = trim($row['name']);
                $win->start_time = $row['start'];
                $win->end_time = $row['end'];
                $win->is_enabled = (bool) ($row['enabled'] ?? false);
                $win->sort_order = $i;
                $win->save();
                $keepIds[] = $win->id;
            }

            // Drop windows the admin removed from the UI.
            $context->windows()->whereNotIn('id', $keepIds ?: [0])->delete();
        }

        session()->flash('ok', 'Shift settings saved.');
    }

    protected function rules(): array
    {
        $rules = [];
        foreach ($this->windows as $contextId => $rows) {
            foreach (array_keys($rows) as $i) {
                $rules["windows.$contextId.$i.name"] = ['required', 'string', 'max:40'];
                $rules["windows.$contextId.$i.start"] = ['required', 'date_format:H:i'];
                $rules["windows.$contextId.$i.end"] = ['required', 'date_format:H:i'];
            }
        }
        return $rules;
    }

    protected function attributes(): array
    {
        $attrs = [];
        foreach ($this->windows as $contextId => $rows) {
            foreach (array_keys($rows) as $i) {
                $attrs["windows.$contextId.$i.name"] = 'shift name';
                $attrs["windows.$contextId.$i.start"] = 'start time';
                $attrs["windows.$contextId.$i.end"] = 'end time';
            }
        }
        return $attrs;
    }

    public function render()
    {
        $svc = new ShiftService();
        $contexts = ShiftContext::orderBy('module')->orderBy('label')->get()
            ->map(function ($ctx) use ($svc) {
                $ctx->status = $svc->status($ctx->key);
                return $ctx;
            })
            ->groupBy('module');

        return view('core::livewire.settings.shift-settings', ['grouped' => $contexts]);
    }
}
