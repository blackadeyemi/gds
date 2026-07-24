<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialGroup;
use Modules\Bil\Models\RawMaterialProduct;
use Modules\Bil\Models\RawMaterialSubgroup;
use Modules\Core\Livewire\DataGrid;

/**
 * Raw Materials → Products. Rebuilt from legacy rawmaterials_products.php:
 * the product master joined to its group and sub-group, with add/edit
 * (legacy rawmaterials_product_specifications.php) and delete.
 */
#[Title('Raw Materials Products List')]
class Products extends DataGrid
{
    public string $storecode = '';
    public string $productname = '';
    public string $accountcode = '';
    public ?int $groupid = null;
    public ?int $subgroupid = null;
    public string $common = 'No';

    public function pageKey(): string { return 'bil.raw-materials.products'; }
    public function pageLabel(): string { return 'Raw Materials Products List'; }
    public function pageSubtitle(): string { return 'Raw-materials product master — group, sub-group, store & account codes.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.raw-material-product'; }
    public function defaultSort(): array { return ['groupname', 'asc']; }
    public function modalSize(): string { return '640px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Group Name', 'groupname'],
                    ['Group Code', 'groupcode'],
                    ['Product Name', 'productname'],
                    ['Store Code', 'storecode'],
                    ['Sub-Group Name', 'subgroupname'],
                    ['Sub-Group Code', 'subgroupcode'],
                ],
                // Mirror the legacy join so group/sub-group columns are real,
                // sortable and searchable (aliases match the underlying column
                // names, so they resolve in WHERE/ORDER BY too).
                'query' => fn () => RawMaterialProduct::query()
                    ->leftJoin('rawmaterials_groups as g', 'rawmaterials_products.groupid', '=', 'g.id')
                    ->leftJoin('rawmaterials_subgroups as s', 'rawmaterials_products.subgroupid', '=', 's.id')
                    ->select(
                        'rawmaterials_products.id',
                        'rawmaterials_products.productname',
                        'rawmaterials_products.storecode',
                        'rawmaterials_products.accountcode',
                        'rawmaterials_products.groupid',
                        'rawmaterials_products.subgroupid',
                        'g.groupname',
                        'g.groupcode',
                        's.subgroupname',
                        's.subgroupcode',
                    ),
                'searchable' => ['productname', 'storecode', 'accountcode', 'groupname', 'groupcode', 'subgroupname', 'subgroupcode'],
                'sortable' => ['groupname', 'groupcode', 'productname', 'storecode', 'subgroupname', 'subgroupcode'],
            ],
            'by_group' => [
                'label' => 'Summary (by group name)',
                'type' => 'summary',
                'columns' => [
                    ['Group Name', 'groupname'],
                    ['Group Code', 'groupcode'],
                    ['Products', 'total'],
                ],
                'query' => fn () => RawMaterialProduct::query()
                    ->leftJoin('rawmaterials_groups as g', 'rawmaterials_products.groupid', '=', 'g.id')
                    ->selectRaw("COALESCE(g.groupname, '—') as groupname, COALESCE(g.groupcode, '') as groupcode, COUNT(*) as total")
                    ->groupBy('g.groupname', 'g.groupcode')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
            'by_subgroup' => [
                'label' => 'Summary (by sub group)',
                'type' => 'summary',
                'columns' => [
                    ['Sub-Group Name', 'subgroupname'],
                    ['Sub-Group Code', 'subgroupcode'],
                    ['Products', 'total'],
                ],
                'query' => fn () => RawMaterialProduct::query()
                    ->leftJoin('rawmaterials_subgroups as s', 'rawmaterials_products.subgroupid', '=', 's.id')
                    ->selectRaw("COALESCE(s.subgroupname, '—') as subgroupname, COALESCE(s.subgroupcode, '') as subgroupcode, COUNT(*) as total")
                    ->groupBy('s.subgroupname', 's.subgroupcode')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
        ];
    }

    #[Computed]
    public function groups()
    {
        return RawMaterialGroup::orderBy('groupname')->get();
    }

    /** Sub-groups for the picked group (legacy showed all; this scopes them). */
    #[Computed]
    public function subgroups()
    {
        return RawMaterialSubgroup::when($this->groupid, fn ($q) => $q->where('groupid', $this->groupid))
            ->orderBy('subgroupname')
            ->get();
    }

    protected function rules(): array
    {
        return [
            'storecode' => ['required', 'string', 'max:50'],
            'productname' => ['required', 'string', 'max:255'],
            'accountcode' => ['nullable', 'string', 'max:50'],
            'groupid' => ['required', 'integer'],
            'subgroupid' => ['required', 'integer'],
            'common' => ['required', 'in:No,Yes'],
        ];
    }

    protected function resetForm(): void
    {
        $this->storecode = '';
        $this->productname = '';
        $this->accountcode = '';
        $this->groupid = null;
        $this->subgroupid = null;
        $this->common = 'No';
    }

    protected function fillForm(int $id): void
    {
        $p = RawMaterialProduct::findOrFail($id);
        $this->storecode = (string) $p->storecode;
        $this->productname = (string) $p->productname;
        $this->accountcode = (string) $p->accountcode;
        $this->groupid = $p->groupid;
        $this->subgroupid = $p->subgroupid;
        $this->common = $p->common ?: 'No';
    }

    protected function findRow(int $id)
    {
        return RawMaterialProduct::find($id);
    }

    /**
     * Product ids that already appear in supplier deliveries, loaded once per
     * render (only ~90 of 325 products, from 129k deliveries). Kept as a set
     * for O(1) membership so the per-row guard doesn't query each row.
     */
    protected ?array $deliveredProductIds = null;

    protected function productHasDeliveries(int $id): bool
    {
        $this->deliveredProductIds ??= array_flip(
            DB::connection('bil')->table('rawmaterials_supplier_deliveries')
                ->distinct()->pluck('productid')->all()
        );

        return isset($this->deliveredProductIds[$id]);
    }

    /** Block delete once the product has been received via a supplier delivery. */
    public function deleteGuard($row): ?string
    {
        return $this->productHasDeliveries((int) ($row->id ?? 0))
            ? 'Has supplier deliveries — cannot delete.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        RawMaterialProduct::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        RawMaterialProduct::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Product updated.' : 'Product added.');
    }
}
