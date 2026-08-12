<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bil\Models\FgWarehouseStock;
use Modules\Bil\Support\FinishedGoodsStock;
use Modules\Bil\Support\FinishedGoodsStockMovements;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Warehouse;

/**
 * BIL → Finished Goods → Stock. Bundles of each product held in each warehouse.
 *
 * Editing a row does NOT write `bundles` directly. It records an adjustment —
 * the signed difference between what is there and what the operator typed —
 * so stock stays derivable and every correction has an author, a timestamp and
 * a reason. See FinishedGoodsStock::setTo().
 *
 * That matters most right now: the figure starts from the cut-over, since
 * imported history is excluded, so somebody has to key the opening balance in.
 * Doing that through adjustments means the opening balance is itself auditable
 * rather than a number that silently appeared.
 *
 * Sorted by quantity, highest first — the question this page answers is
 * usually "what do we have most of / has anything gone negative".
 */
#[Title('Finished Goods Stock')]
class Stock extends DataGrid
{
    public ?int $warehouse_id = null;
    public ?int $productid = null;
    public ?int $bundles = null;
    public ?string $reason = null;

    /** Read-only context shown in the edit modal. */
    public string $productLabel = '';
    public string $warehouseLabel = '';
    public int $currentBundles = 0;

    /* ---- movement detail modal ---- */
    public bool $showMovements = false;
    public ?int $detailWarehouseId = null;
    public ?int $detailProductId = null;
    public string $movementTab = 'incoming';

    public function pageKey(): string { return 'bil.finished_goods.stock'; }
    public function pageLabel(): string { return 'Finished Goods Stock'; }
    public function pageSubtitle(): string
    {
        $counted = $this->ordersCountedAt();

        return 'Bundles held per product, per warehouse. Corrections are recorded as adjustments.'
            . ($counted ? ' Order counts recounted ' . $counted . '.' : '');
    }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.fg-stock'; }
    public function extraView(): ?string { return 'bil::partials.fg-stock-movements'; }

