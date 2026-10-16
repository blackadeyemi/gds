<?php

namespace Modules\Bpl\Livewire\Sales\Reports;

use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bpl\Livewire\Concerns\BplMoneyReport;

/**
 * Shared base for the BPL Sales reports.
 *
 * Sits on the report framework every BIL report uses — filters, view
 * switching, search, sort, paging, footer totals, drill-down, print and export
 * — pointed at the `bpl` schema, the BPL print/download routes and the
 * Belpapyrus logo. (The framework lives under Modules\Bil only because Raw
 * Materials needed it first; the Jumbo Rolls reports sit on it the same way.)
 *
 * The money conventions — never totalling across currencies, the entity-encoded
 * symbols, how an order is named — are shared with the Finance reports through
 * Modules\Bpl\Livewire\Concerns\BplMoneyReport, because Sales and Finance are
 * the same business seen from two ends and must agree.
 *
 * ## Read-only
 *
 * The Orders, Proforma Invoices and Packing Lists screens own their
 * corrections — deleting a proforma has to consider the packing list built on
 * it, which a report cannot — so no edit, no delete anywhere in this group.
 */
abstract class SalesReport extends RawMaterialReport
{
    use BplMoneyReport;

    /** Also the Branding hint: anything starting `bpl` prints the BPL logo. */
    protected function exportPrefix(): string
    {
        return 'bpl-sales-';
    }

    protected function printRouteName(): string
    {
        return 'bpl.sales.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bpl.sales.reports.download';
    }

    protected function reportPageKey(): string
    {
        return 'bpl.sales.reports.' . str_replace('-', '_', $this->printKey());
    }

    public function readOnly(): bool
    {
        return true;
    }
}
