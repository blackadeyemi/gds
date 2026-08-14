<?php

namespace Modules\Bil\Livewire\FinishedGoods\Reports;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Livewire\RawMaterials\Reports\RawMaterialReport;
use Modules\Bil\Models\StockTransfer as TransferModel;
use Modules\Core\Models\Warehouse;

/**
 * BIL → Finished Goods → Reports → Stock Transfer. Rebuild of the legacy
 * report_fg_inter_transfer.php.
 *
 * Reads the rebuilt tables, so it covers both the 814 imported legacy lines
 * (flagged historic, with no source warehouse — the legacy data records only a
 * company on each side) and everything moved through gds since.
 *
 * `kind` is a first-class filter here, which is the point of deriving it: the
 * legacy report could not separate internal moves from inter-company ones,
 * because both were recorded in a column called "company to".
 */
#[Title('Stock Transfer Report')]
class StockTransfer extends RawMaterialReport
{
    protected ?array $optCache = null;

    public function title(): string
    {
        return 'Stock Transfer Report';
    }

    public function printKey(): string
    {
        return 'stock-transfer';
    }

    public function subtitle(): string
    {
        return 'Finished goods moved between warehouses — by line, by route and by product.';
    }

    protected function reportPageKey(): string
    {
        return 'bil.finished_goods.reports.stock_transfer';
    }

    protected function printRouteName(): string
    {
        return 'bil.finished-goods.reports.print';
    }

    protected function downloadRouteName(): string
    {
        return 'bil.finished-goods.reports.download';
    }

    protected function options(): array
    {
        return $this->optCache ??= [
            'warehouses' => Warehouse::where('module', 'finished-goods')
                ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'kinds' => [
                TransferModel::INTERNAL => 'Internal',
                TransferModel::INTER_COMPANY => 'Inter-company',
            ],
            'statuses' => [
                TransferModel::DISPATCHED => 'In transit',
                TransferModel::RECEIVED => 'Received',
                TransferModel::CANCELLED => 'Cancelled',
            ],
            'sources' => ['gds' => 'Recorded in gds', 'historic' => 'Imported history'],
        ];
    }

