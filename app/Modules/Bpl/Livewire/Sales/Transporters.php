<?php

namespace Modules\Bpl\Livewire\Sales;

use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplTransporter;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Sales → Transporters. Rebuild of legacy `bpl_transporters.php` — a
 * self-contained page that interpolated `$_POST` straight into its INSERT,
 * UPDATE and DELETE.
 *
 * Built to match BIL → Sales → Transporters, with the same three deliberate
 * changes from legacy:
 *
 *  - **A Transporter Code** — eight digits, system-assigned, never typed. See
 *    BplTransporter. Shown and searchable, read-only in the form.
 *  - **Deleting is guarded** — here on waybill payments (`bpl_waybill_payment`
 *    names a transporter on every payment; legacy deleted on sight and would
 *    have orphaned them).
 *  - **The name is unique** — the column always had a UNIQUE index; legacy let
 *    the insert fail silently instead of saying so.
 */
#[Title('BPL Transporters')]
class Transporters extends DataGrid
{
    public string $transportername = '';

    /** Shown while editing so the code is visible; never written from here. */
    public ?string $transportercode = null;

    public function pageKey(): string { return 'bpl.sales.transporters'; }
    public function pageLabel(): string { return 'BPL Transporters'; }
    public function pageSubtitle(): string { return 'The hauliers who carry BPL rolls out. Waybill payments name one.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.transporter'; }
    public function defaultSort(): array { return ['transportername', 'asc']; }

    public function views(): array
    {
        $code = fn ($r) => $r->transportercode
            ? '<span class="badge badge-muted mono">' . e($r->transportercode) . '</span>'
            : '<span class="badge badge-danger">not set</span>';

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Transporter Code', 'transportercode', $code],
                    ['Transporter', 'transportername'],
                    ['Waybill Payments', 'waybill_payments_count', fn ($r) => number_format((int) $r->waybill_payments_count)],
                ],
                'query' => fn () => BplTransporter::query()->withCount('waybillPayments'),
                'searchable' => ['transportercode', 'transportername'],
                'sortable' => ['transportercode', 'transportername', 'waybill_payments_count'],
            ],
            // Nothing has ever been paid against these: new, or dead entries
            // safe to remove. The only deletable rows there are.
            'unused' => [
                'label' => 'Never used',
                'type' => 'table',
                'columns' => [
                    ['Transporter Code', 'transportercode', $code],
                    ['Transporter', 'transportername'],
                ],
                'query' => fn () => BplTransporter::query()->withCount('waybillPayments')->doesntHave('waybillPayments'),
                'searchable' => ['transportercode', 'transportername'],
                'sortable' => ['transportercode', 'transportername'],
            ],
        ];
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            // varchar(100), UNIQUE. The connection has to be spelled out — the
            // default connection is core.
            'transportername' => ['required', 'string', 'max:100',
                'unique:bpl.bpl_transporters,transportername' . $ignore],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['transportername' => 'transporter name'];
    }

    protected function resetForm(): void
    {
        $this->transportername = '';
        $this->transportercode = null;   // minted on save
    }

    protected function fillForm(int $id): void
    {
        $t = BplTransporter::findOrFail($id);
        $this->transportername = (string) $t->transportername;
        $this->transportercode = $t->transportercode;
    }

    protected function findRow(int $id)
    {
        return BplTransporter::withCount('waybillPayments')->find($id);
    }

    /** A transporter named on a waybill payment stays: deleting orphans it. */
    public function deleteGuard($row): ?string
    {
        $n = (int) ($row->waybill_payments_count ?? 0);

        return $n > 0
            ? 'Named on ' . number_format($n) . ' waybill ' . Str::plural('payment', $n) . ' — cannot delete.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        BplTransporter::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->transportername = trim($this->transportername);

        $data = $this->validate();

        // The code is never in $data: the model mints it on create and an edit
        // must not be able to change it.
        $t = BplTransporter::updateOrCreate(['id' => $this->editingId], $data);

        // A row the legacy screen created has no code; give it one the first
        // time gds saves it.
        if (! $t->transportercode) {
            $t->forceFill(['transportercode' => BplTransporter::generateCode()])->save();
        }

        $this->showModal = false;
        session()->flash('ok', $this->editingId
            ? 'Transporter updated.'
            : 'Transporter added — code ' . $t->transportercode . '.');
    }
}
