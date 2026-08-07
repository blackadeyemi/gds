<?php

namespace Modules\Bil\Livewire\Machines;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\Concerns\LegacyStatQueries;
use Modules\Bil\Support\ServiceDuration;
use Modules\Core\Livewire\StatisticsPage;
use Modules\Core\Models\Division;
use Modules\Core\Models\Factory;
use Modules\Core\Models\MachineLine;
use Modules\Core\Models\MachineProject;
use Modules\Core\Models\Staff;

/**
 * Machines → Statistics. The counterpart to Raw Materials → Statistics, over the
 * machine hierarchy and the service jobs logged against it
 * (factory_machine_maintenance, 43k rows back to 2014).
 *
 * Where the Raw Materials dashboard measures material moving through the plant,
 * this one measures the plant itself: what is installed, how often it is worked
 * on, and how long it stands still while that happens. "Stop time" is the
 * legacy term and this keeps it.
 *
 * `date` is a varchar in 'Y/m/d' form (see LegacyStatQueries) and it holds a few
 * dd/mm/yy rows from before the format settled, which every query here excludes.
 */
#[Title('Machines Statistics')]
class Statistics extends StatisticsPage
{
    use LegacyStatQueries;

    /**
     * The window a date can plausibly fall in. Three rows carry a dd/mm/yy date
     * from before the format settled: '20/08/26' sorts below the lower bound and
     * '26/08/20' above the upper one, so two string comparisons drop all three
     * without a regex scan. Bounded ranges exclude them anyway — this is what
     * keeps All time honest.
     */
    private const MIN_DATE = '2000/01/01';

    private const MAX_DATE = '2100/01/01';

    public function pageTitle(): string
    {
        return 'Machines Statistics';
    }

    public function pageSubtitle(): string
    {
        return 'The machine hierarchy, the service jobs logged against it, and the time it stood still.';
    }

    /**
     * All time is offered here, unlike Raw Materials. That page caps at 12
     * months because its tables run to 310k rows over 25 years; maintenance is
     * 43k rows and every aggregate below stays comfortably under a second.
     */
    public function rangeOptions(): array
    {
        return [
            '7d' => ['Last 7 days', 7],
            '30d' => ['Last 30 days', 30],
            '90d' => ['Last 90 days', 90],
            '12m' => ['Last 12 months', 365],
            'all' => ['All time', null],
        ];
    }

    protected function exportRouteName(): ?string
    {
        return 'bil.machines.statistics.export';
    }

    protected function exportPageKey(): ?string
    {
        return 'bil.machines.statistics';
    }

    public function sections(): array
    {
        return [
            'overview' => 'Overview',
            'machines' => 'Machines',
            'services' => 'Service Jobs',
            'downtime' => 'Downtime',
            'workforce' => 'Workforce',
        ];
    }

    protected function section(string $key): array
    {
        return match ($key) {
            'machines' => $this->machinesSection(),
            'services' => $this->servicesSection(),
            'downtime' => $this->downtimeSection(),
            'workforce' => $this->workforceSection(),
            default => $this->overviewSection(),
        };
    }

    /* ---------------- Query helpers ---------------- */

    protected function db()
    {
        return DB::connection('bil');
    }

    /** Service jobs scoped to the selected range, aliased `m` for the duration SQL. */
    private function jobs()
    {
        [$from, $to] = $this->bounds('/');

        return $this->db()->table('factory_machine_maintenance as m')
            ->whereBetween('m.date', [self::MIN_DATE, self::MAX_DATE])
            ->when($from, fn ($q) => $q->whereBetween('m.date', [$from, $to]));
    }

    /** Total stop time over the range, in minutes. */
    private function stopMinutes(): int
    {
        return (int) ($this->jobs()->selectRaw(ServiceDuration::MINUTES_SQL . ' as v')->value('v') ?? 0);
    }

