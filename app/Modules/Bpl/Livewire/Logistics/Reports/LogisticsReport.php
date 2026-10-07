<?php

namespace Modules\Bpl\Livewire\Logistics\Reports;

use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bpl\Livewire\Concerns\BplMoneyReport;

/**
 * Shared base for the BPL Logistics reports.
 *
 * Same report framework as every other module, pointed at the `bpl` schema and
 * the Logistics print/download routes. It carries BplMoneyReport for the few
 * places a value appears — a bill of lading belongs to a shipment that has one
 * — so the cross-currency rule holds here as everywhere else.
 *
 * ## Read-only
 *
 * A waybill is raised by the Delivery screen, which takes the rolls off the
 * loading bay in the same transaction, and completed by the Weighbridge, which
 * checks the figure against those rolls. Editing either from a report would
 * leave the rolls behind, so this group never writes.
 */
abstract class LogisticsReport extends RawMaterialReport
{
    use BplMoneyReport;

    /** Also the Branding hint: anything starting `bpl` prints the BPL logo. */
    protected function exportPrefix(): string
    {
        return 'bpl-logistics-';
    }

    protected function printRouteName(): string
    {
        return 'bpl.logistics.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bpl.logistics.reports.download';
    }

    protected function reportPageKey(): string
    {
        return 'bpl.logistics.reports.' . str_replace('-', '_', $this->printKey());
    }

    public function readOnly(): bool
    {
        return true;
    }

    /** Kilograms shown as tonnes, the unit a truck load is discussed in. */
    protected function tonnesCell(string $field): callable
    {
        return fn ($r) => ($v = data_get($r, $field)) === null || $v === ''
            ? '' : number_format((float) $v / 1000, 3);
    }
}
