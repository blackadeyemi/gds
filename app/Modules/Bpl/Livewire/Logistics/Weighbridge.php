<?php

namespace Modules\Bpl\Livewire\Logistics;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplWaybill;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Logistics → Weighbridge. The second half of the legacy
 * `report_bpl_delivery.php` (handler `Bil\Bpl\DeliveryReport`): a load leaves
 * the Delivery screen as **pending**, the loaded truck is weighed on the way
 * out, and that figure completes the waybill.
 *
 * THE CHECK, kept from legacy: net = loaded − empty, and the rolls on the
 * truck are weighed individually at production, so the two should agree. A net
 * more than 150 kg SHORT of the rolls means the figure (or the load) is wrong
 * and is refused. Measured over the 1,219 weighed loads: nothing is short by
 * more than 148 kg, so nothing real is blocked. There is deliberately no upper
 * bound — 22 of those loads came out over 150 kg heavy, one by 13.7 tonnes,
 * so refusing "too heavy" would refuse real deliveries.
 *
 * Changes from legacy:
 *  - **The empty weight can be corrected here**, next to the loaded one. The
 *    legacy only accepted the loaded weight on this screen, so a truck booked
 *    in with the wrong tare had to go back to the edit form.
 *  - **Everything is one transaction**, and a completed waybill's rolls are
 *    never touched.
 *  - **Cancelling a pending load returns its rolls to the loading bay** and
 *    removes the delivery, which is what the legacy's "delete" did — but it
 *    is now refused once the load has been weighed, instead of silently
 *    reverting the waybill to pending.
 *  - **Reopening a completed waybill is its own action**, not a delete.
 */
#[Title('BPL Weighbridge')]
class Weighbridge extends DataGrid
{
    /** Kilograms the net may fall short of the rolls before it is refused. */
    public const TOLERANCE = 150;

    public string $waybill = '';
    public string $truckWeight = '';
    public string $fullWeight = '';

    /** Read-only context for the form, filled by fillForm(). */
    public array $context = [];

    public function pageKey(): string { return 'bpl.logistics.weighbridge'; }
    public function pageLabel(): string { return 'BPL Weighbridge'; }
    public function pageSubtitle(): string { return 'Weigh the loaded truck. The figure completes the waybill the Delivery screen raised.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.weighbridge'; }
    public function defaultSort(): array { return ['id', 'desc']; }
    public function modalSize(): string { return '560px'; }

    /** Nothing is created here: a waybill only ever arrives from Delivery. */
    public function create(): void {}

    /* ---------------- The grid ---------------- */

    /**
     * One row per waybill, with its delivery and its rolls.
     *
     * The delivery is reached through the load's FIRST line rather than a
     * group-by: every line of a waybill carries the same `bpl_delivery_id`,
     * and joining them all would multiply the row by its rolls.
     */
    protected function baseQuery()
    {
        return BplWaybill::query()
            ->leftJoin('bpl_delivery_details as d', 'd.id', '=', DB::raw(
                '(SELECT MIN(dd.`id`) FROM `bpl_delivery_details` dd'
                . ' WHERE dd.`delivery_barcode` = `bpl_delivery_barcode`.`delivery_barcode`)'
            ))
            ->leftJoin('bpl_delivery as f', 'f.id', '=', 'd.bpl_delivery_id')
            ->select('bpl_delivery_barcode.*')
            ->selectRaw('f.customer_name, f.vechile_number, f.driver, f.date as delivery_date,'
                . ' CAST(f.truck_weight AS DECIMAL(12,2)) as truck_weight')
            ->selectRaw('(SELECT COUNT(*) FROM `bpl_delivery_details` dd'
                . ' WHERE dd.`delivery_barcode` = `bpl_delivery_barcode`.`delivery_barcode`) as roll_count')
            ->selectRaw('(SELECT ROUND(COALESCE(SUM(p.`weight`), 0), 2) FROM `bpl_delivery_details` dd'
                . ' JOIN `bpl_production` p ON p.`barcode` = dd.`barcode`'
                . ' WHERE dd.`delivery_barcode` = `bpl_delivery_barcode`.`delivery_barcode`) as roll_weight');
    }

    /** Net weight off the weighbridge, or null while the load is unweighed. */
    public static function netOf($row): ?float
    {
        $full = (float) ($row->full_weight ?? 0);

        return $full > 0 ? round($full - (float) ($row->truck_weight ?? 0), 2) : null;
    }

