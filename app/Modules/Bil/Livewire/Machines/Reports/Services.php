<?php

namespace Modules\Bil\Livewire\Machines\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Core\Models\Division;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;
use Modules\Core\Models\ServiceType;
use Modules\Core\Models\Staff;

/**
 * BIL → Machines → Reports → Services. Rebuild of the legacy
 * report_factory_machine.php, over factory_machine_maintenance.
 *
 * The legacy screen rendered a card per project with the jobs nested inside and
 * a "total stop time" per group. This keeps the same information but as a
 * filterable table — which is what every other gds report is, and what makes
 * export/print work — with the group totals available through the summary view.
 *
 * Extends RawMaterialReport for filters, date range, pagination, export, print
 * and row edit/delete. That base is namespaced under RawMaterials but is
 * entirely generic; renaming it across the nine existing reports is a separate
 * tidy-up, not part of this change.
 */
#[Title('Services Report')]
class Services extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Services Report';
    }

    public function printKey(): string
    {
        return 'services';
    }

    public function subtitle(): string
    {
        return 'Service jobs logged against machines — who worked on what, and for how long.';
    }

    /** This report lives under Machines, not Raw Materials. */
    protected function reportPageKey(): string
    {
        return 'bil.machines.reports.services';
    }

    protected function printRouteName(): string
    {
        return 'bil.machines.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.machines.reports.download';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'lines' => MachineLine::treeOrder()->get()
                ->mapWithKeys(fn ($l) => [$l->name => ($l->parent_id ? '— ' : '') . $l->name])->all(),
            'projects' => MachineProject::roots()->orderBy('name')->pluck('name', 'name')->all(),
            'subprojects' => MachineProject::whereNotNull('parent_id')->orderBy('name')->pluck('name', 'name')->all(),
            'divisions' => Division::orderBy('name')->get()
                ->mapWithKeys(fn ($d) => [$d->id => $d->name])->all(),
            'staff' => Staff::orderBy('name')->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->name])->all(),
            'types' => ServiceType::orderBy('sort_order')->pluck('name', 'id')->all(),
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'line' => ['label' => 'Line', 'options' => $o['lines']],
            'project' => ['label' => 'Project', 'options' => $o['projects']],
            'subproject' => ['label' => 'Sub-project', 'options' => $o['subprojects']],
            'service_type' => ['label' => 'Service Type', 'options' => $o['types']],
            'division' => ['label' => 'Division', 'options' => $o['divisions']],
            'staff' => ['label' => 'Staff', 'options' => $o['staff']],
        ];
    }

    /**
     * Base query. `date` is a varchar in Y/m/d form, so the range is compared as
     * a string — which sorts correctly in that format and matches how the
     * legacy report filtered.
     */
    protected function base()
    {
        $f = $this->filters;

        return DB::connection('bil')->table('factory_machine_maintenance as m')
            ->leftJoin('core.service_types as t', 't.id', '=', 'm.service_type_id')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('m.date', '>=', str_replace('-', '/', $this->dateFrom)))
            ->when($this->dateTo !== '', fn ($q) => $q->where('m.date', '<=', str_replace('-', '/', $this->dateTo)))
            ->when($f['line'] ?? '', fn ($q, $v) => $q->where('m.linename', $v))
            ->when($f['project'] ?? '', fn ($q, $v) => $q->where('m.project', $v))
            ->when($f['subproject'] ?? '', fn ($q, $v) => $q->where('m.subproject', $v))
            ->when($f['service_type'] ?? '', fn ($q, $v) => $q->where('m.service_type_id', $v))
            ->when($f['division'] ?? '', fn ($q, $v) => $q->where('m.division_id', $v))
            ->when($f['staff'] ?? '', fn ($q, $v) => $q->where('m.staff_id', $v))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('m.jobid', 'like', $term)
                  ->orWhere('m.jobtitle', 'like', $term)
                  ->orWhere('m.staff', 'like', $term)
                  ->orWhere('m.note', 'like', $term);
            }));
    }

    /**
     * The legacy report_factory_machine screen was a per-project grouping with
     * each project's jobs listed underneath it. Summary (by project) is the
     * default view, and each of its rows opens its own jobs inline.
     *
     * Project names are globally unique (enforced on machine_projects.name), so
     * the project name is a safe key for a summary row that has no id.
     */
    public function expandableBy(): ?string
    {
        return 'project';
    }

    /** Job columns for the nested table. No row actions — this is a read-out. */
    public function detailColumns(): array
    {
        return [
            ['Job ID', 'jobid'],
            ['Job Title', 'jobtitle'],
            ['Type', 'service_type', fn ($r) => $r->service_type
                ? '<span class="badge badge-muted">' . e($r->service_type) . '</span>'
                : '<span class="text-muted">Unclassified</span>'],
            ['Sub-project', 'subproject', fn ($r) => e($r->subproject ?: '—')],
            ['Division', 'division', fn ($r) => e(ucwords(strtolower((string) $r->division)))],
            ['Staff', 'staff', fn ($r) => e(ucwords(strtolower((string) $r->staff)))],
            ['Start', 'starttime'],
            ['End', 'endtime'],
            ['Duration', 'duration', fn ($r) => e(self::humanDuration($r->duration))],
            ['Note', 'note', fn ($r) => nl2br(e((string) $r->note))],
        ];
    }

    public function detailTitle(string $key): string
    {
        return $key;
    }

    /**
     * The legacy screen headed each project block with its line, code and total
     * stop time. Same three facts, on one line under the project name.
     */
    public function detailSubtitle(string $key): string
    {
        $row = $this->base()
            ->where('m.project', $key)
            ->selectRaw('m.linename, COUNT(*) as jobs, ' . self::MINUTES_SQL . ' as minutes')
            ->groupBy('m.linename')
            ->first();

        if (! $row) {
            return '';
        }

        $code = MachineProject::where('name', $key)->value('code');

        return trim(($row->linename ?: '—')
            . ($code ? ' · ' . $code : '')
            . ' · ' . $row->jobs . ' ' . str($row->jobs === 1 ? 'job' : 'jobs')
            . ' · total stop time ' . self::formatMinutes((int) $row->minutes));
    }

    /**
     * The jobs behind one project row, under the same filters and date range as
     * the summary it sits in — so the list always reconciles with the job count
     * beside it.
     */
    public function detailRows(string $key): iterable
    {
        return $this->base()
            ->where('m.project', $key)
            ->select([
                'm.id', 'm.jobid', 'm.jobtitle', 'm.subproject', 'm.division',
                'm.staff', 'm.starttime', 'm.endtime', 'm.duration', 'm.note',
                't.name as service_type',
            ])
            ->orderByDesc('m.id')
            ->get();
    }

    public function views(): array
    {
        return [
            'by_project' => [
                'label' => 'Summary (by project)',
                'type' => 'summary',
                'columns' => [
                    ['Line', 'linename'],
                    ['Project', 'project'],
                    ['Jobs', 'jobs'],
                    ['Total stop time', 'minutes', fn ($r) => e(self::formatMinutes((int) $r->minutes))],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('m.linename, m.project, COUNT(*) as jobs, ' . self::MINUTES_SQL . ' as minutes')
                    ->groupBy('m.linename', 'm.project')
                    ->orderByDesc('jobs'),
            ],
            'details' => [
                'label' => 'Job Details',
                'type' => 'table',
                'columns' => [
                    ['Job ID', 'jobid'],
                    ['Job Title', 'jobtitle'],
                    ['Type', 'service_type', fn ($r) => $r->service_type
                        ? '<span class="badge badge-muted">' . e($r->service_type) . '</span>'
                        : '<span class="text-muted">Unclassified</span>'],
                    ['Line', 'linename'],
                    ['Project', 'project'],
                    ['Sub-project', 'subproject', fn ($r) => e($r->subproject ?: '—')],
                    ['Division', 'division', fn ($r) => e(ucwords(strtolower((string) $r->division)))],
                    ['Staff', 'staff', fn ($r) => e(ucwords(strtolower((string) $r->staff)))],
                    ['Start', 'starttime'],
                    ['End', 'endtime'],
                    ['Duration', 'duration', fn ($r) => e(self::humanDuration($r->duration))],
                    ['Note', 'note', fn ($r) => nl2br(e($r->note))],
                ],
                'query' => fn () => $this->base()->select([
                    'm.id', 'm.jobid', 'm.jobtitle', 'm.linename', 'm.project', 'm.subproject',
                    'm.division', 'm.staff', 'm.starttime', 'm.endtime', 'm.duration', 'm.note',
                    't.name as service_type',
                ])->orderByDesc('m.id'),
            ],
            'by_staff' => [
                'label' => 'Summary (by staff)',
                'type' => 'summary',
                'columns' => [
                    ['Division', 'division', fn ($r) => e(ucwords(strtolower((string) $r->division)))],
                    ['Staff', 'staff', fn ($r) => e(ucwords(strtolower((string) $r->staff)))],
                    ['Jobs', 'jobs'],
                    ['Total stop time', 'minutes', fn ($r) => e(self::formatMinutes((int) $r->minutes))],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw('m.division, m.staff, COUNT(*) as jobs, ' . self::MINUTES_SQL . ' as minutes')
                    ->groupBy('m.division', 'm.staff')
                    ->orderByDesc('jobs'),
            ],
            'by_type' => [
                'label' => 'Summary (by service type)',
                'type' => 'summary',
                'columns' => [
                    ['Service type', 'service_type', fn ($r) => e($r->service_type ?? 'Unclassified')],
                    ['Jobs', 'jobs'],
                    ['Total stop time', 'minutes', fn ($r) => e(self::formatMinutes((int) $r->minutes))],
                ],
                'query' => fn () => $this->base()
                    ->selectRaw("COALESCE(t.name, 'Unclassified') as service_type, COUNT(*) as jobs, " . self::MINUTES_SQL . ' as minutes')
                    ->groupBy('service_type')
                    ->orderByDesc('jobs'),
            ],
        ];
    }

    /**
     * `duration` is the legacy {"d":_,"h":_,"m":_} JSON, so totals are summed by
     * pulling the parts out in SQL rather than re-deriving from the timestamps
     * (which are varchars).
     */
    private const MINUTES_SQL = "SUM(
        COALESCE(JSON_EXTRACT(m.duration, '$.d'), 0) * 1440
      + COALESCE(JSON_EXTRACT(m.duration, '$.h'), 0) * 60
      + COALESCE(JSON_EXTRACT(m.duration, '$.m'), 0)
    )";

    /** Render the stored duration JSON the way the legacy report did. */
    public static function humanDuration(?string $json): string
    {
        $d = json_decode((string) $json, true);
        if (! is_array($d)) {
            return '—';
        }

        return self::formatMinutes(
            ((int) ($d['d'] ?? 0)) * 1440 + ((int) ($d['h'] ?? 0)) * 60 + (int) ($d['m'] ?? 0)
        );
    }

    public static function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        $parts = [];
        if ($days = intdiv($minutes, 1440)) {
            $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
        }
        if ($hours = intdiv($minutes % 1440, 60)) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($mins = $minutes % 60) {
            $parts[] = $mins . ' minute' . ($mins > 1 ? 's' : '');
        }

        return implode(', ', $parts);
    }

    /* ---------------- Row edit / delete ---------------- */

    public function editFields(): array
    {
        return [
            'jobtitle' => ['label' => 'Job Title'],
            'note' => ['label' => 'Note'],
        ];
    }

    protected function findRow(int $id)
    {
        return DB::connection('bil')->table('factory_machine_maintenance')->find($id);
    }

    protected function fillEdit(int $id): void
    {
        $row = $this->findRow($id);
        $this->edit = [
            'jobtitle' => $row->jobtitle ?? '',
            'note' => $row->note ?? '',
        ];
    }

    /** Called by the base's persistEdit(), which owns the guards and the modal. */
    public function saveEdit(): void
    {
        DB::connection('bil')->table('factory_machine_maintenance')
            ->where('id', $this->editingId)
            ->update([
                'jobtitle' => (string) ($this->edit['jobtitle'] ?? ''),
                'note' => (string) ($this->edit['note'] ?? ''),
            ]);
    }

    protected function performDelete(int $id): void
    {
        // No soft-delete column on this legacy table, so removal is real —
        // matching the legacy report's own delete action.
        DB::connection('bil')->table('factory_machine_maintenance')->where('id', $id)->delete();
        DB::connection('bil')->table('factory_machine_maintenance_comment')->where('report_id', $id)->delete();
    }
}
