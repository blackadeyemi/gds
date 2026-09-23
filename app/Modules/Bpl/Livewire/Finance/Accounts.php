<?php

namespace Modules\Bpl\Livewire\Finance;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplAccount;
use Modules\Bpl\Models\BplBank;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Finance → Accounts. Rebuild of legacy `bpl_accounts.php` (handler
 * `Bil\Bpl\Accounts`): the receiving accounts a proforma tells the customer
 * to pay into — beneficiary bank, optional intermediary and correspondent
 * banks, a further-credit account and a currency.
 *
 * Changes from legacy:
 *  - **Bank + currency is checked for duplicates in words**, deleted accounts
 *    included: UNIQUE(beneficiary, currency_id) spans them, so legacy's insert
 *    simply failed. A deleted duplicate is offered for restoring instead.
 *  - **Deleted accounts are visible and restorable** (legacy's soft delete
 *    hid them for good).
 *  - **A bank that no longer exists is flagged** — account 28 names
 *    intermediary bank 61, which was deleted from under it.
 *  - The routing banks may not repeat the beneficiary.
 *
 * Deleting stays a soft delete and is allowed on an account a proforma names:
 * the proforma keeps printing it, it just cannot be chosen for a new one.
 */
#[Title('BPL Accounts')]
class Accounts extends DataGrid
{
    public string $account = '';
    public ?int $beneficiary = null;
    public ?int $intermediary = null;
    public ?int $correspondent = null;
    public string $further_acc = '';
    public ?int $currency_id = null;

    /** Set when the account being edited names a bank that no longer exists. */
    public string $missingNote = '';

    public function pageKey(): string { return 'bpl.finance.accounts'; }
    public function pageLabel(): string { return 'BPL Accounts'; }
    public function pageSubtitle(): string { return 'The accounts customers are told to pay into. A proforma names one; its invoice prints the banks.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.account'; }
    public function defaultSort(): array { return ['account', 'asc']; }
    public function modalSize(): string { return '600px'; }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function banks()
    {
        return BplBank::orderBy('name')->orderBy('number')->get(['id', 'name', 'number']);
    }

    #[Computed]
    public function currencies()
    {
        return DB::connection('bpl')->table('currencies')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /* ---------------- The grid ---------------- */

    protected function baseQuery()
    {
        return BplAccount::query()
            ->leftJoin('bpl_banks as b', 'b.id', '=', 'bpl_accounts.beneficiary')
            ->leftJoin('bpl_banks as i', 'i.id', '=', 'bpl_accounts.intermediary')
            ->leftJoin('bpl_banks as c', 'c.id', '=', 'bpl_accounts.correspondent')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'bpl_accounts.currency_id')
            ->select('bpl_accounts.*')
            ->selectRaw('b.name as bank, b.number as bank_number, i.name as intermediary_name, i.number as intermediary_number,'
                . ' c.name as correspondent_name, c.number as correspondent_number, cur.code as currency')
            ->selectRaw('(SELECT COUNT(*) FROM `bpl_proforma` p WHERE p.`account_id` = `bpl_accounts`.`id` AND p.`deleted_at` IS NULL) as proformas_count');
    }

    /** A bank cell: name + number, "—" when none, a red flag when the id points nowhere. */
    protected static function bankCell(?int $id, ?string $name, ?string $number): string
    {
        if (! $id) {
            return '<span class="text-muted">—</span>';
        }
        if ($name === null) {
            return '<span class="badge badge-danger" title="This bank has been deleted">missing bank #' . $id . '</span>';
        }

        return e($name) . ($number ? '<div class="text-sm text-muted mono">' . e($number) . '</div>' : '');
    }