    public function filterDefs(): array
    {
        $o = $this->options();

        return [
            'from' => ['label' => 'From warehouse', 'options' => $o['warehouses']],
            'to' => ['label' => 'To warehouse', 'options' => $o['warehouses']],
            'kind' => ['label' => 'Type', 'options' => $o['kinds']],
            'status' => ['label' => 'Status', 'options' => $o['statuses']],
            'source' => ['label' => 'Source', 'options' => $o['sources']],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    /** Every filter is on the transfer header, so the count needs no joins. */
    protected function joinedFilterKeys(): array
    {
        return [];
    }

    protected function countQuery()
    {
        return $this->applyHeaderFilters(
            DB::connection('core')->table('stock_transfer_lines as l')
                ->join('stock_transfers as t', 'l.transfer_id', '=', 't.id')
        );
    }

    protected function applyHeaderFilters($q)
    {
        $f = $this->filters;

        return $this->applyDate($q, 't.date_of_transfer')
            ->where('t.module', 'finished-goods')
            ->when($f['from'] ?? '', fn ($q, $v) => $q->where('t.from_warehouse_id', $v))
            ->when($f['to'] ?? '', fn ($q, $v) => $q->where('t.to_warehouse_id', $v))
            ->when($f['kind'] ?? '', fn ($q, $v) => $q->where('t.kind', $v))
            ->when($f['status'] ?? '', fn ($q, $v) => $q->where('t.status', $v))
            ->when($f['source'] ?? '', fn ($q, $v) => $q->where('t.is_historic', $v === 'historic'));
    }

    protected function base()
    {
        $q = DB::connection('core')->table('stock_transfer_lines as l')
            ->join('stock_transfers as t', 'l.transfer_id', '=', 't.id')
            ->leftJoin('warehouses as wf', 't.from_warehouse_id', '=', 'wf.id')
            ->leftJoin('warehouses as wt', 't.to_warehouse_id', '=', 'wt.id');

        return $this->applyHeaderFilters($q)
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $term = '%' . $this->search . '%';
                $w->where('l.product_name', 'like', $term)
                  ->orWhere('l.product_code', 'like', $term)
                  ->orWhere('t.transfer_number', 'like', $term)
                  ->orWhere('t.truck_number', 'like', $term)
                  ->orWhere('wf.name', 'like', $term)
                  ->orWhere('wt.name', 'like', $term);
            }));
    }

    public function views(): array
    {
        $kind = fn ($r) => $r->kind === TransferModel::INTER_COMPANY
            ? '<span class="badge" style="background:rgba(217,119,6,.14);color:#b45309;">Inter-company</span>'
            : '<span class="badge badge-success">Internal</span>';

        $status = fn ($r) => match ($r->status) {
            TransferModel::DISPATCHED => '<span class="badge" style="background:rgba(59,130,246,.14);color:#1d4ed8;">In transit</span>',
            TransferModel::CANCELLED => '<span class="badge badge-danger">Cancelled</span>',
            default => '<span class="badge badge-muted">Received</span>',
        };

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Transfer', 'transfer_number'],
                    ['From', 'from_name'],
                    ['To', 'to_name'],
                    ['Type', 'kind', $kind],
                    ['Product Code', 'product_code'],
                    ['Product', 'product_name'],
                    ['Sent', 'bundles'],
                    ['Received', 'received_bundles'],
                    $this->dateCol('Date', 'date_of_transfer'),
                    ['Status', 'status', $status],
                ],
                'searchable' => ['l.product_name', 'l.product_code', 't.transfer_number', 't.truck_number'],
                'sortable' => ['transfer_number', 'from_name', 'to_name', 'kind', 'product_code',
                    'product_name', 'bundles', 'received_bundles', 'date_of_transfer', 'status'],
                'query' => fn () => $this->base()
                    ->select('l.id', 't.transfer_number', 'wf.name as from_name', 'wt.name as to_name',
                        't.kind', 'l.product_code', 'l.product_name', 'l.bundles', 'l.received_bundles',
                        't.date_of_transfer', 't.status')
                    // Along the (date) index, newest first — see the note on the
                    // Conversion Output report about ordering across an index.
                    ->orderByDesc('t.date_of_transfer')->orderByDesc('t.id'),
            ],

            'by_route' => [
                'label' => 'Summary (by route)',
                'type' => 'summary',
                'columns' => [
                    ['From', 'from_name'],
                    ['To', 'to_name'],
                    ['Type', 'kind', $kind],
                    ['Transfers', 'transfers'],
                    ['Bundles sent', 'bundles'],
                    ['Bundles received', 'received'],
                ],
                'searchable' => ['wf.name', 'wt.name'],
                'query' => fn () => $this->base()
                    ->selectRaw('wf.name as from_name, wt.name as to_name, t.kind,
                                 COUNT(DISTINCT t.id) as transfers,
                                 SUM(l.bundles) as bundles, SUM(l.received_bundles) as received')
                    ->groupBy('wf.name', 'wt.name', 't.kind')
                    ->orderByDesc(DB::raw('SUM(l.bundles)')),
            ],

            'by_product' => [
                'label' => 'Summary (by product)',
                'type' => 'summary',
                'columns' => [
                    ['Product Code', 'product_code'],
                    ['Product', 'product_name'],
                    ['Transfers', 'transfers'],
                    ['Bundles sent', 'bundles'],
                    ['Bundles received', 'received'],
                ],
                'searchable' => ['l.product_name', 'l.product_code'],
                'query' => fn () => $this->base()
                    ->selectRaw('l.product_code, l.product_name,
                                 COUNT(DISTINCT t.id) as transfers,
                                 SUM(l.bundles) as bundles, SUM(l.received_bundles) as received')
                    ->groupBy('l.product_code', 'l.product_name')
                    ->orderByDesc(DB::raw('SUM(l.bundles)')),
            ],

            'in_transit' => [
                'label' => 'In transit',
                'type' => 'summary',
                'columns' => [
                    ['Transfer', 'transfer_number'],
                    ['From', 'from_name'],
                    ['To', 'to_name'],
                    ['Truck', 'truck_number'],
                    $this->dateCol('Dispatched', 'date_of_transfer'),
                    ['Days out', 'days', fn ($r) => $this->daysOut($r->date_of_transfer)],
                    ['Bundles', 'bundles'],
                ],
                'searchable' => ['t.transfer_number', 't.truck_number'],
                // Its own predicate on top of the filters: what has left and not
                // arrived is the thing this view exists to show.
                'query' => fn () => $this->base()
                    ->where('t.status', TransferModel::DISPATCHED)
                    ->where('t.is_historic', false)
                    ->selectRaw('t.transfer_number, wf.name as from_name, wt.name as to_name,
                                 t.truck_number, t.date_of_transfer, SUM(l.bundles) as bundles')
                    ->groupBy('t.id', 't.transfer_number', 'wf.name', 'wt.name', 't.truck_number', 't.date_of_transfer')
                    ->orderBy('t.date_of_transfer'),
            ],
        ];
    }

    /** How long a truck has been out — a badge that reddens as it ages. */
    protected function daysOut($date): string
    {
        if (! $date) {
            return '—';
        }

        try {
            $days = (int) \Illuminate\Support\Carbon::parse($date)
                ->startOfDay()->diffInDays(now()->startOfDay());
        } catch (\Throwable) {
            return '—';
        }

        $class = match (true) {
            $days >= 7 => 'badge-danger',
            $days >= 3 => 'badge-muted',
            default => 'badge-success',
        };

        return '<span class="badge ' . $class . '">' . $days . 'd</span>';
    }
}
