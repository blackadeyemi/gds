<?php

namespace Modules\Bpl\Livewire\JumboRolls\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplCustomer;
use Modules\Bpl\Models\BplPort;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Jumbo Rolls → Sales → Customers. Rebuilt from legacy bpl_customers.php
 * and enriched: address is geocoded to a lat/lng point (OpenStreetMap), the
 * port is a country-dependent dropdown shown only for Export customers, and
 * the phone is captured as dial code + national number.
 * The legacy per-customer `products` JSON is preserved untouched on edit.
 */
#[Title('BPL Customers')]
class Customers extends DataGrid
{
    public string $type = '';
    public string $customerlabel = '';
    public string $customername = '';
    public string $customercountry = '';
    public string $customeraddress = '';
    public ?float $latitude = null;
    public ?float $longitude = null;
    public string $phone_dialcode = '';
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
    public function modalSize(): string { return '760px'; }

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
                    ['Phone', 'customertelephone', fn ($r) => $this->phoneDisplay($r)],
                    ['Email', 'email'],
                ],
                'query' => fn () => BplCustomer::query()
                    ->select('id', 'customerlabel', 'customername', 'type', 'customercountry', 'port', 'phone_dialcode', 'customertelephone', 'email'),
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

    /** "+234 8012…" for the grid; passes through a legacy full number as-is. */
    protected function phoneDisplay($row): string
    {
        $n = (string) ($row->customertelephone ?? '');
        $d = (string) ($row->phone_dialcode ?? '');
        if ($n === '') return '—';
        if ($d === '' || str_starts_with($n, '+')) return e($n);
        return e('+' . $d . ' ' . $n);
    }

    /* ---------------- Reference data ---------------- */

    protected ?Collection $countryRows = null;

    protected function countryRows(): Collection
    {
        return $this->countryRows ??= DB::connection('bpl')->table('countries')
            ->select('iso', 'nicename', 'phonecode')
            ->orderBy('nicename')->get();
    }

    /** Country-name options for the address country select. */
    #[Computed]
    public function countries(): Collection
    {
        return $this->countryRows()->map(fn ($c) => ['name' => $c->nicename]);
    }

    /** Dial-code options: flag + country + code, value is the numeric code. */
    #[Computed]
    public function dialCodes(): Collection
    {
        return $this->countryRows()
            ->filter(fn ($c) => (string) $c->phonecode !== '' && (int) $c->phonecode !== 0)
            ->map(fn ($c) => [
                'value' => (string) $c->phonecode,
                'label' => $this->flag($c->iso) . '  ' . $c->nicename . '  (+' . $c->phonecode . ')',
            ])->values();
    }

    /** Ports for the currently selected country (empty until a country is set). */
    #[Computed]
    public function ports(): Collection
    {
        $iso = $this->selectedIso();
        if (! $iso) return collect();

        return BplPort::query()->where('country_iso', $iso)->orderBy('name')
            ->pluck('name')->map(fn ($n) => ['value' => $n, 'label' => $n]);
    }

    protected function selectedIso(): ?string
    {
        if ($this->customercountry === '') return null;

        return $this->countryRows()->firstWhere('nicename', $this->customercountry)?->iso;
    }

    /** ISO-3166 alpha-2 → regional-indicator flag emoji. */
    protected function flag(?string $iso): string
    {
        if (! $iso || strlen($iso) !== 2) return '';
        $iso = strtoupper($iso);

        return mb_chr(0x1F1E6 + ord($iso[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($iso[1]) - 65, 'UTF-8');
    }

    /* ---------------- Dependent-field reactions ---------------- */

    public function updatedType(): void
    {
        if ($this->type !== 'Export') {
            $this->port = '';
        }
    }

    public function updatedCustomercountry(): void
    {
        // Country drives the port list — the previous port no longer applies.
        $this->port = '';
        // Helpfully default the dial code from the country when unset.
        if ($this->phone_dialcode === '') {
            $this->phone_dialcode = (string) ($this->countryRows()->firstWhere('nicename', $this->customercountry)->phonecode ?? '');
        }
    }

    /* ---------------- Validation / persistence ---------------- */

    protected function rules(): array
    {
        return [
            'type' => ['required', 'in:Local,Export'],
            'customerlabel' => ['required', 'string', 'max:20', Rule::unique('bpl.bpl_customers', 'customerlabel')->ignore($this->editingId)],
            'customername' => ['required', 'string', 'max:255', Rule::unique('bpl.bpl_customers', 'customername')->ignore($this->editingId)],
            'customercountry' => ['required', 'string', 'max:50'],
            'customeraddress' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone_dialcode' => ['nullable', 'string', 'max:8'],
            'customertelephone' => ['nullable', 'string', 'max:30'],
            'port' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => $this->type === 'Export')],
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
        $this->latitude = null;
        $this->longitude = null;
        $this->phone_dialcode = '';
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
        $this->latitude = $c->latitude;
        $this->longitude = $c->longitude;
        $this->phone_dialcode = (string) $c->phone_dialcode;
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

        // Local customers ship no export port.
        if (($data['type'] ?? '') !== 'Export') {
            $data['port'] = '';
        }

        BplCustomer::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Customer updated.' : 'Customer added.');
    }
}
