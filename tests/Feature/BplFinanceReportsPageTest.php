<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Bpl\Livewire\Finance\Reports\Accounts;
use Modules\Bpl\Livewire\Finance\Reports\ExportPayments;
use Modules\Bpl\Livewire\Finance\Reports\InvoicePayments;
use Modules\Bpl\Livewire\Finance\Reports\Receivables;
use Modules\Bpl\Livewire\Finance\Reports\WaybillPayments;
use Modules\Bpl\Livewire\Finance\Statistics;
use Modules\Bpl\Livewire\Sales\Reports\Shipments;
use Modules\Bpl\Support\Receivables as Rule;
use Modules\Bpl\Support\SalesFigures;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * BPL → Finance → Reports + Statistics.
 *
 * Read-only pages over the LIVE bpl database: nothing here writes, so there is
 * nothing to clean up. The assertions are about agreement — that the ageing
 * rule is the legacy's to the day, that the buckets add up, that Finance and
 * Sales say the same thing about the same shipment, and that a haulier's
 * balance comes from the receipts rather than the column that was wrong.
 */
class BplFinanceReportsPageTest extends TestCase
{
    /** All time: BPL raises a few hundred shipments a year, so this is cheap. */
    private const FROM = '';

    private const TO = '';

    /** slug => [component, route] */
    private const REPORTS = [
        'receivables' => [Receivables::class, 'bpl.finance.reports.receivables'],
        'invoice-payments' => [InvoicePayments::class, 'bpl.finance.reports.invoice-payments'],
        'waybill-payments' => [WaybillPayments::class, 'bpl.finance.reports.waybill-payments'],
        'export-payments' => [ExportPayments::class, 'bpl.finance.reports.export-payments'],
        'accounts' => [Accounts::class, 'bpl.finance.reports.accounts'],
    ];

    private function admin(): User
    {
        $u = User::whereHas('roles', fn ($q) => $q->where('legacy_level', 1))->first();
        $this->assertNotNull($u);

        return $u;
    }

    private function report(string $class, array $filters = [])
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test($class)->set('dateFrom', self::FROM)->set('dateTo', self::TO);

        foreach (array_keys($c->instance()->filterDefs()) as $name) {
            $c->set('filters.' . $name, $filters[$name] ?? '');
        }

