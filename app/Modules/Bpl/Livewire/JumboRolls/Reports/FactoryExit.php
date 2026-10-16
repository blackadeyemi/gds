<?php

namespace Modules\Bpl\Livewire\JumboRolls\Reports;

use Livewire\Attributes\Title;
use Modules\Bpl\Support\StockLocations;

/**
 * BPL → Jumbo Rolls → Reports → Factory Exit. Rolls scanned off a paper
 * machine floor, over `bpl_factoryexit` and `bpl_softroll_factoryexit`.
 *
 * Rebuilt from the legacy `report_bpl_factory_exit` and
 * `report_softroll_factory_exit` pages — the same report written twice, once
 * per stream — with the Type filter doing what the two pages did.
 *
 * ## The Status column
 *
 * A factory-exit row is stamped nothing; what it MEANS is stamped on the roll.
 * `bpl_production.status` = 'Exited' from the moment this scan happens, so the
 * exit row's own `status` stays NULL for all but one row in 132,000 — a
 * decade-old stray. The useful onward question ("did it reach a store?") is
 * what the Roll Trail report answers, because it needs the entry table.
 */
#[Title('BPL Factory Exit Report')]
class FactoryExit extends MovementReport
{
    public function title(): string
    {
        return 'Factory Exit Report';
    }

    public function printKey(): string
    {
        return 'factory-exit';
    }

    public function subtitle(): string
    {
        return 'Rolls scanned off a BPL paper machine floor.';
    }

    protected function tables(): array
    {
        return [
            'hardroll' => 'bpl_factoryexit',
            'softroll' => 'bpl_softroll_factoryexit',
        ];
    }

    protected function dateLabel(): string
    {
        return 'Date of Exit';
    }

    protected function placeLabel(): string
    {
        return 'Factory';
    }

    protected function placeOptions(): array
    {
        return StockLocations::options(StockLocations::FLOOR);
    }

    protected function statusSpec(): array
    {
        return [
            'options' => ['none' => 'Left the floor', 'Exited' => 'Exited (legacy stamp)'],
            'map' => ['Exited' => ['Exited', 'badge-muted']],
            'null' => 'Left the floor',
            'nullClass' => 'badge-success',
        ];
    }
}
