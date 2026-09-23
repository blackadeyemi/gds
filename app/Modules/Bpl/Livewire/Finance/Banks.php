<?php

namespace Modules\Bpl\Livewire\Finance;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplBank;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Finance → Banks. Rebuild of legacy `bpl_banks.php` (handler
 * `Bil\Bpl\Banks`), which inserted whatever JSON the page posted and deleted a
 * bank on sight — including one a proforma's account still prints.
 *
 * Changes from legacy:
 *  - **Deleting is guarded** on `bpl_accounts` (every role, deleted accounts
 *    included — see BplBank).
 *  - **The account number is checked for duplicates** in words; legacy let the
 *    UNIQUE index reject it silently. An empty number is stored as NULL so
 *    number-less routing banks do not collide.
 *  - **Sort code is text**: legacy's `type="number"` input dropped leading
 *    zeros (Access Bank's is 044150084).
 */
#[Title('BPL Banks')]
class Banks extends DataGrid
{
    public string $name = '';
    public string $number = '';
    public string $sortcode = '';
    public string $swiftcode = '';
    public string $address = '';

    public function pageKey(): string { return 'bpl.finance.banks'; }
    public function pageLabel(): string { return 'BPL Banks'; }
    public function pageSubtitle(): string { return 'Bank accounts BPL is paid into, and the banks payments route through. Accounts name them.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.bank'; }
    public function defaultSort(): array { return ['name', 'asc']; }
    public function modalSize(): string { return '560px'; }

    public function views(): array
    {
        $mono = fn (string $field) => fn ($r) => $r->{$field} !== null && $r->{$field} !== ''
            ? '<span class="mono">' . e($r->{$field}) . '</span>'
            : '<span class="text-muted">—</span>';

        $columns = [
            ['Bank', 'name'],
            ['Account Number', 'number', $mono('number')],
            ['Sort Code', 'sortcode', $mono('sortcode')],
            ['Swift Code', 'swiftcode', $mono('swiftcode')],
            ['Address', 'address', fn ($r) => $r->address ? e($r->address) : '<span class="text-muted">—</span>'],
            ['Accounts', 'accounts_count', fn ($r) => number_format((int) $r->accounts_count)],
        ];
        $query = fn () => BplBank::query()->select('bpl_banks.*')
            ->selectRaw(BplBank::accountCountSql() . ' as accounts_count');
        $searchable = ['name', 'number', 'sortcode', 'swiftcode', 'address'];
        $sortable = ['name', 'number', 'sortcode', 'swiftcode', 'accounts_count'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'query' => $query,
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            // No account names these: the only deletable rows there are.
            'unused' => [
                'label' => 'Not on any account',
                'type' => 'table',
                'columns' => array_slice($columns, 0, 5),
                'query' => fn () => $query()->whereRaw(BplBank::accountCountSql() . ' = 0'),
                'searchable' => $searchable,
                'sortable' => array_slice($sortable, 0, 4),
            ],
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'number' => ['nullable', 'string', 'max:100',
                Rule::unique('bpl.bpl_banks', 'number')->ignore($this->editingId)],
            'sortcode' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 -]*$/'],
            'swiftcode' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]*$/'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function messages(): array
    {
        return [
            'number.unique' => 'Another bank already has this account number.',
            'sortcode.regex' => 'Digits only (spaces and dashes allowed).',
            'swiftcode.regex' => 'Letters and digits only.',
        ];
    }

    protected function validationAttributes(): array
    {
        return ['name' => 'bank name', 'number' => 'account number', 'sortcode' => 'sort code', 'swiftcode' => 'swift code'];
    }

    protected function resetForm(): void
    {
        $this->name = $this->number = $this->sortcode = $this->swiftcode = $this->address = '';
    }

    protected function fillForm(int $id): void
    {
        $b = BplBank::findOrFail($id);
        $this->name = (string) $b->name;
        $this->number = (string) $b->number;
        $this->sortcode = (string) $b->sortcode;
        $this->swiftcode = (string) $b->swiftcode;
        $this->address = (string) $b->address;
    }

    protected function findRow(int $id)
    {
        return BplBank::query()->select('bpl_banks.*')
            ->selectRaw(BplBank::accountCountSql() . ' as accounts_count')->find($id);
    }

    /** A bank any account names — deleted accounts too — stays. */
    public function deleteGuard($row): ?string
    {
        $n = (int) ($row->accounts_count ?? 0);

        return $n > 0
            ? 'Named on ' . number_format($n) . ' ' . Str::plural('account', $n) . ' — cannot delete.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        BplBank::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->number = trim($this->number);
        $this->sortcode = trim($this->sortcode);
        $this->swiftcode = strtoupper((string) preg_replace('/\s+/', '', $this->swiftcode));
        $this->address = trim($this->address);

        $data = $this->validate();

        // '' → NULL: the number is UNIQUE, and number-less banks must not collide.
        $data = array_map(fn ($v) => ($v === '' ? null : $v), $data);

        // Without a number, UNIQUE(name, number) cannot catch a repeat (NULLs
        // never collide), so check the name alone.
        $repeat = $data['number'] === null && BplBank::where('name', $data['name'])->whereNull('number')
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists();

        if ($repeat) {
            $this->addError('name', 'A bank with this name and no account number already exists.');

            return;
        }

        BplBank::updateOrCreate(['id' => $this->editingId], $data);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Bank updated.' : 'Bank added.');
    }
}