    public function views(): array
    {
        $kg = fn (string $field) => fn ($r) => (float) ($r->{$field} ?? 0) > 0
            ? number_format((float) $r->{$field}, 2)
            : '<span class="text-muted">—</span>';

        $difference = function ($r) {
            $net = self::netOf($r);
            if ($net === null) {
                return '<span class="text-muted">—</span>';
            }

            $diff = round($net - (float) $r->roll_weight, 2);
            $class = abs($diff) <= self::TOLERANCE ? 'badge-muted' : 'badge-danger';

            return '<span class="badge ' . $class . '">' . ($diff > 0 ? '+' : '') . number_format($diff, 2) . '</span>';
        };

        $status = fn ($r) => '<span class="badge ' . ($r->status === BplWaybill::COMPLETED ? 'badge-success' : 'badge-warning') . '">'
            . e((string) $r->status) . '</span>';

        $columns = [
            ['Waybill', 'delivery_barcode', fn ($r) => '<span class="mono">' . e($r->delivery_barcode) . '</span>'],
            ['Date', 'date'],
            ['Customer', 'customer_name', fn ($r) => e((string) ($r->customer_name ?: '—'))],
            ['Truck', 'vechile_number', fn ($r) => e((string) ($r->vechile_number ?: '—'))],
            ['Rolls', 'roll_count', fn ($r) => number_format((int) $r->roll_count)],
            ['Roll Weight', 'roll_weight', fn ($r) => number_format((float) $r->roll_weight, 2)],
            ['Empty', 'truck_weight', $kg('truck_weight')],
            ['Loaded', 'full_weight', $kg('full_weight')],
            ['Net', 'net', fn ($r) => ($n = self::netOf($r)) === null ? '<span class="text-muted">—</span>' : number_format($n, 2)],
            ['Difference', 'difference', $difference],
            ['Status', 'status', $status],
        ];

        $searchable = ['bpl_delivery_barcode.delivery_barcode', 'f.customer_name', 'f.vechile_number', 'f.driver'];
        $sortable = ['delivery_barcode', 'date', 'customer_name', 'vechile_number', 'roll_count', 'roll_weight',
            'truck_weight', 'full_weight', 'status', 'id'];

        return [
            'default' => [
                'label' => 'Awaiting weight',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->baseQuery()->where('bpl_delivery_barcode.status', BplWaybill::PENDING),
                'count' => fn () => BplWaybill::query()->where('status', BplWaybill::PENDING),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            'completed' => [
                'label' => 'Weighed',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->baseQuery()->where('bpl_delivery_barcode.status', BplWaybill::COMPLETED),
                'count' => fn () => BplWaybill::query()->where('status', BplWaybill::COMPLETED),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            'all' => [
                'label' => 'All',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->baseQuery(),
                'count' => fn () => BplWaybill::query(),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
        ];
    }

    /* ---------------- Row actions ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return true;
    }

    public function leadingRowActions($row): string
    {
        $print = '<a href="' . e(route('bpl.logistics.delivery.waybill', $row->delivery_barcode)) . '"'
            . ' target="_blank" rel="noopener" class="btn btn-ghost btn-icon btn-sm" title="Print waybill">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>'
            . '<path d="M6 14h12v8H6z"/></svg></a>';

        if ($row->status !== BplWaybill::COMPLETED || ! $this->mayDo('edit')) {
            return $print;
        }

        return $print . '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
            . ' wire:click="reopen(' . (int) $row->id . ')"'
            . ' wire:confirm="Reopen waybill ' . e($row->delivery_barcode) . ' for re-weighing?"'
            . ' title="Reopen for re-weighing">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></button>';
    }

    /** Put a weighed waybill back in the queue — the figure stays until it is re-entered. */
    public function reopen(int $id): void
    {
        if (! $this->mayDo('edit')) {
            return;
        }

        $waybill = BplWaybill::find($id);
        if (! $waybill || $waybill->status !== BplWaybill::COMPLETED) {
            return;
        }

        $waybill->update(['status' => BplWaybill::PENDING, 'updated_at' => now()->format('y-m-d')]);

        session()->flash('ok', 'Waybill ' . $waybill->delivery_barcode . ' reopened for re-weighing.');
    }

    /* ---------------- The form ---------------- */

    protected function resetForm(): void
    {
        $this->waybill = '';
        $this->truckWeight = '';
        $this->fullWeight = '';
        $this->context = [];
    }

    protected function fillForm(int $id): void
    {
        $row = $this->findRow($id);
        abort_if(! $row, 404);

        $this->waybill = (string) $row->delivery_barcode;
        $this->truckWeight = (float) $row->truck_weight > 0 ? (string) (float) $row->truck_weight : '';
        $this->fullWeight = (float) $row->full_weight > 0 ? (string) (float) $row->full_weight : '';
        $this->context = [
            'customer' => (string) ($row->customer_name ?? ''),
            'truck' => (string) ($row->vechile_number ?? ''),
            'driver' => (string) ($row->driver ?? ''),
            'rolls' => (int) $row->roll_count,
            'roll_weight' => (float) $row->roll_weight,
            'status' => (string) $row->status,
            'delivery_id' => $row->delivery_id ? (int) $row->delivery_id : null,
        ];
    }

    protected function findRow(int $id)
    {
        return $this->baseQuery()->addSelect('d.bpl_delivery_id as delivery_id')
            ->where('bpl_delivery_barcode.id', $id)->first();
    }

    protected function rules(): array
    {
        return [
            'truckWeight' => ['required', 'numeric', 'min:1'],
            'fullWeight' => ['required', 'numeric', 'min:1'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['truckWeight' => 'empty truck weight', 'fullWeight' => 'loaded truck weight'];
    }

    public function save(): void
    {
        $data = $this->validate();

        $empty = (float) $data['truckWeight'];
        $full = (float) $data['fullWeight'];

        if ($full <= $empty) {
            $this->addError('fullWeight', 'The loaded truck must weigh more than the empty one.');

            return;
        }

        $row = $this->findRow((int) $this->editingId);
        if (! $row) {
            session()->flash('err', 'That waybill no longer exists.');
            $this->showModal = false;

            return;
        }

        $net = round($full - $empty, 2);
        $shortfall = round((float) $row->roll_weight - $net, 2);

        if ($shortfall > self::TOLERANCE) {
            $this->addError('fullWeight', 'That leaves a net of ' . number_format($net, 2) . ' kg, '
                . number_format($shortfall, 2) . ' kg short of the ' . number_format((float) $row->roll_weight, 2)
                . ' kg of rolls on the truck. Check the weights, or the load.');

            return;
        }

        DB::connection('bpl')->transaction(function () use ($row, $empty, $full) {
            if ($row->delivery_id) {
                DB::connection('bpl')->table('bpl_delivery')->where('id', $row->delivery_id)
                    ->update(['truck_weight' => (string) $empty, 'updated_at' => now()->format('Y-m-d H:i:s')]);
            }

            BplWaybill::whereKey($row->id)->update([
                'full_weight' => (string) $full,
                'status' => BplWaybill::COMPLETED,
                'updated_at' => now()->format('y-m-d'),
            ]);
        });

        $this->showModal = false;
        session()->flash('ok', 'Waybill ' . $row->delivery_barcode . ' weighed — net ' . number_format($net, 2) . ' kg.');
    }

    /* ---------------- Cancelling a load ---------------- */

    /** Only an unweighed load can be cancelled; a weighed one is reopened instead. */
    public function deleteGuard($row): ?string
    {
        return $row->status === BplWaybill::COMPLETED
            ? 'Already weighed — reopen it first if the load has to be undone.'
            : null;
    }

    /**
     * Cancel a pending load: the rolls go back to the loading bay and the
     * delivery, its lines and its waybill are removed — the legacy's "delete".
     */
    protected function performDelete(int $id): void
    {
        $row = $this->findRow($id);
        if (! $row || $row->status === BplWaybill::COMPLETED) {
            return;
        }

        $bpl = DB::connection('bpl');

        $bpl->transaction(function () use ($bpl, $row) {
            $barcodes = $bpl->table('bpl_delivery_details')
                ->where('delivery_barcode', $row->delivery_barcode)->pluck('barcode')->all();

            if ($barcodes !== []) {
                $bpl->table('bpl_warehouse_exit')->whereIn('barcode', $barcodes)
                    ->update(['status' => null, 'updated_at' => now()]);
            }

            $bpl->table('bpl_delivery_details')->where('delivery_barcode', $row->delivery_barcode)->delete();
            $bpl->table('bpl_delivery_barcode')->where('id', $row->id)->delete();

            if ($row->delivery_id) {
                $bpl->table('bpl_delivery')->where('id', $row->delivery_id)->delete();
            }
        });

    }

    public function deletedMessage($row): string
    {
        return 'Waybill ' . $row->delivery_barcode . ' cancelled — its '
            . number_format((int) $row->roll_count) . ' roll' . ((int) $row->roll_count === 1 ? '' : 's')
            . ' are back on the loading bay.';
    }
}
