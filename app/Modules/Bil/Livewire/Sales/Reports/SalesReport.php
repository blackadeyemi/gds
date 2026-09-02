<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Core\Models\Warehouse;

/**
 * Shared base for the five Sales reports — Orders, Loading, Delivery, Returns
 * and Waybill. Rebuild of the legacy report_sales.php, whose one screen carried
 * all of them behind a switcher (Order / Loading / Delivery / Return) with a
 * second dropdown for Product / Customer / Daily.
 *
 * Each of those is a page here instead of a tab, because they are gated
 * separately — a depot clerk who may see what was loaded has no business with
 * transport costs — and the switcher's "views" become the report framework's
 * own view list, which brings paging, sort, search, print and export with it.
 *
 * SUMMARY (BY CUSTOMER) IS THE DEFAULT VIEW ON ALL FIVE, and each of its rows
 * opens the records behind it in a modal — the same shape as Machines →
 * Reports → Services, whose summary-by-project rows expand into their jobs.
 * The legacy screen did group by customer, but rendered every line underneath
 * every heading, so a week of sales was thousands of rows to scroll.
 *
 * All five sit on the same spine:
 *
 *     sales_loading / sales_delivery / sales_return / sales_waybill
 *       -> sales_order_details (the ordered line)
 *         -> sales_order (the order: date, depot, customer)
 *
 * Two things about that spine used to make it unusable for a report, and both
 * are now fixed in the schema rather than worked around here:
 *
 *   - `sales_order_details.orderid` was utf8mb3 against a latin1 `sales_order`,
 *     so the hop back to the order could not use an index at all — 20s for
 *     fifty rows (2026_09_02_140000).
 *   - `loadnumber` is a per-day sequence, so delivery <-> loading has to match
 *     on (date, number) and there was no index on the pair — 39s for a year of
 *     the waybill report (2026_09_02_150000).
 *
 * Reports are READ-OUTS: no row edit or delete. Every one of these rows is a
 * step in the sales chain with a screen that owns it — a wrong loading is
 * corrected on Loading, where the stock and the delivery it feeds are kept
 * straight — so a report that quietly deleted one would leave the rest dangling.
 */
abstract class SalesReport extends RawMaterialReport
{
    protected ?array $salesOptCache = null;

    /** bil.sales.reports.{slug} — see config/pages.php. */
    protected function reportPageKey(): string
    {
        return 'bil.sales.reports.' . str_replace('-', '_', $this->printKey());
    }

    protected function printRouteName(): string
    {
        return 'bil.sales.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.sales.reports.download';
    }

    /** See the class note: corrections belong on the screen that owns the row. */
    public function readOnly(): bool
    {
        return true;
    }

    /* ---------------- Shared option lists ---------------- */

    /**
     * The dropdown contents every sales report filters by.
     *
     * Depots come from the core `warehouses` table by their `legacy_sales_code`,
     * which is what `sales_order.warehousecode` still holds; closed ones stay in
     * the list because a report you cannot filter to is one that hides its own
     * history (Kano has orders, and has been shut for years).
     */
    protected function salesOptions(): array
    {
        return $this->salesOptCache ??= [
            'warehouses' => Warehouse::query()->whereNotNull('legacy_sales_code')
                ->orderBy('sort_order')->pluck('name', 'legacy_sales_code')->all(),
            'customers' => DB::connection('bil')->table('sales_customers')
                ->orderBy('customername')->pluck('customername', 'id')->all(),
            'products' => DB::connection('bil')->table('products')
                ->where('is_deleted', 0)->orderBy('productname')
                ->pluck('productname', 'productid')->all(),
            'transporters' => DB::connection('bil')->table('sales_transporters')
                ->orderBy('transportername')->pluck('transportername', 'id')->all(),
            'cagerooms' => DB::connection('core')->table('warehouse_gates')
                ->where('direction', 'out')->where('legacy_name', 'like', 'CGR%')
                ->whereNull('deleted_at')->orderBy('sort_order')
                ->pluck('name', 'legacy_name')->all(),
        ];
    }

    /**
     * The three filters every one of these reports carries, in the order the
     * legacy search modal had them: depot, customer, product.
     */
    protected function commonFilterDefs(): array
    {
        $o = $this->salesOptions();

        return [
            'warehouse' => ['label' => 'Depot', 'options' => $o['warehouses']],
            'customer' => ['label' => 'Customer', 'options' => $o['customers']],
            'product' => ['label' => 'Product', 'options' => $o['products']],
        ];
    }