    /**
     * Top groups by job count or stop time, grouped on an INDEXED id column and
     * named in PHP afterwards — grouping on the name strings instead means a
     * temp table plus a collation filesort, which is the trap the Raw Materials
     * dashboard already hit.
     *
     * @param  string  $by  'jobs' | 'minutes'
     */
    private function topGroups(string $idCol, string $by = 'jobs', int $limit = 10)
    {
        $agg = $by === 'minutes' ? ServiceDuration::MINUTES_SQL : 'COUNT(*)';

        return $this->jobs()
            ->whereNotNull($idCol)
            ->selectRaw("$idCol as k, $agg as v")
            ->groupBy($idCol)->orderByDesc('v')->limit($limit)->get();
    }

    /** Resolve the ids from topGroups() to display names, keeping their order. */
    private function labelled($rows, array $names, string $fallback = '—'): array
    {
        return $rows->map(fn ($r) => $names[$r->k] ?? $fallback)->all();
    }

    private function values($rows, bool $asHours = false): array
    {
        return $rows->map(fn ($r) => $asHours ? ServiceDuration::hours((float) $r->v) : (float) $r->v)->all();
    }

    /** A time series of job counts or stop-time hours over the range. */
    private function jobSeries(bool $hours = false): array
    {
        // series() queries the table unaliased, so the duration SQL is spelt
        // out here rather than reused from ServiceDuration (which assumes `m`).
        $agg = $hours ? 'ROUND(SUM(COALESCE(duration_minutes, 0)) / 60)' : 'COUNT(*)';

        return $this->series('factory_machine_maintenance', 'date', '/', $agg,
            fn ($q) => $q->whereBetween('date', [self::MIN_DATE, self::MAX_DATE]));
    }

    private function stopTile(string $label, int $minutes, ?string $sub = null, ?string $tone = null): array
    {
        return $this->tile($label, ServiceDuration::format($minutes), $sub, $tone);
    }

    /* ---------------- Sections ---------------- */

    protected function overviewSection(): array
    {
        $jobs = (int) $this->jobs()->count();
        $minutes = $this->stopMinutes();
        $lines = (int) MachineLine::count();
        $projects = (int) MachineProject::count();
        $activeStaff = (int) $this->jobs()->distinct()->count('m.staff_id');

        $overTime = $this->jobSeries();

        $byDivision = $this->topGroups('m.division_id', 'jobs', 8);
        $divNames = Division::whereIn('id', $byDivision->pluck('k'))->pluck('name', 'id')->all();

        $byLine = $this->topGroups('m.line_id', 'jobs', 10);
        $lineNames = MachineLine::whereIn('id', $byLine->pluck('k'))->pluck('name', 'id')->all();

        return [
            'tiles' => [
                $this->tile('Service Jobs', $this->num($jobs), $this->rangeLabel(), 'brand'),
                $this->stopTile('Total Stop Time', $minutes, $this->rangeLabel()),
                $this->stopTile('Average per Job', $jobs ? (int) round($minutes / $jobs) : 0),
                $this->tile('Machines', $this->num($lines), 'lines and sub-lines'),
                $this->tile('Projects', $this->num($projects), 'and sub-projects'),
                $this->tile('Staff on Jobs', $this->num($activeStaff), $this->rangeLabel()),
            ],
            'charts' => [
                $this->chartSpec('mc-jobs-time', 'line', 'Service Jobs', $overTime['labels'],
                    [['name' => 'Jobs', 'data' => $overTime['data']]],
                    ['span' => 2, 'subtitle' => 'Jobs logged · ' . $this->rangeLabel(), 'height' => 260]),
                $this->chartSpec('mc-div', 'donut', 'Jobs by Division',
                    $this->labelled($byDivision, $divNames, 'Unassigned'),
                    [['name' => 'Jobs', 'data' => $this->values($byDivision)]],
                    ['subtitle' => 'Who did the work']),
                $this->chartSpec('mc-line', 'hbar', 'Busiest Machines',
                    $this->labelled($byLine, $lineNames),
                    [['name' => 'Jobs', 'data' => $this->values($byLine)]],
                    ['subtitle' => 'Jobs per line · ' . $this->rangeLabel(), 'height' => 300]),
            ],
        ];
    }