    /** Highest quantity first — the default question this page answers. */
    public function defaultSort(): array { return ['bundles', 'desc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Product Code', 'productcode', fn ($r) => e($r->productcode ?? '—')],
                    ['Product', 'productname', fn ($r) => e($r->productname ?? '—')],
                    ['Warehouse', 'warehouse_name'],
                    ['Bundles', 'bundles', fn ($r) => $r->bundles < 0
                        ? '<span class="badge badge-danger">' . number_format($r->bundles) . '</span>'
                        : number_format($r->bundles)],
                    ['Orders (90d)', 'orders_90d', fn ($r) => $r->orders_90d
                        ? number_format($r->orders_90d)
                        : '<span class="text-muted">0</span>'],
                    ['Ordered qty (90d)', 'ordered_qty_90d', fn ($r) => number_format($r->ordered_qty_90d)],
                    ['Last Changed', 'updated_at', fn ($r) => $r->updated_at
                        ? e(\Illuminate\Support\Carbon::parse($r->updated_at)->format('d M Y H:i'))
                        : '—'],
                ],
                // Every column sorts, including the product — which is only
                // possible because the name is denormalised onto the row;
                // products live on `bil` and cannot be joined from `core`.
                'sortable' => ['productcode', 'productname', 'warehouse_name', 'bundles',
                    'orders_90d', 'ordered_qty_90d', 'updated_at'],
                'searchable' => ['s.productname', 's.productcode', 'w.name'],
                'query' => fn () => $this->base(),
            ],
            'by_warehouse' => [
                'label' => 'Summary (by warehouse)',
                'type' => 'summary',
                'columns' => [
                    ['Warehouse', 'warehouse_name'],
                    ['Products', 'products'],
                    ['Bundles', 'bundles'],
                ],
                'query' => fn () => FgWarehouseStock::query()
                    ->from('finished_goods_warehouse_stock as s')
                    ->leftJoin('warehouses as w', 's.warehouse_id', '=', 'w.id')
                    ->selectRaw("COALESCE(w.name, '—') as warehouse_name,
                                 COUNT(*) as products, SUM(s.bundles) as bundles")
                    ->groupBy('warehouse_name'),
            ],
        ];
    }

    /* ---------------- Movement detail ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return true;
    }

    public function leadingRowActions($row): string
    {
        return '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
            . ' wire:click="openMovements(' . (int) $row->id . ')" title="View movements">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            . '</svg></button>';
    }

    /**
     * Open the movement modal.
     *
     * NOT named `showMovements` — that is the property, and a Livewire action
     * sharing a property's name is shadowed by it, so `wire:click` silently
     * did nothing. (A direct ->call() in a test still worked, which is how it
     * got through.)
     */
    public function openMovements(int $id): void
    {
        $row = DB::connection('core')->table('finished_goods_warehouse_stock')->find($id);
        if (! $row) {
            return;
        }

        $this->detailWarehouseId = (int) $row->warehouse_id;
        $this->detailProductId = (int) $row->productid;
        $this->productLabel = (string) ($row->productname ?? '—');
        $this->warehouseLabel = (string) (Warehouse::find($row->warehouse_id)?->name ?? '—');
        $this->currentBundles = (int) $row->bundles;
        $this->movementTab = 'incoming';
        $this->showMovements = true;
    }

    public function closeMovements(): void
    {
        $this->showMovements = false;
        $this->detailWarehouseId = null;
        $this->detailProductId = null;
    }

    /**
     * Movements read live, never stored.
     *
     * These already exist as rows — receipts, adjustments, orders, loadings,
     * deliveries, returns — so caching them onto the stock row would duplicate
     * data that has an owner and go stale the moment it changed. Reading them
     * on open also means the modal and the reconciled total can never disagree.
     */
    #[Computed]
    public function movements(): array
    {
        if (! $this->detailWarehouseId || ! $this->detailProductId) {
            return ['incoming' => [], 'outgoing' => []];
        }

        return [
            'incoming' => FinishedGoodsStockMovements::incoming($this->detailWarehouseId, $this->detailProductId),
            'outgoing' => FinishedGoodsStockMovements::outgoing($this->detailProductId),
        ];
    }

    /**
     * Stock with its warehouse.
     *
     * The product name and code are columns on the row rather than a join:
     * stock is on `core` and products on `bil`, and MySQL cannot join two
     * connections — so sorting or searching by product needs them here. They
     * are refreshed on every movement and by `bil:reconcile-fg-stock`.
     */
    protected function base()
    {
        // Eloquent rather than the query builder: the grid blade calls
        // `$row->getKey()` for row actions, which a stdClass has not got.
        return FgWarehouseStock::query()->from('finished_goods_warehouse_stock as s')
            ->leftJoin('warehouses as w', 's.warehouse_id', '=', 'w.id')
            ->select('s.id', 's.warehouse_id', 's.productid', 's.productname', 's.productcode',
                's.bundles', 's.orders_90d', 's.ordered_qty_90d', 's.orders_counted_at',
                's.updated_at', 'w.name as warehouse_name');
    }

    /** When the order counts were last recounted — a stale figure should show. */
    #[Computed]
    public function ordersCountedAt(): ?string
    {
        $at = DB::connection('core')->table('finished_goods_warehouse_stock')->max('orders_counted_at');

        return $at ? \Illuminate\Support\Carbon::parse($at)->diffForHumans() : null;
    }

    #[Computed]
    public function warehouses()
    {
        return Warehouse::forModule('finished-goods')->ordered()->get();
    }

    /** Stock lines are created by movement, never by hand. */
    public function canCreate(): bool
    {
        return false;
    }

    protected function rules(): array
    {
        return [
            'bundles' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function resetForm(): void
    {
        $this->warehouse_id = null;
        $this->productid = null;
        $this->bundles = null;
        $this->reason = null;
        $this->productLabel = '';
        $this->warehouseLabel = '';
        $this->currentBundles = 0;
    }

    protected function fillForm(int $id): void
    {
        $row = DB::connection('core')->table('finished_goods_warehouse_stock')->find($id);
        if (! $row) {
            return;
        }

        $this->warehouse_id = (int) $row->warehouse_id;
        $this->productid = (int) $row->productid;
        $this->currentBundles = (int) $row->bundles;
        $this->bundles = (int) $row->bundles;
        $this->reason = null;

        $this->productLabel = (string) (DB::connection('bil')->table('products')
            ->where('productid', $row->productid)->value('productname') ?? '—');
        $this->warehouseLabel = (string) (Warehouse::find($row->warehouse_id)?->name ?? '—');
    }

    /** Stock is never deleted — a line at zero is a fact, not clutter. */
    public function canDelete(): bool
    {
        return false;
    }

    public function save(): void
    {
        $data = $this->validate();

        if (! $this->warehouse_id || ! $this->productid) {
            return;
        }

        $target = (int) $data['bundles'];
        if ($target === $this->currentBundles) {
            $this->showModal = false;
            session()->flash('ok', 'No change.');

            return;
        }

        FinishedGoodsStock::setTo(
            $this->warehouse_id,
            $this->productid,
            $target,
            $data['reason'] ?: null
        );

        $this->showModal = false;
        session()->flash('ok', sprintf(
            'Stock set to %s (%+d recorded as an adjustment).',
            number_format($target),
            $target - $this->currentBundles
        ));
    }
}
