<?php

namespace Modules\Bil\Livewire\RawMaterials;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Modules\Bil\Models\RawMaterialSupplier;
use Modules\Core\Livewire\DataGrid;

/**
 * Raw Materials → Suppliers. Rebuilt from legacy rawmaterials_suppliers.php
 * (list) + rawmaterials_supplier_details.php (add/edit form): the supplier
 * master — supplier id, name and code.
 */
#[Title('Raw Materials Suppliers List')]
class Suppliers extends DataGrid
{
    public string $supplierid = '';
    public string $suppliername = '';
    public string $suppliercode = '';

    public function pageKey(): string { return 'bil.raw-materials.suppliers'; }
    public function pageLabel(): string { return 'Raw Materials Suppliers List'; }
    public function pageSubtitle(): string { return 'Raw-materials supplier master — supplier id, name and code.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.raw-material-supplier'; }
    public function defaultSort(): array { return ['suppliername', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Supplier ID', 'supplierid'],
                    ['Supplier Name', 'suppliername'],
                    ['Supplier Code', 'suppliercode'],
                ],
                'query' => fn () => RawMaterialSupplier::query(),
                'searchable' => ['supplierid', 'suppliername', 'suppliercode'],
                'sortable' => ['supplierid', 'suppliername', 'suppliercode'],
            ],
        ];
    }

    protected function rules(): array
    {
        return [
            'supplierid' => ['required', 'string', 'max:50'],
            'suppliername' => ['required', 'string', 'max:255'],
            'suppliercode' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function resetForm(): void
    {
        $this->supplierid = '';
        $this->suppliername = '';
        $this->suppliercode = '';
    }

    protected function fillForm(int $id): void
    {
        $s = RawMaterialSupplier::findOrFail($id);
        $this->supplierid = (string) $s->supplierid;
        $this->suppliername = (string) $s->suppliername;
        $this->suppliercode = (string) $s->suppliercode;
    }

    protected function findRow(int $id)
    {
        return RawMaterialSupplier::find($id);
    }

    /**
     * Supplier codes that already appear in supplier deliveries, loaded once per
     * render (deliveries link by suppliercode, not id). Kept as a set for O(1)
     * membership so the per-row guard doesn't query each row.
     */
    protected ?array $deliveredSupplierCodes = null;

    protected function supplierHasDeliveries(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        $this->deliveredSupplierCodes ??= array_flip(array_filter(
            DB::connection('bil')->table('rawmaterials_supplier_deliveries')
                ->distinct()->pluck('suppliercode')->all(),
            fn ($v) => $v !== null && $v !== ''
        ));

        return isset($this->deliveredSupplierCodes[$code]);
    }

    /** Block delete once the supplier has any recorded delivery. */
    public function deleteGuard($row): ?string
    {
        return $this->supplierHasDeliveries($row->suppliercode ?? null)
            ? 'Has supplier deliveries — cannot delete.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        RawMaterialSupplier::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        RawMaterialSupplier::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Supplier updated.' : 'Supplier added.');
    }
}