    /**
     * Sold vs free-of-charge. `foc` is a flag on the ORDER LINE, and the same
     * product is routinely ordered twice — once sold, once free — which is why
     * it can never be summed away: the two lines are different money.
     */
    protected function focFilterDef(): array
    {
        return ['label' => 'Type', 'options' => ['0' => 'Sold', '1' => 'Free of charge']];
    }

    /** Apply depot / customer / product / type to a query on the full spine. */
    protected function applyCommonFilters($q)
    {
        $f = $this->filters;

        return $q
            ->when(($f['warehouse'] ?? '') !== '', fn ($q) => $q->where('so.warehousecode', $f['warehouse']))
            ->when(($f['customer'] ?? '') !== '', fn ($q) => $q->where('so.customerid', $f['customer']))
            ->when(($f['product'] ?? '') !== '', fn ($q) => $q->where('sod.productid', $f['product']))
            ->when(($f['foc'] ?? '') !== '', fn ($q) => $q->where('sod.foc', $f['foc']));
    }

    /* ---------------- Cell rendering ---------------- */

    /** Bundle counts, thousands-separated; nothing at all reads as a dash. */
    protected function qty($value): string
    {
        $n = (int) $value;

        return $n === 0 ? '—' : number_format($n);
    }

    /** Bundle count that stays a number even at zero (totals, balances). */
    protected function num($value): string
    {
        return number_format((int) $value);
    }

    protected function money($value): string
    {
        return number_format((float) $value, 2);
    }

    /**
     * A currency figure in a table. Coloured and tabular-figured so a column of
     * money reads as one — `money()` stays plain for the places that put a
     * figure inside a sentence.
     */
    protected function moneyCell($value): string
    {
        return '<span class="text-money">' . $this->money($value) . '</span>';
    }

    /**
     * Sold or free of charge, spelled FOC and coloured red.
     *
     * FOC is the word the office uses, and it is the one thing on a line that
     * changes what the line MEANS: the same product on the same order, ordered
     * twice, is money once and a giveaway the other time. It has to be findable
     * by eye down a long table, which a neutral badge was not.
     *
     * Exports and printouts strip the markup and keep the word, so a
     * spreadsheet still says FOC rather than 1.
     */
    protected function focCell($value): string
    {
        return (int) $value === 1
            ? '<strong class="text-danger">FOC</strong>'
            : '<span class="text-muted">Sold</span>';
    }

    /* ---------------- Summary drill-down, by customer ---------------- */

    /**
     * Every one of these reports leads with Summary (by customer), and every one
     * of those rows expands into its own records.
     *
     * Keyed by id AND name: the id is what the drill-down query filters on (two
     * customers do share a name in this data), and the name rides along so the
     * modal can head itself without a second lookup.
     */
    public function expandableBy(): ?array
    {
        return ['customerid', 'customername'];
    }

    protected function detailCustomerId(string $key): ?int
    {
        $id = $this->detailKeyParts($key)[0] ?? '';

        return $id === '' ? null : (int) $id;
    }

    /**
     * Narrow a query to the customer a summary row stands for.
     *
     * A NULL id is a real group, not a missing one: 71,972 loadings point at an
     * order line that no longer exists (almost all of them 2017-18 rows written
     * before `sod_id` was populated at all), and they carry 6.7 million bundles
     * that did leave the warehouse. They are reported under UNMATCHED rather
     * than dropped, and their row opens like any other.
     */
    protected function whereDetailCustomer($q, string $key)
    {
        $id = $this->detailCustomerId($key);

        return $id === null
            ? $q->whereNull('so.customerid')
            : $q->where('so.customerid', $id);
    }

    /** Column label for a summary row whose order line has gone. */
    protected const UNMATCHED = '— no order line —';

    protected function customerCell($row): string
    {
        return e($row->customername ?: self::UNMATCHED);
    }

    public function detailTitle(string $key): string
    {
        return ($this->detailKeyParts($key)[1] ?? '') ?: self::UNMATCHED;
    }

    public function detailContext(string $key): array
    {
        return [['Customer', $this->detailTitle($key)]];
    }
}