    /**
     * The installed base. Counts here are current state, not range-dependent —
     * a machine doesn't stop existing because the date filter moved.
     */
    protected function machinesSection(): array
    {
        $rootLines = (int) MachineLine::whereNull('parent_id')->count();
        $subLines = (int) MachineLine::whereNotNull('parent_id')->count();
        $rootProjects = (int) MachineProject::whereNull('parent_id')->count();
        $subProjects = (int) MachineProject::whereNotNull('parent_id')->count();
        $unassigned = (int) MachineLine::whereNull('factory_id')->whereNull('parent_id')->count();

        // Lines per factory, counting sub-lines under their factory too.
        $byFactory = MachineLine::query()
            ->selectRaw('factory_id as k, COUNT(*) as v')
            ->groupBy('factory_id')->orderByDesc('v')->get();
        $factoryNames = Factory::whereIn('id', $byFactory->pluck('k')->filter())->pluck('name', 'id')->all();

        // Projects per line — how much of the plant each machine covers.
        $projectsPerLine = MachineProject::query()
            ->selectRaw('line_id as k, COUNT(*) as v')
            ->whereNotNull('line_id')
            ->groupBy('line_id')->orderByDesc('v')->limit(10)->get();
        $plNames = MachineLine::whereIn('id', $projectsPerLine->pluck('k'))->pluck('name', 'id')->all();

        // Which machines actually get worked on, by stop time.
        $stopByLine = $this->topGroups('m.line_id', 'minutes', 10);
        $stopNames = MachineLine::whereIn('id', $stopByLine->pluck('k'))->pluck('name', 'id')->all();

        return [
            'tiles' => [
                $this->tile('Factories', $this->num((int) Factory::count())),
                $this->tile('Lines', $this->num($rootLines), 'top level', 'brand'),
                $this->tile('Sub-lines', $this->num($subLines)),
                $this->tile('Projects', $this->num($rootProjects)),
                $this->tile('Sub-projects', $this->num($subProjects)),
                $this->tile('Unassigned', $this->num($unassigned), 'no factory'),
            ],
            'charts' => [
                $this->chartSpec('mc-factory', 'bar', 'Machines per Factory',
                    $this->labelled($byFactory, $factoryNames, 'Unassigned'),
                    [['name' => 'Lines', 'data' => $this->values($byFactory)]],
                    ['subtitle' => 'Lines and sub-lines installed']),
                $this->chartSpec('mc-proj-line', 'donut', 'Projects per Machine',
                    $this->labelled($projectsPerLine, $plNames),
                    [['name' => 'Projects', 'data' => $this->values($projectsPerLine)]]),
                $this->chartSpec('mc-stop-line', 'hbar', 'Stop Time by Machine',
                    $this->labelled($stopByLine, $stopNames),
                    [['name' => 'Stop time', 'data' => $this->values($stopByLine, true)]],
                    ['span' => 2, 'valueFmt' => 'hrs', 'subtitle' => $this->rangeLabel(), 'height' => 300]),
            ],
        ];
    }

