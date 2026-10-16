<?php

namespace Modules\Bpl\Livewire\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplPaymentTerm;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Finance → Payment Terms. Rebuild of legacy `bpl_payment_terms.php`
 * (handler `Bil\Bpl\PaymentTerms`) — the terms a proforma is raised on, each
 * with the number of days that sets when payment falls due.
 *
 * Changes from legacy:
 *  - **A repeated term is refused in words**, deleted terms included:
 *    UNIQUE(payment_terms) spans them, so legacy's insert simply failed. The
 *    deleted twin is offered for restoring instead.
 *  - **Deleted terms are visible and restorable** — legacy's soft delete hid
 *    them for good, and a proforma still prints one (term 8, deleted 2021).
 *  - **Days must be at least 1**, which only the browser enforced before.
 *
 * Deleting stays a soft delete and is allowed on a term a proforma names: the
 * proforma keeps printing it, it just cannot be chosen for a new one.
 */
#[Title('BPL Payment Terms')]
class PaymentTerms extends DataGrid
{
    public string $payment_terms = '';
    public ?int $days = null;

    public function pageKey(): string { return 'bpl.finance.payment-terms'; }
    public function pageLabel(): string { return 'BPL Payment Terms'; }
    public function pageSubtitle(): string { return 'How and when a customer pays. A proforma is raised on one; the days set when it falls due.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.payment-term'; }
    public function defaultSort(): array { return ['payment_terms', 'asc']; }
    public function modalSize(): string { return '480px'; }

    protected function baseQuery()
    {
        return BplPaymentTerm::query()
            ->select('bpl_payment_terms.*')
            ->selectRaw('(SELECT COUNT(*) FROM `bpl_proforma` p WHERE p.`payment_term_id` = `bpl_payment_terms`.`id`'
                . ' AND p.`deleted_at` IS NULL) as proformas_count');
    }

    public function views(): array
    {
        $columns = [
            ['Payment Term', 'payment_terms'],
            ['Days', 'days', fn ($r) => number_format((int) $r->days) . ' ' . Str::plural('day', (int) $r->days)],
            ['Proformas', 'proformas_count', fn ($r) => number_format((int) $r->proformas_count)],
        ];
        $searchable = ['payment_terms'];
        $sortable = ['payment_terms', 'days', 'proformas_count'];

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
            . ' wire:click="restore(' . (int) $row->id . ')" title="Restore this payment term">'
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

        $t = BplPaymentTerm::onlyTrashed()->find($id);
        if ($t) {
            $t->restore();
            session()->flash('ok', 'Payment term "' . $t->payment_terms . '" restored.');
        }
    }

    /* ---------------- Form ---------------- */

    protected function rules(): array
    {
        return [
            'payment_terms' => ['required', 'string', 'max:150'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }

    protected function messages(): array
    {
        return [
            'days.min' => 'Payment cannot fall due in fewer than one day — cash in advance is 1.',
        ];
    }

    protected function validationAttributes(): array
    {
        return ['payment_terms' => 'payment term', 'days' => 'number of days'];
    }

    protected function resetForm(): void
    {
        $this->payment_terms = '';
        $this->days = null;
    }

    protected function fillForm(int $id): void
    {
        $t = BplPaymentTerm::withTrashed()->findOrFail($id);
        $this->payment_terms = (string) $t->payment_terms;
        $this->days = (int) $t->days;
    }

    protected function findRow(int $id)
    {
        return BplPaymentTerm::withTrashed()->find($id);
    }

    public function deleteGuard($row): ?string
    {
        return $row->deleted_at ? 'Already deleted.' : null;
    }

    protected function performDelete(int $id): void
    {
        BplPaymentTerm::whereKey($id)->first()?->delete();
    }

    public function save(): void
    {
        $this->payment_terms = trim((string) preg_replace('/\s+/', ' ', $this->payment_terms));

        $data = $this->validate();

        // UNIQUE(payment_terms) — deleted rows included, and MySQL compares
        // case-insensitively here, so "LC AT SIGHT" clashes with "LC at Sight".
        $clash = BplPaymentTerm::withTrashed()->where('payment_terms', $data['payment_terms'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->first();

        if ($clash) {
            $this->addError('payment_terms', $clash->trashed()
                ? 'This term already exists but was deleted — restore it from the Deleted view instead.'
                : 'This payment term already exists.');

            return;
        }

        BplPaymentTerm::withTrashed()->updateOrCreate(['id' => $this->editingId], $data);

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Payment term updated.' : 'Payment term added.');
    }
}
