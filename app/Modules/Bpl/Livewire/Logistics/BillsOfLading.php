<?php

namespace Modules\Bpl\Livewire\Logistics;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use Modules\Bpl\Models\BplBillOfLading;
use Modules\Bpl\Support\BillOfLadingDocs;
use Modules\Bpl\Support\PackingList as PackingListSupport;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Logistics → Bills of Lading. Rebuild of legacy
 * `bpl_bill_of_lading.php` (handler `Bil\Bpl\BillOfLading`): the carrier's
 * receipt for a shipment, raised against the packing list that went out.
 *
 * One per packing list — and once it exists the packing list is frozen, which
 * is the rule both apps already enforce.
 *
 * The scanned document is a file in a folder shared with the legacy app; only
 * its name is stored. See Support\BillOfLadingDocs.
 *
 * Changes from legacy:
 *  - **A replacement scan is only written once the new one is saved.** The
 *    legacy deleted the old file first and uploaded the new one inside the
 *    same transaction — a failed upload took the original with it, and the
 *    file system does not roll back.
 *  - **Keeping the existing scan is allowed.** The legacy form demanded a file
 *    on every edit, so correcting a date meant re-uploading the document.
 *  - **The B/L number must be unique**, and dates have to make sense: shipped
 *    before issued, arrival after shipped.
 *  - **A missing scan is flagged in the list** rather than breaking the page:
 *    the legacy read every file to build the list and fell over when one had
 *    been moved.
 */
#[Title('BPL Bills of Lading')]
class BillsOfLading extends DataGrid
{
    use WithFileUploads;

    public ?int $packing_id = null;
    public string $number = '';
    public string $issuedIso = '';
    public string $shippedIso = '';
    public string $arrivalIso = '';

    /** The new scan, if one is being uploaded. */
    public $document = null;

    /** The filename already on the record, shown while editing. */
    public string $currentDoc = '';

    public function pageKey(): string { return 'bpl.logistics.bills-of-lading'; }
    public function pageLabel(): string { return 'BPL Bills of Lading'; }
    public function pageSubtitle(): string { return 'The carrier\'s receipt for each shipment, with its scanned document.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.bill-of-lading'; }
    public function defaultSort(): array { return ['bpl_bill_of_lading.issued_date', 'desc']; }
    public function modalSize(): string { return '640px'; }

    /* ---------------- The grid ---------------- */

    protected function baseQuery()
    {
        return BplBillOfLading::query()
            ->leftJoin('bpl_packing_list as pl', 'pl.id', '=', 'bpl_bill_of_lading.packing_id')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->select('bpl_bill_of_lading.*')
            ->selectRaw('pl.number as packing_number, c.id as customer_id, c.customerlabel, c.customername')
            ->selectRaw('JSON_LENGTH(pl.containers) as containers');
    }

