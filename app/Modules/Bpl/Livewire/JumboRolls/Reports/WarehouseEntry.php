<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Livewire\Attributes\Title;
use Modules\Bpl\Support\StockLocations;

/**
 * BPL → Jumbo Rolls → Reports → Warehouse Entry. Rolls received into a BPL
 * store, over `bpl_warehouse_entry` and `bpl_softroll_warehouse_entry`.
 *
 * Rebuilt from the legacy `report_bpl_store_entrance` and
 * `report_softroll_store_entrance` pages.
 *
 * ## The Status column is the stock position
 *
 * `status` here is the flag Warehouse Exit stamps when the roll is released:
 * NULL means the roll is STILL IN THE STORE. So filtering this report to "In
 * the store" over all time is the held position, roll by roll — the same
 * definition the Warehouse Stock page totals (see its positionQuery(), and the
 * Movement anomalies view there for the rows where this flag and the exit
 * table disagree).
 */
#[Title('BPL Warehouse Entry Report')]
class WarehouseEntry extends MovementReport
{
    public function title(): string
    {
        return 'Warehouse Entry Report';
    }

    public function printKey(): string
    {
        return 'warehouse-entry';
    }

    public function subtitle(): string
    {
        return 'Rolls received into a BPL store. "In the store" is the held position.';
    }

    protected function tables(): array
    {
        return [
            'hardroll' => 'bpl_warehouse_entry',
            'softroll' => 'bpl_softroll_warehouse_entry',
        ];
    }

    protected function dateLabel(): string
    {
        return 'Date Received';
    }

    protected function placeLabel(): string
    {
        return 'Warehouse';
    }

    protected function placeOptions(): array
    {
        return StockLocations::options(StockLocations::STORE);
    }

    protected function statusSpec(): array
    {
        return [
            'options' => ['none' => 'In the store', 'Exited' => 'Released'],
            'map' => ['Exited' => ['Released', 'badge-muted']],
            'null' => 'In the store',
            'nullClass' => 'badge-success',
        ];
    }
}