        return $c;
    }

    /* ---------------- The reports ---------------- */

    public function test_every_report_page_opens(): void
    {
        foreach (self::REPORTS as [$class, $route]) {
            $this->actingAs($this->admin())->get(route($route))
                ->assertOk()
                ->assertSee((new $class())->title());
        }
    }

    public function test_every_view_of_every_report_renders(): void
    {
        foreach (self::REPORTS as $slug => [$class]) {
            $c = $this->report($class);

            foreach (array_keys($c->instance()->views()) as $view) {
                $c->set('view', $view)->assertOk();
                $this->assertNotNull($c->viewData('rows'), $slug . ' / ' . $view);
            }
        }
    }

    /* ---------------- The ageing rule ---------------- */

    /**
     * The due date is the legacy's arithmetic to the day: the credit period
     * plus one, counted from the bill of lading for a vessel and from the
     * packing list for a truck. Re-derived in PHP for every shipment and
     * compared with what the SQL produced.
     */
    public function test_the_due_date_matches_the_legacy_rule(): void
    {
        $rows = DB::connection('bpl')->table('bpl_packing_list as pl')
            ->joinSub(SalesFigures::shipments(), 'f', 'f.packing_id', '=', 'pl.id')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('bpl_bill_of_lading as bl', 'bl.packing_id', '=', 'pl.id')
            ->selectRaw(
                '`pl`.`id`, `pl`.`date` as `packed`, `pf`.`shipment`,'
                . " COALESCE(`bl`.`issued_date`, '') as `issued`,"
                . ' COALESCE(`pt`.`days`, 0) as `days`,'
                . ' ' . Rule::DUE_DATE . ' as `due_date`,'
                . ' ' . Rule::DAYS_OVERDUE . ' as `days_overdue`'
            )->get();

        $this->assertGreaterThan(100, $rows->count());

        $checked = 0;
        $noClock = 0;

        foreach ($rows as $row) {
            $start = $row->shipment === 'Truck'
                ? (string) $row->packed
                : (string) $row->issued;

            if ($start === '') {
                // A vessel shipment with no bill of lading: the clock has not
                // started, so there is no due date and it cannot be overdue.
                $this->assertNull($row->due_date, 'shipment ' . $row->id . ' should have no due date');
                $this->assertNull($row->days_overdue);
                $noClock++;

                continue;
            }

            $expected = Carbon::createFromFormat('Y/m/d', $start)
                ->addDays((int) $row->days + 1)->format('Y-m-d');

            $this->assertSame($expected, (string) $row->due_date, 'due date of shipment ' . $row->id);
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
        // The "awaiting B/L" state is real in this data; if it ever empties,
        // the view and the bucket built for it have nothing to show.
        $this->assertGreaterThan(0, $noClock);
    }

    /** The buckets are exclusive and account for every outstanding shipment. */
    public function test_the_ageing_buckets_account_for_everything_outstanding(): void
    {
        $summary = $this->report(Receivables::class)->set('view', 'by_age')
            ->set('perPage', 250)->viewData('rows');

        $outstanding = $this->report(Receivables::class)->viewData('rows')->total();

        $this->assertSame($outstanding, (int) collect($summary->items())->sum('shipments'));

        // Every bucket is a state the rule can produce, and never "paid" —
        // this view only lists what is still owed.
        foreach ($summary->items() as $row) {
            $this->assertArrayHasKey($row->state, Rule::STATES);
            $this->assertNotSame('paid', $row->state);
        }
    }

    /** A shipment with no bill of lading is never reported as overdue. */
    public function test_a_shipment_awaiting_a_bill_of_lading_is_not_overdue(): void
    {
        $awaiting = $this->report(Receivables::class)->set('view', 'awaiting_bl')
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $awaiting->total());

        foreach ($awaiting->items() as $row) {
            $this->assertNull($row->due_date, $row->raw_number);
            $this->assertNull($row->days_overdue, $row->raw_number);
            $this->assertSame('awaiting_bl', $row->state);
            $this->assertFalse(Rule::isOverdue($row->state));
        }

        // …and none of them appears in the overdue view.
        $overdue = $this->report(Receivables::class)->set('view', 'overdue')
            ->set('perPage', 250)->viewData('rows');

        $awaitingIds = collect($awaiting->items())->pluck('id')->all();

        foreach ($overdue->items() as $row) {
            $this->assertNotContains($row->id, $awaitingIds);
            $this->assertGreaterThan(0, (int) $row->days_overdue);
        }
    }

    /**
     * Finance and Sales describe the same shipments from opposite ends, so
     * they must agree about what is outstanding. They compute it through the
     * same SalesFigures arithmetic; this is the check that they still do.
     */
    public function test_receivables_agree_with_the_sales_shipments_report(): void
    {
        Livewire::actingAs($this->admin());

        $finance = $this->report(Receivables::class)->viewData('rows')->total();

        $sales = Livewire::test(Shipments::class)
            ->set('dateFrom', '')->set('dateTo', '')
            ->set('view', 'outstanding')->viewData('rows')->total();

        $this->assertSame($sales, $finance);
    }

    /** More received than invoiced, split by whether the invoice is the problem. */
    public function test_overpaid_separates_a_short_invoice_from_a_large_payment(): void
    {
        $rows = $this->report(Receivables::class)->set('view', 'overpaid')
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        $understated = 0;

        foreach ($rows->items() as $row) {
            $this->assertGreaterThan(0, (float) $row->excess, $row->raw_number);
            $this->assertGreaterThan((float) $row->invoice_total, (float) $row->paid);

            if ((float) $row->unpriced_weight > 0) {
                $understated++;
            }
        }

        // Most of these are a short invoice rather than an excess payment —
        // the goods shipped under a description no priced line matches. If
        // that ever stops being true the Why column has nothing to explain.
        $this->assertGreaterThan(0, $understated);
    }

    /* ---------------- Waybill payments ---------------- */

    /**
     * A haulier's balance comes from the RECEIPTS, not the stored column.
     * The legacy maintained `amount_paid` and `status` by hand and had them
     * wrong on every row.
     */
    public function test_waybill_balances_come_from_the_receipts(): void
    {
        $rows = $this->report(WaybillPayments::class)->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            $paid = (float) DB::connection('bpl')->table('bpl_waybill_payment_history')
                ->where('waybill_payment_id', $row->id)->sum('amount');

            $this->assertEqualsWithDelta($paid, (float) $row->paid, 0.01, 'waybill ' . $row->barcode);
            $this->assertEqualsWithDelta((float) $row->amount - $paid, (float) $row->balance, 0.01);
            $this->assertSame(
                (int) DB::connection('bpl')->table('bpl_waybill_payment_history')
                    ->where('waybill_payment_id', $row->id)->count(),
                (int) $row->receipts
            );
        }
    }

    /** The Receipts view totals the receipts, not the trips they belong to. */
    public function test_the_receipts_view_is_one_row_per_payment(): void
    {
        $receipts = $this->report(WaybillPayments::class)->set('view', 'receipts')
            ->set('perPage', 250)->viewData('rows');

        $this->assertSame(
            (int) DB::connection('bpl')->table('bpl_waybill_payment_history')->count(),
            $receipts->total()
        );

        // `agreed` is the trip's whole bill repeated on each of its receipts,
        // so it must not be in the footer totals.
        $totals = $this->report(WaybillPayments::class)->set('view', 'receipts')->viewData('totals');
        $this->assertArrayHasKey('amount', $totals);
        $this->assertArrayNotHasKey('agreed', $totals);
    }

    /** Haulage is naira only, so its amounts total without picking a currency. */
    public function test_haulage_totals_without_a_currency(): void
    {
        $totals = $this->report(WaybillPayments::class)->viewData('totals');

        $this->assertArrayHasKey('amount', $totals);
        $this->assertArrayHasKey('balance', $totals);
        $this->assertStringNotContainsString('pick a currency',
            $this->report(WaybillPayments::class)->instance()->subtitle());
    }

    /* ---------------- Currency discipline ---------------- */

    /** Everywhere else, money is only totalled within one currency. */
    public function test_amounts_are_only_totalled_within_one_currency(): void
    {
        foreach ([Receivables::class, InvoicePayments::class, ExportPayments::class, Accounts::class] as $class) {
            $mixed = $this->report($class);
            $this->assertStringContainsString('pick a currency', $mixed->instance()->subtitle(), $class);
        }

        $usd = (int) DB::connection('bpl')->table('currencies')->where('code', 'USD')->value('id');

        $one = $this->report(Receivables::class, ['currency' => (string) $usd]);
        $this->assertArrayHasKey('balance', $one->viewData('totals'));
        $this->assertStringNotContainsString('pick a currency', $one->instance()->subtitle());
    }

    /* ---------------- Drill-downs ---------------- */

    public function test_every_summary_drills_into_exactly_what_it_counted(): void
    {
        $checks = [
            Receivables::class => ['by_age' => 'shipments', 'by_customer' => 'shipments'],
            InvoicePayments::class => ['by_customer' => 'payments', 'by_currency' => 'payments',
                'by_bank' => 'payments', 'by_month' => 'payments'],
            WaybillPayments::class => ['by_transporter' => 'waybills', 'by_month' => 'receipts'],
            ExportPayments::class => ['by_bank' => 'shipments', 'by_terms' => 'shipments',
                'by_month' => 'shipments'],
            Accounts::class => ['by_bank' => 'accounts'],
        ];

        foreach ($checks as $class => $views) {
            foreach ($views as $view => $countField) {
                $c = $this->report($class)->set('view', $view)->set('perPage', 10);
                $report = $c->instance();

                $rows = collect($c->viewData('rows')->items())->take(5);
                $this->assertNotEmpty($rows, $class . ' / ' . $view . ' has no groups');

                foreach ($rows as $row) {
                    $key = $report->detailKeyFor($row);
                    $this->assertNotNull($key, $class . ' / ' . $view . ' has no drill-down key');

                    $this->assertSame(
                        (int) $row->{$countField},
                        $report->detailQuery($key)->count(),
                        $class . ' / ' . $view . ' — group ' . str_replace("\x1f", ' | ', $key)
                    );
                }
            }
        }
    }

    /* ---------------- Export payments ---------------- */

    /**
     * The legacy `bpl_export_payments` listing had no `split = '0'` condition
     * while its record count did, so it paged 409 rows against a stated total
     * of 360 — the 49 split parents, which are scaffolding rather than
     * shipments, were listed and counted twice over.
     */
    public function test_export_payments_lists_shipments_not_split_parents(): void
    {
        $listed = $this->report(ExportPayments::class)->viewData('rows')->total();

        $shipments = (int) DB::connection('bpl')->table('bpl_packing_list')
            ->where('split', '0')->count();

        $this->assertSame($shipments, $listed);
        $this->assertNotSame(
            (int) DB::connection('bpl')->table('bpl_packing_list')->count(),
            $listed,
            'there are no split parents in this data, so the check proves nothing'
        );
    }

    /**
     * Every shipment, settled or not — that is what separates this from the
     * Receivables report, which lists only what is owed.
     */
    public function test_export_payments_lists_settled_shipments_too(): void
    {
        $all = $this->report(ExportPayments::class)->viewData('rows')->total();
        $open = $this->report(ExportPayments::class)->set('view', 'open')->viewData('rows')->total();
        $owed = $this->report(Receivables::class)->viewData('rows')->total();

        $this->assertGreaterThan($open, $all);
        // The unsettled view is the Receivables report's list, by another name.
        $this->assertSame($owed, $open);
    }

    /**
     * The missing-NXP view asks about SEA exports only. An inland truck
     * routinely has none, and listing those would bury the few vessel
     * shipments that should have one.
     */
    public function test_the_missing_nxp_view_asks_only_about_vessels(): void
    {
        $rows = $this->report(ExportPayments::class)->set('view', 'no_nxp')
            ->set('perPage', 250)->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows->items() as $row) {
            $this->assertSame('', (string) $row->nxp, $row->raw_number);
            $this->assertNotSame('Truck', (string) $row->shipment, $row->raw_number);
        }

        // Trucks without an NXP exist and are deliberately left out of it.
        $trucksWithout = (int) DB::connection('bpl')->table('bpl_packing_list as pl')
            ->leftJoin('bpl_proforma as pf', function ($j) {
                $j->on('pf.order_id', '=', 'pl.order_id')->whereNull('pf.deleted_at');
            })
            ->where('pl.split', '0')->where('pf.shipment', 'Truck')
            ->whereRaw("COALESCE(`pf`.`nxp`, '') = ''")
            ->count();

        // They exist, and none of them reached the view.
        $this->assertGreaterThan(0, $trucksWithout);
    }

    /** The NXP filter and the column agree. */
    public function test_the_nxp_filter_splits_the_register(): void
    {
        $all = $this->report(ExportPayments::class)->viewData('rows')->total();
        $with = $this->report(ExportPayments::class, ['nxp' => 'yes'])->viewData('rows')->total();
        $without = $this->report(ExportPayments::class, ['nxp' => 'no'])->viewData('rows')->total();

        $this->assertSame($all, $with + $without);
        $this->assertGreaterThan(0, $without);
    }

    /**
     * Export Payments and Receivables share the due-date rule, so a shipment
     * on both must read the same on both.
     */
    public function test_export_payments_agrees_with_receivables_on_a_shipment(): void
    {
        $open = collect($this->report(ExportPayments::class)->set('view', 'open')
            ->set('perPage', 250)->viewData('rows')->items())->keyBy('id');

        $owed = collect($this->report(Receivables::class)->set('perPage', 250)
            ->viewData('rows')->items())->keyBy('id');

        $this->assertNotEmpty($open);

        foreach ($open as $id => $row) {
            $this->assertArrayHasKey($id, $owed, 'shipment ' . $id . ' is open on one report only');
            $this->assertSame((string) $row->due_date, (string) $owed[$id]->due_date);
            $this->assertSame($row->state, $owed[$id]->state);
            $this->assertEqualsWithDelta((float) $row->balance, (float) $owed[$id]->balance, 0.01);
        }
    }

    /* ---------------- The repaired dates ---------------- */

    /**
     * Two payments held `d/m/y` where every other row holds `Y/m/d`, which
     * sorted them before every payment ever made and excluded them from their
     * own month. Repaired in 2026_09_25_160000.
     */
    public function test_no_invoice_payment_carries_a_malformed_date(): void
    {
        $bad = DB::connection('bpl')->table('bpl_invoice_payments')
            ->whereRaw("`date` NOT REGEXP '^(19|20)[0-9]{2}/[0-9]{2}/[0-9]{2}$'")
            ->count();

        $this->assertSame(0, $bad);

        // …and the two repaired rows are now inside the month they belong to.
        $december = $this->report(InvoicePayments::class)
            ->set('dateFrom', '2021-12-01')->set('dateTo', '2021-12-31')
            ->set('perPage', 250)->viewData('rows');

        $ids = collect($december->items())->pluck('id')->all();
        $this->assertContains(136, $ids);
        $this->assertContains(137, $ids);
    }

    /* ---------------- Print and export ---------------- */

    public function test_print_and_export_work_for_every_report(): void
    {
        foreach (array_keys(self::REPORTS) as $slug) {
            $this->actingAs($this->admin())
                ->get(route('bpl.finance.reports.print', ['report' => $slug]))
                ->assertOk();

            foreach (['xlsx', 'csv'] as $format) {
                $this->actingAs($this->admin())->get(
                    route('bpl.finance.reports.download', ['report' => $slug, 'format' => $format])
                )->assertOk();
            }
        }

        $this->actingAs($this->admin())
            ->get(route('bpl.finance.reports.print', ['report' => 'nonsense']))
            ->assertNotFound();
    }

    /* ---------------- Statistics ---------------- */

    public function test_the_statistics_page_opens(): void
    {
        $this->actingAs($this->admin())->get(route('bpl.finance.statistics'))
            ->assertOk()
            ->assertSee('BPL Finance Statistics');
    }

    public function test_every_statistics_section_builds(): void
    {
        Livewire::actingAs($this->admin());

        $c = Livewire::test(Statistics::class);

        foreach (array_keys($c->instance()->sections()) as $section) {
            $c->call('selectSection', $section)->assertOk();
            $this->assertNotEmpty($c->viewData('tiles'), $section . ' has no tiles');
            $this->assertNotEmpty($c->viewData('charts'), $section . ' has no charts');
        }

        foreach (array_keys($c->instance()->rangeOptions()) as $range) {
            $c->set('range', $range)->assertOk();
        }
    }

    /** A debt has no window: the receivables figures ignore the date range. */
    public function test_receivables_ignore_the_date_range(): void
    {
        Livewire::actingAs($this->admin());

        $owed = function (string $range) {
            $tiles = Livewire::test(Statistics::class)->set('range', $range)
                ->call('selectSection', 'receivables')->viewData('tiles');

            return collect($tiles)->filter(fn ($t) => str_starts_with($t['label'], 'Owed'))
                ->mapWithKeys(fn ($t) => [$t['label'] => $t['value']])->all();
        };

        $this->assertNotEmpty($owed('all'));
        $this->assertSame($owed('all'), $owed('90d'));
    }

    /** The dashboard's outstanding count is the report's. */
    public function test_the_dashboard_agrees_with_the_receivables_report(): void
    {
        Livewire::actingAs($this->admin());

        $tiles = Livewire::test(Statistics::class)->call('selectSection', 'receivables')->viewData('tiles');
        $awaiting = collect($tiles)->firstWhere('label', 'Awaiting B/L')['value'];

        $report = $this->report(Receivables::class)->set('view', 'awaiting_bl')->viewData('rows')->total();

        $this->assertSame(number_format($report), $awaiting);
    }

    public function test_the_statistics_export_works(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            $this->actingAs($this->admin())->get(
                route('bpl.finance.statistics.export', ['format' => $format, 'section' => 'overview'])
            )->assertOk();
        }
    }
}
