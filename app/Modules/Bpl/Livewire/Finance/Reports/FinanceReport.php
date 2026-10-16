<?php

namespace Modules\Bpl\Livewire\Finance\Reports;

use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bpl\Livewire\Concerns\BplMoneyReport;

/**
 * Shared base for the BPL Finance reports.
 *
 * Same report framework as every other module, pointed at the `bpl` schema and
 * the Finance print/download routes, and sharing its money conventions with
 * the Sales reports through BplMoneyReport — the two groups describe the same
 * shipments from opposite ends (what was sold, what was collected) and a
 * figure that differed between them would be a bug.
 *
 * ## Read-only
 *
 * Receipts are entered on the Invoice Payments and Waybill Payments screens,
 * which recalculate balances and statuses as they write. A report that could
 * edit a receipt would leave those derived figures behind, so this group never
 * writes.
 */
abstract class FinanceReport extends RawMaterialReport
{
    use BplMoneyReport;

    /** Also the Branding hint: anything starting `bpl` prints the BPL logo. */
    protected function exportPrefix(): string
    {
        return 'bpl-finance-';
    }

    protected function printRouteName(): string
    {
        return 'bpl.finance.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bpl.finance.reports.download';
    }

    protected function reportPageKey(): string
    {
        return 'bpl.finance.reports.' . str_replace('-', '_', $this->printKey());
    }

    public function readOnly(): bool
    {
        return true;
    }

    /* ---------------- The shipment spine ---------------- */

    /**
     * The invoice a shipment is owed, as SQL: the goods that actually shipped
     * plus freight for the containers that actually went. Expects `f` (the
     * SalesFigures subquery) and `pf` (the proforma) in scope.
     *
     * Identical to the Shipments report's, deliberately — see
     * Modules\Bpl\Support\SalesFigures, which both take it from.
     */
    protected const INVOICE_TOTAL = 'ROUND(`f`.`goods_value` + `f`.`containers` * COALESCE(`pf`.`freight_price`, 0), 2)';
}
