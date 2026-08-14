<?php

namespace Modules\Bil\Livewire\FinishedGoods;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Bil\Models\StockTransfer as TransferModel;
use Modules\Bil\Support\StockTransfers;
use Modules\Core\Models\Warehouse;

/**
 * BIL → Finished Goods → Stock Transfer. Rebuild of the legacy
 * fg_inter_transfer.php.
 *
 * ONE DESTINATION LIST, NOT TWO SCREENS. The legacy form asked for a "company
 * from" and a "company to", and put a warehouse ("BIL ABUJA") and a company
 * ("BHN") in the same dropdown — so 812 warehouse-to-warehouse moves were
 * recorded as if they were inter-company transfers. Here the operator picks a
 * destination WAREHOUSE, grouped under its company, and whether the move is
 * internal or inter-company follows from that. Nobody is asked to classify
 * anything, and the two cases cannot be confused.
 *
 * The source company is the module's own (BIL = Belimpex) and is not asked for
 * either; what IS asked for is which of its warehouses the goods are leaving,
 * because that is the figure the stock ledger has to come off.
 *
 * Dispatch takes the bundles off the source immediately. They land at the
 * destination when it receives them — see StockTransferReceive.
 */
#[Layout('core::layouts.admin')]
#[Title('Stock Transfer')]
class StockTransfer extends Component
{
    public ?int $from_warehouse_id = null;
    public ?int $to_warehouse_id = null;
    public string $transfer_number = '';
    public string $truck_number = '';
    public string $dateIso = '';
    public string $note = '';

    /** Unsaved product lines: ['uid','productid','bundles']. */
    public array $rows = [];

    public const PAGE_KEY = 'bil.finished_goods.stock_transfer';

    public function mount(): void
    {
        $this->dateIso = now()->format('Y-m-d');
        $this->transfer_number = StockTransfers::nextTransferNumber();

        // One warehouse to send from is the common case; pre-select it rather
        // than making it a required click.
        $sources = StockTransfers::sources();
        if ($sources->count() === 1) {
            $this->from_warehouse_id = $sources->first()->id;
        }

        $this->addRow();
    }

    /* ---------------- Permissions ---------------- */

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    public function canBackdate(): bool
    {
        return $this->mayDo('backdate');
    }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function sources()
    {
        return StockTransfers::sources();
    }

    /**
     * Destinations grouped by company. The grouping IS the distinction: the
     * operator's own company first, anything under another heading is an
     * inter-company move.
     */
    #[Computed]
    public function destinations(): array
    {
        return StockTransfers::destinations($this->from_warehouse_id);
    }

    /** Flattened for the searchable select, with the company on each label. */
    #[Computed]
    public function destinationOptions(): array
    {
        $out = [];

        foreach ($this->destinations as $company => $warehouses) {
            foreach ($warehouses as $w) {
                $out[] = ['value' => (string) $w->id, 'label' => $w->name . ' — ' . $company];
            }
        }

        return $out;
    }

    #[Computed]
    public function products()
    {
        return FinishedGoodsProduct::query()->active()
            ->orderBy('productname')->get(['productid', 'productcode', 'productname']);
    }

    #[Computed]
    public function productOptions(): array
    {
        return $this->products->map(fn ($p) => [
            'value' => (string) $p->productid,
            'label' => $p->productname . ' (' . $p->productcode . ')',
        ])->all();
    }

    /* ---------------- Derived state ---------------- */

    /** Internal or inter-company — computed, never chosen. */
    #[Computed]
    public function kind(): string
    {
        return StockTransfers::kindFor($this->from_warehouse_id, $this->to_warehouse_id);
    }

    #[Computed]
    public function fromWarehouse(): ?Warehouse
    {
        return $this->from_warehouse_id ? Warehouse::with('company')->find($this->from_warehouse_id) : null;
    }

    #[Computed]
    public function toWarehouse(): ?Warehouse
    {
        return $this->to_warehouse_id ? Warehouse::with('company')->find($this->to_warehouse_id) : null;
    }

    /**
     * Bundles currently on the source warehouse, per product.
     *
     * Shown against each line so an operator can see what they are drawing
     * down. It does NOT block a dispatch: the opening figures are a seeded
     * placeholder until a physical count corrects them, and refusing real work
     * on the strength of a number known to be provisional would be worse than
     * letting it go negative and be reconciled.
     */
    #[Computed]
    public function available(): array
    {
        if (! $this->from_warehouse_id) {
            return [];
        }

        return DB::connection('core')->table('finished_goods_warehouse_stock')
            ->where('warehouse_id', $this->from_warehouse_id)
            ->pluck('bundles', 'productid')->all();
    }

    public function availableFor($productid): ?int
    {
        $productid = (int) $productid;

        return $productid > 0 ? (int) ($this->available[$productid] ?? 0) : null;
    }

    /* ---------------- Lines ---------------- */

    public function addRow(): void
    {
        $this->rows[] = [
            'uid' => Str::random(8),
            'productid' => '',
            'bundles' => '',
        ];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function updatedFromWarehouseId(): void
    {
        // The destination list excludes the source, so a stale choice could
        // otherwise leave a transfer pointing at itself.
        if ($this->to_warehouse_id === $this->from_warehouse_id) {
            $this->to_warehouse_id = null;
        }

        unset($this->destinations, $this->destinationOptions, $this->available, $this->kind);
    }

    /* ---------------- Save ---------------- */

    protected function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer', 'exists:core.warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', 'exists:core.warehouses,id'],
            'transfer_number' => ['required', 'string', 'max:64'],
            'truck_number' => ['nullable', 'string', 'max:64'],
            'dateIso' => ['required', 'date'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.productid' => ['required', 'integer', 'min:1'],
            'rows.*.bundles' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function messages(): array
    {
        return [
            'to_warehouse_id.different' => 'A transfer must go to a different warehouse.',
            'rows.*.productid.required' => 'Choose a product.',
            'rows.*.bundles.min' => 'Bundles must be at least 1.',
        ];
    }

    public function save(): void
    {
        $this->validate();

        // One product twice on one truck is a data-entry slip, and it would make
        // the line totals disagree with the stock movements.
        $ids = array_map(fn ($r) => (int) $r['productid'], $this->rows);
        if (count($ids) !== count(array_unique($ids))) {
            $this->addError('rows', 'The same product appears more than once — combine the lines.');

            return;
        }

        $date = $this->canBackdate() ? $this->dateIso : now()->format('Y-m-d');

        $transfer = StockTransfers::dispatch([
            'module' => StockTransfers::MODULE,
            'transfer_number' => $this->transfer_number,
            'truck_number' => $this->truck_number,
            'date_of_transfer' => $date,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'note' => $this->note,
        ], $this->rows);

        session()->flash('ok', sprintf(
            'Transfer %s dispatched — %s bundle(s) to %s. It will show on the destination\'s Receive screen.',
            $transfer->transfer_number,
            number_format($transfer->totalBundles()),
            $this->toWarehouse?->name ?? 'the destination'
        ));

        // Ready for the next truck.
        $this->reset(['truck_number', 'note', 'rows', 'to_warehouse_id']);
        $this->transfer_number = StockTransfers::nextTransferNumber();
        $this->addRow();
        unset($this->available, $this->kind);
    }

    public function render()
    {
        return view('bil::livewire.finished-goods.stock-transfer', [
            'interCompany' => $this->kind === TransferModel::INTER_COMPANY,
        ]);
    }
}