    public function views(): array
    {
        $date = fn (string $field) => fn ($r) => $r->{$field} ? e((string) $r->{$field}) : '<span class="text-muted">—</span>';

        $columns = [
            ['B/L Number', 'bpl_bill_of_lading.number', fn ($r) => '<span class="mono">' . e((string) $r->number) . '</span>'],
            ['Packing List', 'packing_number', fn ($r) => '<span class="mono">'
                . e(PackingListSupport::label($r->packing_number, (int) $r->customer_id, $r->customerlabel)) . '</span>'],
            ['Customer', 'customername', fn ($r) => e((string) ($r->customername ?: '—'))],
            ['Containers', 'containers', fn ($r) => number_format((int) $r->containers)],
            ['Shipped', 'shipped_date', $date('shipped_date')],
            ['Issued', 'bpl_bill_of_lading.issued_date', $date('issued_date')],
            ['Arrived', 'arrival_date', function ($r) {
                return trim((string) $r->arrival_date) === ''
                    ? '<span class="badge badge-warning">in transit</span>'
                    : e((string) $r->arrival_date);
            }],
            ['Document', 'doc', function ($r) {
                if (! BillOfLadingDocs::exists($r->doc)) {
                    return '<span class="badge badge-danger" title="' . e((string) $r->doc) . ' is not in the documents folder">missing</span>';
                }

                return '<a href="' . e(route('bpl.logistics.bills-of-lading.document', $r->id)) . '" target="_blank"'
                    . ' rel="noopener" class="mono text-sm">' . e((string) $r->doc) . '</a>';
            }],
        ];

        $searchable = ['bpl_bill_of_lading.number', 'pl.number', 'c.customername'];
        $sortable = ['bpl_bill_of_lading.number', 'packing_number', 'customername', 'shipped_date',
            'bpl_bill_of_lading.issued_date', 'arrival_date'];

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->baseQuery(),
                'count' => fn () => BplBillOfLading::query(),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
            // Shipped, not yet arrived: what the shipping desk chases.
            'transit' => [
                'label' => 'In transit',
                'type' => 'table',
                'columns' => $columns,
                'query' => fn () => $this->baseQuery()->where(fn ($q) => $q
                    ->whereNull('bpl_bill_of_lading.arrival_date')->orWhere('bpl_bill_of_lading.arrival_date', '')),
                'searchable' => $searchable,
                'sortable' => $sortable,
            ],
        ];
    }

    /* ---------------- Options ---------------- */

    /** Packing lists with no bill of lading yet, plus the one being edited. */
    #[Computed]
    public function openPackingLists()
    {
        $rows = DB::connection('bpl')->table('bpl_packing_list as pl')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_bill_of_lading as bl')
                ->whereColumn('bl.packing_id', 'pl.id'))
            ->when($this->editingId, fn ($q) => $q->orWhereExists(fn ($w) => $w->selectRaw('1')
                ->from('bpl_bill_of_lading as bl')->whereColumn('bl.packing_id', 'pl.id')
                ->where('bl.id', $this->editingId)))
            ->orderByDesc('pl.id')
            ->limit(300)
            ->get(['pl.id', 'pl.number', 'pl.date', 'c.id as customer_id', 'c.customerlabel', 'c.customername']);

        return $rows->map(fn ($r) => (object) [
            'id' => (int) $r->id,
            'label' => PackingListSupport::label($r->number, (int) $r->customer_id, $r->customerlabel),
            'customer' => (string) ($r->customername ?? ''),
            'date' => (string) $r->date,
        ]);
    }

    /* ---------------- Form ---------------- */

    protected function resetForm(): void
    {
        $this->packing_id = null;
        $this->number = '';
        $this->issuedIso = now()->format('Y-m-d');
        $this->shippedIso = '';
        $this->arrivalIso = '';
        $this->document = null;
        $this->currentDoc = '';
    }

    protected function fillForm(int $id): void
    {
        $bl = BplBillOfLading::findOrFail($id);

        $this->packing_id = (int) $bl->packing_id;
        $this->number = (string) $bl->number;
        $this->issuedIso = $this->toIso($bl->issued_date);
        $this->shippedIso = $this->toIso($bl->shipped_date);
        $this->arrivalIso = $this->toIso($bl->arrival_date);
        $this->document = null;
        $this->currentDoc = (string) $bl->doc;
    }

    protected function toIso(?string $legacy): string
    {
        $legacy = trim((string) $legacy);

        return $legacy === '' ? '' : str_replace('/', '-', $legacy);
    }

    protected function findRow(int $id)
    {
        return $this->baseQuery()->where('bpl_bill_of_lading.id', $id)->first();
    }

    protected function rules(): array
    {
        return [
            'packing_id' => ['required', 'integer', 'exists:bpl.bpl_packing_list,id'],
            'number' => ['required', 'string', 'max:100'],
            'issuedIso' => ['required', 'date'],
            'shippedIso' => ['required', 'date'],
            'arrivalIso' => ['nullable', 'date'],
            'document' => [$this->editingId ? 'nullable' : 'required', 'file',
                'mimes:' . implode(',', BillOfLadingDocs::EXTENSIONS), 'max:' . BillOfLadingDocs::MAX_KB],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'packing_id' => 'packing list', 'number' => 'B/L number', 'issuedIso' => 'issued date',
            'shippedIso' => 'shipped date', 'arrivalIso' => 'arrival date', 'document' => 'scanned document',
        ];
    }

    public function save(): void
    {
        $this->number = trim($this->number);

        $data = $this->validate();

        $clash = BplBillOfLading::where('number', $data['number'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->first();

        if ($clash) {
            $this->addError('number', 'That B/L number is already recorded against another shipment.');

            return;
        }

        if (! $this->editingId && BplBillOfLading::where('packing_id', $data['packing_id'])->exists()) {
            $this->addError('packing_id', 'That packing list already has a bill of lading.');

            return;
        }

        if ($data['shippedIso'] > $data['issuedIso']) {
            $this->addError('shippedIso', 'Goods ship before the bill of lading is issued.');

            return;
        }

        if (($data['arrivalIso'] ?? '') !== '' && $data['arrivalIso'] < $data['shippedIso']) {
            $this->addError('arrivalIso', 'A shipment cannot arrive before it ships.');

            return;
        }

        $packing = DB::connection('bpl')->table('bpl_packing_list as pl')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->where('pl.id', $data['packing_id'])
            ->first(['pl.number', 'c.id as customer_id', 'c.customerlabel']);

        $row = [
            'packing_id' => (int) $data['packing_id'],
            'number' => $data['number'],
            'issued_date' => str_replace('-', '/', $data['issuedIso']),
            'shipped_date' => str_replace('-', '/', $data['shippedIso']),
            'arrival_date' => ($data['arrivalIso'] ?? '') === '' ? null : str_replace('-', '/', $data['arrivalIso']),
        ];

        // The new scan is written BEFORE the old one is removed: a file system
        // does not take part in the transaction, and the legacy deleted first.
        $replaced = null;

        if ($this->document) {
            $label = PackingListSupport::label($packing->number ?? '', (int) ($packing->customer_id ?? 0), $packing->customerlabel ?? null);

            try {
                $row['doc'] = BillOfLadingDocs::store($this->document, $label);
            } catch (\Throwable $e) {
                report($e);
                $this->addError('document', 'The document could not be saved: ' . $e->getMessage());

                return;
            }

            $replaced = $this->editingId ? $this->currentDoc : null;
        }

        $bl = BplBillOfLading::updateOrCreate(['id' => $this->editingId], $row);

        if ($replaced && $replaced !== $bl->doc) {
            BillOfLadingDocs::delete($replaced);
        }

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Bill of lading updated.' : 'Bill of lading ' . $bl->number . ' recorded.');
    }

    /* ---------------- Deleting ---------------- */

    protected function performDelete(int $id): void
    {
        $bl = BplBillOfLading::find($id);

        if (! $bl) {
            return;
        }

        $doc = (string) $bl->doc;
        $bl->delete();
        BillOfLadingDocs::delete($doc);
    }

    public function deletedMessage($row): string
    {
        return 'Bill of lading ' . $row->number . ' removed — its packing list can be changed again.';
    }
}
