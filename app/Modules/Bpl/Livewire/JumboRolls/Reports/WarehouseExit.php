<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Livewire\Attributes\Title;
use Modules\Bpl\Support\StockLocations;

/**
 * BPL → Jumbo Rolls → Reports → Warehouse Exit. Rolls released from a BPL
 * store, over `bpl_warehouse_exit` and `bpl_softroll_warehouse_exit`.
 *
 * Rebuilt from the legacy `report_bpl_store_exit` and
 * `report_softroll_store_exit` pages.
 *
 * ## The Status column answers "did it actually go?"
 *
 * Warehouse Exit releases a roll; Delivery stamps `status = 'yes'` on that same
 * row when the roll is put on a truck. So a released roll with a NULL status is
 * standing at the gate — released, not yet loaded — which is the one thing this
 * report can tell you that the screen cannot. About one released roll in five
 * is in that state at any time, and a roll that has stood there for weeks is
 * either in the waiting area or is a scan nobody followed up.
 */
#[Title('BPL Warehouse Exit Report')]
class WarehouseExit extends MovementReport
{
    public function title(): string
    {
        return 'Warehouse Exit Report';
    }

    public function printKey(): string
    {
        return 'warehouse-exit';
    }

    public function subtitle(): string
    {
        return 'Rolls released from a BPL store — and whether a truck has taken them.';
    }

    protected function tables(): array
    {
        return [
            'hardroll' => 'bpl_warehouse_exit',
            'softroll' => 'bpl_softroll_warehouse_exit',
        ];
    }

    protected function dateLabel(): string
    {
        return 'Date of Exit';
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
            'options' => ['none' => 'Awaiting a truck', 'yes' => 'Loaded'],
            'map' => ['yes' => ['Loaded', 'badge-muted']],
            'null' => 'Awaiting a truck',
            'nullClass' => 'badge-warning',
        ];
    }
}
