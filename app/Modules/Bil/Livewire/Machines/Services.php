<?php

namespace Modules\Bil\Livewire\Machines;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Models\Department;
use Modules\Core\Models\Division;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\Staff;

/**
 * BIL → Machines → Services. Rebuild of the legacy factory_machines.php
 * ("Machine Maintenance Entry"): a service job logged against a machine, by a
 * staff member, over a start/end window.
 *
 * Writes to factory_machine_maintenance. Both contracts the legacy app depends
 * on are reproduced exactly, because its report still reads these rows:
 *   - `jobid`  = {yy-mm-dd}-{linecode}-{maxId+1}, per Machine::generateJOBID()
 *   - `duration` = {"d":_,"h":_,"m":_} JSON, per js/machine.js convertMS()
 *
 * The name columns are written alongside the new ids — `division` gets the full
 * legacy string ("MAINTENANCE ELECTRICAL"), not the canonical division name, so
 * legacy reports that GROUP BY it keep working.
 *
 * The legacy form offered Division → Staff. Now that staff hang off a
 * department with an optional division, this asks for Department first and
 * treats Division as a filter, so staff with no division are still reachable.
 */
#[Layout('core::layouts.admin')]
#[Title('Machine Services')]
class Services extends Component
{
    public string $jobtitle = '';
    public ?int $service_type_id = null;
    public ?int $department_id = null;
    public ?int $division_id = null;
    public ?int $staff_id = null;
    public ?int $line_id = null;
    public ?int $project_id = null;
    public ?int $subproject_id = null;
    public string $startDate = '';
    public string $startTime = '';
    public string $endDate = '';
    public string $endTime = '';
    public string $note = '';

    public function mount(): void
    {
        $this->startDate = now()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    /* ---------------- Option sources ---------------- */

    #[Computed]
    public function serviceTypes()
    {
        return ServiceType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function departments()
    {
        // Only departments that actually have factory staff — the list also
        // holds org units like MARKETING that never service a machine.
        return Department::whereHas('staff')->orderBy('name')->get();
    }

    #[Computed]
    public function divisions()
    {
        return $this->department_id
            ? Division::where('department_id', $this->department_id)->orderBy('name')->get()
            : collect();
    }

    #[Computed]
    public function staffOptions()
    {
        if (! $this->department_id) {
            return collect();
        }

        return Staff::where('department_id', $this->department_id)
            ->where('is_active', true)
            ->when($this->division_id, fn ($q) => $q->where('division_id', $this->division_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Every line node — a job can be logged on a line or one of its sub-lines.
     *
     * Shaped for the searchable-select partial (which reads `id`/`name`) rather
     * than handed over as models: the label carries the tree indent, and with
     * ~50 nodes the list needs to be typed into, not scrolled.
     */
    #[Computed]
    public function lineOptions()
    {
        return MachineLine::treeOrder()->get()->map(fn ($l) => [
            'id' => $l->id,
            'name' => ($l->parent_id ? '— ' : '') . $l->name,
        ]);
    }

    #[Computed]
    public function projects()
    {
        return $this->line_id
            ? MachineProject::roots()->where('line_id', $this->line_id)->orderBy('name')->get()
            : collect();
    }

    #[Computed]
    public function subprojects()
    {
        return $this->project_id
            ? MachineProject::where('parent_id', $this->project_id)->orderBy('name')->get()
            : collect();
    }

    /* ---------------- Cascades ---------------- */

    public function updatedDepartmentId(): void
    {
        $this->division_id = null;
        $this->staff_id = null;
    }

    public function updatedDivisionId(): void
    {
        $this->staff_id = null;
    }

    public function updatedLineId(): void
    {
        $this->project_id = null;
        $this->subproject_id = null;
    }

    public function updatedProjectId(): void
    {
        $this->subproject_id = null;
    }

    /* ---------------- Save ---------------- */

    protected function rules(): array
    {
        return [
            'jobtitle' => ['required', 'string', 'max:100'],
            'service_type_id' => ['required', 'exists:service_types,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'division_id' => ['nullable', 'exists:divisions,id'],
            'staff_id' => ['required', 'exists:staff,id'],
            'line_id' => ['required', 'exists:machine_lines,id'],
            'project_id' => ['required', 'exists:machine_projects,id'],
            'subproject_id' => ['nullable', 'exists:machine_projects,id'],
            'startDate' => ['required', 'date'],
            'startTime' => ['required', 'date_format:H:i'],
            'endDate' => ['required', 'date'],
            'endTime' => ['required', 'date_format:H:i'],
            'note' => ['required', 'string'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $start = \Carbon\Carbon::parse($this->startDate . ' ' . $this->startTime);
        $end = \Carbon\Carbon::parse($this->endDate . ' ' . $this->endTime);

        if ($end->lessThanOrEqualTo($start)) {
            $this->addError('endTime', 'The end of the job must come after its start.');

            return;
        }

        $line = MachineLine::find($this->line_id);
        $project = MachineProject::find($this->project_id);
        $subproject = $this->subproject_id ? MachineProject::find($this->subproject_id) : null;
        $staff = Staff::with('division')->find($this->staff_id);

        // The legacy report keys off the *end* date, not the start.
        $date = $end->format('Y/m/d');

        DB::connection('bil')->table('factory_machine_maintenance')->insert([
            'jobtitle' => $this->jobtitle,
            'jobid' => $this->generateJobId($line, $end),
            'linename' => $line->name,
            'project' => $project->name,
            'subproject' => $subproject?->name ?? '',
            // Full legacy string, so existing GROUP BY division reports hold.
            'division' => $staff->division?->legacyLabel() ?? '',
            'staff' => $staff->name,
            'user' => (string) auth()->user()?->username,
            'date' => $date,
            'starttime' => $start->format('Y/m/d H:i'),
            'endtime' => $end->format('Y/m/d H:i'),
            'note' => $this->note,
            'duration' => $this->durationJson($start, $end),
            'service_type_id' => $this->service_type_id,
            // The BEFORE INSERT trigger would derive these from the names, but
            // writing them directly keeps this page correct on its own terms.
            'line_id' => $line->id,
            'project_id' => $project->id,
            'subproject_id' => $subproject?->id,
            'division_id' => $staff->division_id,
            'staff_id' => $staff->id,
        ]);

        session()->flash('ok', 'Service job logged.');
        $this->reset(['jobtitle', 'note', 'startTime', 'endTime']);
    }

    /** {yy-mm-dd}-{LINECODE}-{maxId+1} — Machine::generateJOBID(). */
    private function generateJobId(MachineLine $line, \Carbon\Carbon $end): string
    {
        $next = (int) DB::connection('bil')->table('factory_machine_maintenance')->max('id') + 1;
        $code = strtoupper($line->code ?: 'UNK');

        return $end->format('y-m-d') . '-' . $code . '-' . $next;
    }

    /** {"d":_,"h":_,"m":_} — js/machine.js convertMS(). */
    private function durationJson(\Carbon\Carbon $start, \Carbon\Carbon $end): string
    {
        $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);

        return json_encode([
            'd' => intdiv($minutes, 1440),
            'h' => intdiv($minutes % 1440, 60),
            'm' => $minutes % 60,
        ]);
    }

    public function render()
    {
        return view('bil::livewire.machines.services');
    }
}
