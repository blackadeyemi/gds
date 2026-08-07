<?php

namespace Modules\Bil\Livewire\Machines;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bil\Models\ConversionSetup as ConversionSetupModel;
use Modules\Bil\Models\ConversionSetupHistory;
use Modules\Bil\Models\FinishedGoodsProduct;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\MachineLine;

/**
 * BIL → Machines → Conversion Setup. Rebuild of the legacy
 * factory_pre_production.php: one row per line saying which finished-goods
 * product it is currently set up to convert, and how many bundles the run is
 * targeting.
 *
 * Changing a line's product is a changeover, so every save also appends to
 * conversion_setup_history — the same contract the legacy screen honours, and
 * what the Conversion History report reads.
 *
 * The product is stored by NAME (`productname`), not id: that is what the
 * legacy consumption screen and the production log match on, so a rebuild that
 * switched to ids would silently break them. The picker is fed from the
 * finished-goods master, so the names stay real.
 */
#[Title('Conversion Setup')]
class ConversionSetup extends DataGrid
{
    public ?int $line_id = null;
    public string $productname = '';
    public string $bundles = '0';

    public function pageKey(): string { return 'bil.machines.conversion-setup'; }
    public function pageLabel(): string { return 'Conversion Setup'; }
    public function pageSubtitle(): string { return 'What each line is currently set up to convert, and the bundle target for the run.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.conversion-setup'; }
    public function defaultSort(): array { return ['linename', 'asc']; }
    public function modalSize(): string { return '560px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Conversion Setup',
                'type' => 'table',
                'columns' => [
                    ['Line', 'linename'],
                    ['Product', 'productname', fn ($r) => $this->productCell($r)],
                    ['Bundle Target', 'bundles'],
                    ['Set By', 'username'],
                    ['Changed', 'timestamp', fn ($r) => $this->when($r)],
                ],
                'query' => fn () => ConversionSetupModel::query()
                    ->select('conversion_setup.*', 'conversion_setup.id as id'),
                'searchable' => ['linename', 'productname', 'username'],
                'sortable' => ['linename', 'productname', 'bundles', 'username', 'timestamp'],
            ],
            'running' => [
                'label' => 'Running only',
                'type' => 'table',
                'columns' => [
                    ['Line', 'linename'],
                    ['Product', 'productname'],
                    ['Bundle Target', 'bundles'],
                    ['Set By', 'username'],
                    ['Changed', 'timestamp', fn ($r) => $this->when($r)],
                ],
                // Idle lines carry the literal "None" the legacy screen writes.
                'query' => fn () => ConversionSetupModel::query()
                    ->select('conversion_setup.*', 'conversion_setup.id as id')
                    ->whereNotNull('productname')
                    ->where('productname', '<>', '')
                    ->where('productname', '<>', ConversionSetupModel::IDLE),
                'searchable' => ['linename', 'productname', 'username'],
                'sortable' => ['linename', 'productname', 'bundles', 'username', 'timestamp'],
            ],
        ];
    }

    /** Idle lines read as a muted "Idle" rather than the literal "None". */
    protected function productCell($row): string
    {
        $name = trim((string) $row->productname);

        return ($name === '' || $name === ConversionSetupModel::IDLE)
            ? '<span class="text-muted">Idle</span>'
            : e($name);
    }

    protected function when($row): string
    {
        return $row->timestamp ? e($row->timestamp->format('d/M/Y H:i')) : '—';
    }

    /* ---------------- Options ---------------- */

    /**
     * Lines that can be set up: any line not already holding a setup row, plus
     * the one being edited.
     *
     * Sub-lines are deliberately included. The actual converting machines mostly
     * ARE sub-lines — 9 of the 17 setups point at one (OMET 1-5, FJ 1/2, Fabio
     * Perini, REW 12) — while several top-level lines are site equipment
     * (compressors, lifters, elevator) that never converts anything. Filtering
     * to top-level would hide the real machines and leave mostly the wrong ones.
     * Sub-lines are prefixed so the two levels still read apart.
     */
    #[Computed]
    public function lines()
    {
        $taken = ConversionSetupModel::query()
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->pluck('line_id')->filter()->all();

        return MachineLine::query()->whereNotIn('id', $taken)->treeOrder()->get()
            ->map(fn ($l) => [
                'id' => (string) $l->id,
                'label' => ($l->parent_id ? '— ' : '') . $l->name,
            ]);
    }

    /** Finished-goods products, the vocabulary for what a line converts. */
    #[Computed]
    public function products()
    {
        return FinishedGoodsProduct::query()->active()
            ->orderBy('productname')
            ->get(['productid', 'productname', 'productcode'])
            ->map(fn ($p) => [
                'name' => $p->productname,
                'label' => $p->productname . ' (' . $p->productcode . ')',
            ]);
    }

    /* ---------------- Form ---------------- */

    protected function rules(): array
    {
        return [
            'line_id' => [
                'required', 'integer', 'exists:core.machine_lines,id',
                // One setup per line; the table enforces it on linename too.
                Rule::unique('bil.conversion_setup', 'line_id')->ignore($this->editingId),
            ],
            // Free-form so an idle line can be cleared, but a named product must
            // be one that exists — the legacy consumption screen matches on it.
            'productname' => ['nullable', 'string', 'max:200'],
            'bundles' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['line_id' => 'line', 'productname' => 'product', 'bundles' => 'bundle target'];
    }

    protected function resetForm(): void
    {
        $this->line_id = null;
        $this->productname = '';
        $this->bundles = '0';
    }

    protected function fillForm(int $id): void
    {
        $row = ConversionSetupModel::findOrFail($id);
        $this->line_id = $row->line_id;
        $this->productname = $row->isIdle() ? '' : (string) $row->productname;
        $this->bundles = (string) $row->bundles;
    }

    protected function findRow(int $id)
    {
        return ConversionSetupModel::find($id);
    }

    protected function performDelete(int $id): void
    {
        ConversionSetupModel::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();

        $line = MachineLine::findOrFail($data['line_id']);
        // Blank means the line is idle; legacy records that as "None".
        $product = trim($data['productname']) ?: ConversionSetupModel::IDLE;
        $bundles = (int) $data['bundles'];
        $user = (string) (auth()->user()?->username ?? auth()->user()?->name ?? 'gds');

        DB::connection('bil')->transaction(function () use ($line, $product, $bundles, $user) {
            $row = ConversionSetupModel::updateOrCreate(
                ['id' => $this->editingId],
                [
                    'line_id' => $line->id,
                    'linename' => $line->name,
                    'productname' => $product,
                    'bundles' => $bundles,
                    'username' => $user,
                    'timestamp' => now(),
                ]
            );

            // A changeover is only worth logging when something actually moved.
            if ($row->wasRecentlyCreated || $row->wasChanged(['productname', 'bundles'])) {
                ConversionSetupHistory::create([
                    'linename' => $line->name,
                    'productname' => $product,
                    'quantity' => $bundles,
                    'username' => $user,
                    'date_modified' => now(),
                ]);
            }
        });

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Conversion setup updated.' : 'Conversion setup added.');
    }
}