    public function views(): array
    {
        $columns = [
            ['Account Name', 'account'],
            ['Beneficiary Bank', 'bank', fn ($r) => self::bankCell($r->beneficiary, $r->bank, $r->bank_number)],
            ['Intermediary', 'intermediary_name', fn ($r) => self::bankCell($r->intermediary, $r->intermediary_name, $r->intermediary_number)],
            ['Correspondent', 'correspondent_name', fn ($r) => self::bankCell($r->correspondent, $r->correspondent_name, $r->correspondent_number)],
            ['Further Credit Account', 'further_acc', fn ($r) => $r->further_acc ? '<span class="mono">' . e($r->further_acc) . '</span>' : '<span class="text-muted">—</span>'],
            ['Currency', 'currency', fn ($r) => $r->currency ? e($r->currency) : '<span class="text-muted">—</span>'],
            ['Proformas', 'proformas_count', fn ($r) => number_format((int) $r->proformas_count)],
        ];
        $searchable = ['bpl_accounts.account', 'b.name', 'b.number', 'i.name', 'c.name', 'bpl_accounts.further_acc', 'cur.code'];
        $sortable = ['account', 'bank', 'intermediary_name', 'correspondent_name', 'further_acc', 'currency', 'proformas_count'];

        return [
            'default' => [
                'label' => 'Active',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->baseQuery(),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            'deleted' => [
                'label' => 'Deleted',
                'type' => 'table',
                'columns' => array_merge($columns, [
                    ['Deleted', 'deleted_at', fn ($r) => e(\Illuminate\Support\Carbon::parse($r->deleted_at)->format('d/m/Y'))],
                ]),
                'query' => fn () => $this->baseQuery()->onlyTrashed(),
                'searchable' => $searchable,
                'sortable' => array_merge($sortable, ['deleted_at']),
            ],
        ];
    }

    /* ---------------- Restore ---------------- */

    public function hasLeadingRowActions(): bool
    {
        return $this->view === 'deleted' && $this->mayDo('edit');
    }

    public function leadingRowActions($row): string
    {
        if ($this->view !== 'deleted' || ! $this->mayDo('edit')) {
            return '';
        }

        return '<button type="button" class="btn btn-ghost btn-icon btn-sm"'
            . ' wire:click="restore(' . (int) $row->id . ')" title="Restore this account">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>'
            . '</svg></button>';
    }

    public function restore(int $id): void
    {
        if (! $this->mayDo('edit')) {
            return;
        }

        $a = BplAccount::onlyTrashed()->find($id);
        if ($a) {
            $a->restore();
            session()->flash('ok', 'Account "' . $a->account . '" restored.');
        }
    }

    /* ---------------- Form ---------------- */

    protected function rules(): array
    {
        $bank = ['nullable', 'integer', 'exists:bpl.bpl_banks,id'];

        return [
            'account' => ['required', 'string', 'max:50'],
            'beneficiary' => ['required', 'integer', 'exists:bpl.bpl_banks,id'],
            'intermediary' => array_merge($bank, ['different:beneficiary']),
            'correspondent' => array_merge($bank, ['different:beneficiary']),
            'further_acc' => ['nullable', 'string', 'max:100'],
            'currency_id' => ['nullable', 'integer', 'exists:bpl.currencies,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'intermediary.different' => 'The intermediary cannot be the beneficiary bank itself.',
            'correspondent.different' => 'The correspondent cannot be the beneficiary bank itself.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'account' => 'account name',
            'beneficiary' => 'beneficiary bank',
            'intermediary' => 'intermediary bank',
            'correspondent' => 'correspondent bank',
            'further_acc' => 'further credit account',
            'currency_id' => 'currency',
        ];
    }

    protected function resetForm(): void
    {
        $this->account = '';
        $this->beneficiary = $this->intermediary = $this->correspondent = $this->currency_id = null;
        $this->further_acc = '';
        $this->missingNote = '';
    }

    protected function fillForm(int $id): void
    {
        $a = BplAccount::withTrashed()->findOrFail($id);
        $this->account = (string) $a->account;
        $this->beneficiary = $a->beneficiary ? (int) $a->beneficiary : null;
        $this->intermediary = $a->intermediary ? (int) $a->intermediary : null;
        $this->correspondent = $a->correspondent ? (int) $a->correspondent : null;
        $this->further_acc = (string) $a->further_acc;
        $this->currency_id = $a->currency_id ? (int) $a->currency_id : null;

        // A routing bank deleted from under the account (legacy deleted banks
        // unguarded) cannot be shown in the picker; clear it and say so,
        // rather than failing validation on a value nobody can see.
        $this->missingNote = '';
        $existing = BplBank::whereIn('id', array_filter([$this->beneficiary, $this->intermediary, $this->correspondent]))->pluck('id')->all();
        foreach (['beneficiary', 'intermediary', 'correspondent'] as $role) {
            if ($this->{$role} && ! in_array($this->{$role}, $existing)) {
                $this->missingNote .= ucfirst($role) . ' bank #' . $this->{$role} . ' no longer exists and has been cleared. ';
                $this->{$role} = null;
            }
        }
        $this->missingNote = trim($this->missingNote);
    }

    protected function findRow(int $id)
    {
        return BplAccount::withTrashed()->find($id);
    }

    public function deleteGuard($row): ?string
    {
        return $row->deleted_at ? 'Already deleted.' : null;
    }

    protected function performDelete(int $id): void
    {
        BplAccount::whereKey($id)->first()?->delete();
    }

    public function save(): void
    {
        $this->account = trim($this->account);
        $this->further_acc = trim($this->further_acc);
        foreach (['beneficiary', 'intermediary', 'correspondent', 'currency_id'] as $f) {
            $this->{$f} = $this->{$f} ?: null;
        }

        $data = $this->validate();
        $data['further_acc'] = $data['further_acc'] === '' ? null : $data['further_acc'];

        // UNIQUE(beneficiary, currency_id) — deleted rows included. NULL
        // currencies never collide, so only a set currency is checked.
        if ($data['currency_id'] !== null) {
            $clash = BplAccount::withTrashed()
                ->where('beneficiary', $data['beneficiary'])->where('currency_id', $data['currency_id'])
                ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
                ->first();

            if ($clash) {
                $this->addError('currency_id', $clash->trashed()
                    ? 'A deleted account ("' . $clash->account . '") already uses this bank and currency — restore it from the Deleted view instead.'
                    : 'Account "' . $clash->account . '" already uses this bank and currency.');

                return;
            }
        }

        $a = BplAccount::withTrashed()->updateOrCreate(['id' => $this->editingId], $data);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Account updated.' : 'Account "' . $a->account . '" added.');
    }
}