    protected function servicesSection(): array
    {
        $jobs = (int) $this->jobs()->count();
        $overTime = $this->jobSeries();

        $topLine = $this->topGroups('m.line_id', 'jobs', 1)->first();
        $topProject = $this->topGroups('m.project_id', 'jobs', 1)->first();

        $byProject = $this->topGroups('m.project_id', 'jobs', 10);
        $projectNames = MachineProject::whereIn('id', $byProject->pluck('k'))->pluck('name', 'id')->all();

        $bySub = $this->topGroups('m.subproject_id', 'jobs', 10);
        $subNames = MachineProject::whereIn('id', $bySub->pluck('k'))->pluck('name', 'id')->all();

        // Service type was never captured by the legacy screen, so all but a
        // handful of rows are honestly "Unclassified" rather than guessed at.
        $byType = $this->jobs()
            ->leftJoin('core.service_types as t', 't.id', '=', 'm.service_type_id')
            ->selectRaw("COALESCE(t.name, 'Unclassified') as name, COUNT(*) as n")
            ->groupBy('name')->orderByDesc('n')->get();

        $days = max(1, $this->rangeDays());

        return [
            'tiles' => [
                $this->tile('Service Jobs', $this->num($jobs), $this->rangeLabel(), 'brand'),
                $this->tile('Jobs per Day', number_format($jobs / $days, 1), 'average'),
                $this->tile('Busiest Machine', $topLine
                    ? (MachineLine::where('id', $topLine->k)->value('name') ?? '—') : '—',
                    $topLine ? $this->num((float) $topLine->v) . ' jobs' : null),
                $this->tile('Busiest Project', $topProject
                    ? (MachineProject::where('id', $topProject->k)->value('name') ?? '—') : '—',
                    $topProject ? $this->num((float) $topProject->v) . ' jobs' : null),
            ],
            'charts' => [
                $this->chartSpec('sv-time', 'bar', 'Jobs Logged', $overTime['labels'],
                    [['name' => 'Jobs', 'data' => $overTime['data']]],
                    ['span' => 2, 'subtitle' => $this->rangeLabel(), 'height' => 260]),
                $this->chartSpec('sv-project', 'hbar', 'Top Projects by Jobs',
                    $this->labelled($byProject, $projectNames),
                    [['name' => 'Jobs', 'data' => $this->values($byProject)]],
                    ['height' => 300]),
                $this->chartSpec('sv-sub', 'hbar', 'Top Sub-projects by Jobs',
                    $this->labelled($bySub, $subNames),
                    [['name' => 'Jobs', 'data' => $this->values($bySub)]],
                    ['subtitle' => 'Only jobs logged against a sub-project', 'height' => 300]),
                $this->chartSpec('sv-type', 'donut', 'Jobs by Service Type',
                    $byType->pluck('name')->all(),
                    [['name' => 'Jobs', 'data' => $byType->pluck('n')->map(fn ($v) => (float) $v)->all()]],
                    ['subtitle' => 'History predates the field, so most rows are unclassified']),
            ],
        ];
    }

    protected function downtimeSection(): array
    {
        $jobs = (int) $this->jobs()->count();
        $minutes = $this->stopMinutes();

        $longest = (int) ($this->jobs()
            ->selectRaw(ServiceDuration::ROW_MINUTES_SQL . ' as v')
            ->orderByDesc('v')->value('v') ?? 0);

        $overTime = $this->jobSeries(true);

        $byLine = $this->topGroups('m.line_id', 'minutes', 10);
        $lineNames = MachineLine::whereIn('id', $byLine->pluck('k'))->pluck('name', 'id')->all();

        $byProject = $this->topGroups('m.project_id', 'minutes', 10);
        $projectNames = MachineProject::whereIn('id', $byProject->pluck('k'))->pluck('name', 'id')->all();

        $byDivision = $this->topGroups('m.division_id', 'minutes', 8);
        $divNames = Division::whereIn('id', $byDivision->pluck('k'))->pluck('name', 'id')->all();

        return [
            'tiles' => [
                $this->stopTile('Total Stop Time', $minutes, $this->rangeLabel(), 'neg'),
                $this->tile('Days Lost', number_format($minutes / 1440, 1), 'machine-days'),
                $this->stopTile('Average per Job', $jobs ? (int) round($minutes / $jobs) : 0),
                $this->stopTile('Longest Single Job', $longest),
            ],
            'charts' => [
                $this->chartSpec('dt-time', 'line', 'Stop Time', $overTime['labels'],
                    [['name' => 'Stop time', 'data' => $overTime['data']]],
                    ['span' => 2, 'valueFmt' => 'hrs', 'subtitle' => $this->rangeLabel(), 'height' => 260]),
                $this->chartSpec('dt-line', 'bar', 'Stop Time by Machine',
                    $this->labelled($byLine, $lineNames),
                    [['name' => 'Stop time', 'data' => $this->values($byLine, true)]],
                    ['valueFmt' => 'hrs']),
                $this->chartSpec('dt-div', 'donut', 'Stop Time by Division',
                    $this->labelled($byDivision, $divNames, 'Unassigned'),
                    [['name' => 'Stop time', 'data' => $this->values($byDivision, true)]],
                    ['valueFmt' => 'hrs']),
                $this->chartSpec('dt-project', 'hbar', 'Top Projects by Stop Time',
                    $this->labelled($byProject, $projectNames),
                    [['name' => 'Stop time', 'data' => $this->values($byProject, true)]],
                    ['span' => 2, 'valueFmt' => 'hrs', 'height' => 300]),
            ],
        ];
    }

