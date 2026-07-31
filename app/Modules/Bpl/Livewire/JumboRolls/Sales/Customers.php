<?php

namespace Modules\Bpl\Livewire\JumboRolls\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplCustomer;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Jumbo Rolls → Sales → Customers. Rebuilt from legacy bpl_customers.php:
 * the Local/Export customer master (identity, contact, country, address).
 * The legacy per-customer product list is not part of this first pass; its
 * `products` JSON column is preserved untouched on edit.
 */
#[Title('BPL Customers')]
class Customers extends DataGrid
{
    public string $type = '';
    public string $customerlabel = '';
    public string $customername = '';
    public string $customercountry = '';
    public string $customeraddress = '';
    public string $customertelephone = '';
    public string $port = '';
    public string $fax = '';
    public string $email = '';

    public function pageKey(): string { return 'bpl.jumbo-rolls.sales.customers'; }
    public function pageLabel(): string { return 'BPL Customers'; }
    public function pageSubtitle(): string { return 'Customer master — Local and Export buyers of jumbo rolls.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.customer'; }
    public function defaultSort(): array { return ['customername', 'asc']; }
    public function modalSize(): string { return '680px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Label', 'customerlabel'],
                    ['Customer', 'customername'],
                    ['Type', 'type'],
                    ['Country', 'customercountry'],
                    ['Port', 'port'],
                    ['Phone', 'customertelephone'],
                    ['Email', 'email'],
                ],
                'query' => fn () => BplCustomer::query()
                    ->select('id', 'customerlabel', 'customername', 'type', 'customercountry', 'port', 'customertelephone', 'email'),
                'searchable' => ['customerlabel', 'customername', 'type', 'customercountry', 'port', 'customertelephone', 'email'],
                'sortable' => ['customerlabel', 'customername', 'type', 'customercountry', 'port', 'email'],
            ],
            'by_type' => [
                'label' => 'Summary (by type)',
                'type' => 'summary',
                'columns' => [
                    ['Type', 'type'],
                    ['Customers', 'total'],
                ],
                'query' => fn () => BplCustomer::query()
                    ->selectRaw("COALESCE(NULLIF(type, ''), '—') as type, COUNT(*) as total")
                    ->groupBy('type')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
            'by_country' => [
                'label' => 'Summary (by country)',
                'type' => 'summary',
                'columns' => [
                    ['Country', 'customercountry'],
                    ['Customers', 'total'],
                ],
                'query' => fn () => BplCustomer::query()
                    ->selectRaw("COALESCE(NULLIF(customercountry, ''), '—') as customercountry, COUNT(*) as total")
                    ->groupBy('customercountry')
                    ->orderByRaw('COUNT(*) DESC'),
            ],
        ];
    }

    /** Country options for the searchable select (legacy stored the name). */
    #[Computed]
    public function countries()
    {
        return DB::connection('bpl')->table('countries')
            ->orderBy('nicename')->pluck('nicename')
            ->map(fn ($n) => ['name' => $n]);
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', 'in:Local,Export'],
            'customerlabel' => ['required', 'string', 'max:20', Rule::unique('bpl.bpl_customers', 'customerlabel')->ignore($this->editingId)],
            'customername' => ['required', 'string', 'max:255', Rule::unique('bpl.bpl_customers', 'customername')->ignore($this->editingId)],
            'customercountry' => ['required', 'string', 'max:50'],
            'customeraddress' => ['required', 'string'],
            'customertelephone' => ['nullable', 'string', 'max:30'],
            'port' => ['nullable', 'string', 'max:100'],
            'fax' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:50'],
        ];
    }

    protected function resetForm(): void
    {
        $this->type = '';
        $this->customerlabel = '';
        $this->customername = '';
        $this->customercountry = '';
        $this->customeraddress = '';
        $this->customertelephone = '';
        $this->port = '';
        $this->fax = '';
        $this->email = '';
    }

    protected function fillForm(int $id): void
    {
        $c = BplCustomer::findOrFail($id);
        $this->type = (string) $c->type;
        $this->customerlabel = (string) $c->customerlabel;
        $this->customername = (string) $c->customername;
        $this->customercountry = (string) $c->customercountry;
        $this->customeraddress = (string) $c->customeraddress;
        $this->customertelephone = (string) $c->customertelephone;
        $this->port = (string) $c->port;
        $this->fax = (string) $c->fax;
        $this->email = (string) $c->email;
    }

    protected function findRow(int $id)
    {
        return BplCustomer::find($id);
    }

    /**
     * Customer ids that already have production, loaded once per render. Kept as
     * a set for O(1) membership so the per-row guard doesn't query each row.
     */
    protected ?array $producedCustomerIds = null;

    protected function customerHasProduction(int $id): bool
    {
        $this->producedCustomerIds ??= array_flip(
            DB::connection('bpl')->table('bpl_production')->distinct()->pluck('customer_id')->all()
        );

        return isset($this->producedCustomerIds[$id]);
    }

    /** Block delete once the customer has any recorded production. */
    public function deleteGuard($row): ?string
    {
        return $this->customerHasProduction((int) ($row->id ?? 0))
            ? 'Has jumbo-roll production — cannot delete.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        BplCustomer::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();
        BplCustomer::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Customer updated.' : 'Customer added.');
    }
}
