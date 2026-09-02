<?php

namespace Modules\Bil\Livewire\Sales;

use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Modules\Bil\Models\SalesTransporter;
use Modules\Core\Livewire\DataGrid;

/**
 * BIL → Sales → Transporters. Rebuild of legacy `sales_transporters.php` — a
 * self-contained page of raw mysqli that inlined its own add/edit/delete
 * modals and interpolated `$_POST` straight into SQL.
 *
 * The haulier list behind Sales Loading: every load names one, and
 * `report_sales_loading_transporter.php` groups by it.
 *
 * WHAT CHANGED FROM LEGACY, DELIBERATELY:
 *
 *  - **A Transporter Code.** Legacy had only the row id, which is not something
 *    anyone can quote on a waybill. Eight digits, system-assigned, never typed
 *    — see SalesTransporter. Shown and searchable here, read-only in the form.
 *  - **Deleting is guarded on loadings.** Legacy deleted on sight, and 141 of
 *    the 143 transporters have carried something — every one of those deletes
 *    would have left loadings pointing at a transporter that no longer exists.
 *    One such orphan is already in the data.
 *  - **The name is unique** (the column always had a UNIQUE index; legacy just
 *    let the insert fail with "Process failed" instead of saying what was wrong).
 */
#[Title('Sales Transporters')]
class Transporters extends DataGrid
{
    public string $transportername = '';

    /** Shown while editing so the code is visible; never written from here. */
    public ?string $transportercode = null;

    public function pageKey(): string { return 'bil.sales.transporters'; }
    public function pageLabel(): string { return 'Sales Transporters'; }
    public function pageSubtitle(): string { return 'The hauliers who move finished goods out. Every sales loading names one.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.sales-transporter'; }
    public function defaultSort(): array { return ['transportername', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Transporter Code', 'transportercode', fn ($r) => $r->transportercode
                        ? '<span class="badge badge-muted mono">' . e($r->transportercode) . '</span>'
                        : '<span class="badge badge-danger">not set</span>'],
                    ['Transporter', 'transportername'],
                    ['Loadings', 'loadings_count', fn ($r) => number_format((int) $r->loadings_count)],
                ],
                'query' => fn () => SalesTransporter::query()->withCount('loadings'),
                'searchable' => ['transportercode', 'transportername'],
                'sortable' => ['transportercode', 'transportername', 'loadings_count'],
            ],
            // The ones nothing has ever been loaded against: either new, or
            // dead entries safe to remove. The only deletable rows there are.
            'unused' => [
                'label' => 'Never used',
                'type' => 'table',
                'columns' => [
                    ['Transporter Code', 'transportercode', fn ($r) => '<span class="badge badge-muted mono">' . e($r->transportercode) . '</span>'],
                    ['Transporter', 'transportername'],
                ],
                'query' => fn () => SalesTransporter::query()->withCount('loadings')->doesntHave('loadings'),
                'searchable' => ['transportercode', 'transportername'],
                'sortable' => ['transportercode', 'transportername'],
            ],
        ];
    }

    protected function rules(): array
    {
        $ignore = $this->editingId ? ',' . $this->editingId : '';

        return [
            // varchar(100), UNIQUE. The unique rule needs the bil connection
            // spelled out — the default connection is core.
            'transportername' => ['required', 'string', 'max:100',
                'unique:bil.sales_transporters,transportername' . $ignore],
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
        $t = SalesTransporter::findOrFail($id);
        $this->transportername = (string) $t->transportername;
        $this->transportercode = $t->transportercode;
    }

    protected function findRow(int $id)
    {
        return SalesTransporter::withCount('loadings')->find($id);
    }

    /** A transporter named on a loading stays: deleting orphans that history. */
    public function deleteGuard($row): ?string
    {
        $n = (int) ($row->loadings_count ?? 0);

        return $n > 0
            ? 'Named on ' . number_format($n) . ' ' . Str::plural('loading', $n) . ' — cannot delete.'
            : null;
    }

    protected function performDelete(int $id): void
    {
        SalesTransporter::whereKey($id)->delete();
    }

    public function save(): void
    {
        $this->transportername = trim($this->transportername);

        $data = $this->validate();

        // The code is assigned by the model on create and never editable, so it
        // is deliberately not in $data — an edit cannot change it, and a create
        // does not need to supply it.
        $t = SalesTransporter::updateOrCreate(['id' => $this->editingId], $data);

        // An older row created by the legacy screen has no code; give it one
        // the first time gds saves it rather than leaving a hole in the list.
        if (! $t->transportercode) {
            $t->forceFill(['transportercode' => SalesTransporter::generateCode()])->save();
        }

        $this->showModal = false;
        session()->flash('ok', $this->editingId
            ? 'Transporter updated.'
            : 'Transporter added — code ' . $t->transportercode . '.');
    }
}