    protected function workforceSection(): array
    {
        $jobs = (int) $this->jobs()->count();
        $active = (int) $this->jobs()->distinct()->count('m.staff_id');

        $byDivision = $this->topGroups('m.division_id', 'jobs', 8);
        $divNames = Division::whereIn('id', $byDivision->pluck('k'))->pluck('name', 'id')->all();

        $byStaff = $this->topGroups('m.staff_id', 'jobs', 10);
        $staffNames = Staff::whereIn('id', $byStaff->pluck('k'))->pluck('name', 'id')->all();

        $staffMinutes = $this->topGroups('m.staff_id', 'minutes', 10);
        $staffMinNames = Staff::whereIn('id', $staffMinutes->pluck('k'))->pluck('name', 'id')->all();

        // Head-count per division, from the staff register rather than the jobs.
        $roster = Staff::query()->selectRaw('division_id as k, COUNT(*) as v')
            ->groupBy('division_id')->orderByDesc('v')->get();
        $rosterNames = Division::whereIn('id', $roster->pluck('k')->filter())->pluck('name', 'id')->all();

        return [
            'tiles' => [
                $this->tile('Staff on Register', $this->num((int) Staff::count())),
                $this->tile('Divisions', $this->num((int) Division::count())),
                $this->tile('Staff on Jobs', $this->num($active), $this->rangeLabel(), 'brand'),
                $this->tile('Jobs per Person', $active ? number_format($jobs / $active, 1) : '—', 'average'),
            ],
            'charts' => [
                $this->chartSpec('wf-div', 'donut', 'Jobs by Division',
                    $this->labelled($byDivision, $divNames, 'Unassigned'),
                    [['name' => 'Jobs', 'data' => $this->values($byDivision)]],
                    ['subtitle' => $this->rangeLabel()]),
                $this->chartSpec('wf-roster', 'bar', 'Head Count by Division',
                    $this->labelled($roster, $rosterNames, 'No division'),
                    [['name' => 'Staff', 'data' => $this->values($roster)]],
                    ['subtitle' => 'Current register']),
                $this->chartSpec('wf-staff', 'hbar', 'Most Jobs Logged',
                    $this->labelled($byStaff, $staffNames),
                    [['name' => 'Jobs', 'data' => $this->values($byStaff)]],
                    ['height' => 300]),
                $this->chartSpec('wf-staff-time', 'hbar', 'Most Stop Time Attended',
                    $this->labelled($staffMinutes, $staffMinNames),
                    [['name' => 'Stop time', 'data' => $this->values($staffMinutes, true)]],
                    ['valueFmt' => 'hrs', 'height' => 300]),
            ],
        ];
    }

    /** Days in the selected range — for the per-day average. */
    private function rangeDays(): int
    {
        $start = $this->rangeStart();

        if ($start) {
            return (int) $start->diffInDays($this->rangeEnd()) + 1;
        }

        // All time: measure from the first real job.
        $first = $this->db()->table('factory_machine_maintenance')
            ->whereBetween('date', [self::MIN_DATE, self::MAX_DATE])->min('date');

        return $first
            ? (int) Carbon::parse(str_replace('/', '-', $first))->diffInDays(now()) + 1
            : 1;
    }
}
