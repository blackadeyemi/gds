<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bpl\Support\StockLocations;

/**
 * Shared base for the BPL Jumbo Rolls reports.
 *
 * Sits on the report framework every BIL report uses — filters, view switching,
 * search, sort, paging, footer totals, drill-down, print and export — and points
 * it at the `bpl` schema, the BPL print/download routes and the Belpapyrus logo.
 * (The framework lives under Modules\Bil only because Raw Materials was the
 * first module to need it; BPL already borrows its blade partials the same way.)
 *
 * ## One report per movement, not one per stream
 *
 * The legacy had these twice over — `report_bpl_store_exit` for hardrolls and
 * `report_softroll_store_exit` for softrolls, the same screen against a
 * different table. A roll is a roll: gds unions the two and puts a **Type**
 * filter on top, exactly as the Factory Exit and Warehouse Entry SCREENS
 * already do (one screen, the barcode decides the stream). The default view
 * shows both, so "what left PM3 Store today" is one answer rather than two.
 *
 * ## Read-only
 *
 * The screens that write these rows own their corrections — a mis-scan is
 * fixed by re-scanning, and a delete has to reverse stock, which a report
 * cannot do. So no edit, no delete, anywhere in this group.
 */
abstract class JumboRollReport extends RawMaterialReport
{
    /* ---------------- Framework wiring ---------------- */

    protected function connection(): string
    {
        return 'bpl';
    }

    /** Also the Branding hint: anything starting `bpl` prints the BPL logo. */
    protected function exportPrefix(): string
    {
        return 'bpl-jr-';
    }

    protected function printRouteName(): string
    {
        return 'bpl.jumbo-rolls.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bpl.jumbo-rolls.reports.download';
    }

    protected function reportPageKey(): string
    {
        return 'bpl.jumbo_rolls.reports.' . str_replace('-', '_', $this->printKey());
    }

    public function readOnly(): bool
    {
        return true;
    }

    protected function db()
    {
        return DB::connection('bpl');
    }

    /* ---------------- Filter options, shared ---------------- */

    /** @var array<string,array>|null */
    protected ?array $sharedOptions = null;

    protected function shared(string $key): array
    {
        $this->sharedOptions ??= [
            'streams' => ['hardroll' => 'Hardroll', 'softroll' => 'Softroll'],
            'grades' => $this->db()->table('bpl_grades')->whereNull('deleted_at')
                ->whereNotNull('type')->where('type', '<>', '')
                ->orderBy('type')->pluck('type', 'type')->all(),
            'stores' => StockLocations::options(StockLocations::STORE),
            'floors' => StockLocations::options(StockLocations::FLOOR),
            'places' => StockLocations::options(),
        ];

        return $this->sharedOptions[$key] ?? [];
    }

    /**
     * Paper machines, as PRODUCTION records them.
     *
     * The two streams spell the same machine differently, because the two
     * screens were written years apart: a hardroll stores the name ('PM2',
     * 'PM3'), a softroll stores the machine NUMBER (2, 3) — the digit in its
     * own M2…/M3… roll number. The filter offers the names, and the softroll
     * leg converts; see softrollMachineExpr() and softrollMachineValue() below.
     *
     * The numbers look like `bpl_stock_locations` ids and are NOT: location 2
     * is PM3 and location 3 is PM2 Store, so resolving them that way names the
     * wrong machine — and a store at that.
     */
    protected function machines(): array
    {
        return $this->db()->table('bpl_production')
            ->whereNotNull('papermachine')->where('papermachine', '<>', '')
            ->select('papermachine')->distinct()->orderBy('papermachine')
            ->pluck('papermachine', 'papermachine')->all();
    }

    /** A softroll's machine as SQL: 2 => 'PM2'. */
    protected function softrollMachineExpr(string $column = '`prod`.`papermachine`'): string
    {
        return "CONCAT('PM', " . $column . ')';
    }

    /** The reverse, for the filter: 'PM2' => 2, anything else => null. */
    protected function softrollMachineValue(string $name): ?int
    {
        return preg_match('/^PM(\d+)$/i', trim($name), $m) ? (int) $m[1] : null;
    }

    /* ---------------- Query helpers ---------------- */

    /** A `location_id` resolved to a warehouse / floor name. */
    protected function placeName(string $expr): string
    {
        return StockLocations::nameExpr($expr);
    }

    /**
     * Apply a filter whose option value 'none' means IS NULL.
     *
     * Every BPL movement table uses NULL for the most interesting state of the
     * lot — a roll still in the store, a roll not yet loaded — and
     * `applyFilters()` builds a plain equality, which can never say that.
     */
    protected function applyNullable($q, string $filter, string $column)
    {
        $value = $this->filters[$filter] ?? '';

        if ($value === '' || $value === 'all') {
            return $q;
        }

        return $value === 'none' ? $q->whereNull($column) : $q->where($column, $value);
    }

    /* ---------------- Cells ---------------- */

    protected function streamCell(): callable
    {
        return fn ($r) => '<span class="badge ' . (($r->stream ?? '') === 'softroll' ? 'badge-muted' : 'badge-success') . '">'
            . e((string) ($r->stream ?? '')) . '</span>';
    }

    /**
     * A movement row's onward state, from the `status` the NEXT screen stamps
     * on it. NULL is not "unknown" here — it is "still where this row put it".
     *
     * @param  array<string,array{0:string,1:string}>  $map  value => [label, badge class]
     */
    protected function statusCell(array $map, string $nullLabel, string $nullClass = 'badge-success'): callable
    {
        return function ($r) use ($map, $nullLabel, $nullClass) {
            $status = $r->status ?? null;

            if ($status === null || $status === '') {
                return '<span class="badge ' . $nullClass . '">' . e($nullLabel) . '</span>';
            }

            [$label, $class] = $map[$status] ?? [$status, 'badge'];

            return '<span class="badge ' . $class . '">' . e($label) . '</span>';
        };
    }

    /** kg, two decimals, blank for nothing. */
    protected function kgCell(string $field): callable
    {
        return fn ($r) => ($v = data_get($r, $field)) === null || $v === ''
            ? '' : number_format((float) $v, 2);
    }
}
