<?php

namespace Modules\Bpl\Livewire\JumboRolls;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bpl\Models\BplCustomer;
use Modules\Bpl\Models\BplProductHardroll;
use Modules\Bpl\Models\BplProduction;
use Modules\Bpl\Models\BplWeightAllowance;
use Modules\Bpl\Models\BplWeightAllowanceHistory;
use Modules\Bpl\Support\WeightAllowance;
use Modules\Core\Livewire\DataGrid;

/**
 * BPL → Jumbo Rolls → Weight Allowances.
 *
 * The core/wrapper allowance deducted from a hardroll's scale weight, by
 * customer, grade type and ply. Same shape as BIL's Conversion Setup: a table
 * of the current rules, an append-only history of every change, and one screen
 * over both.
 *
 * It exists because the rule used to be five hard-coded objects in
 * Bil\Bpl\production::merge(). The live data has drifted from that copy —
 * `PBT+`:1 was being deducted 70 kg in August 2026 without ever appearing in
 * the code — and nothing recorded who changed it or when. The third view puts
 * the rules and what production has actually been doing side by side so the
 * difference is visible rather than buried.
 *
 * Changing a rule does NOT restate reels already recorded: a reel's
 * `net_weight` is what was deducted on the day, and re-deriving it later would
 * rewrite history against a rule that was not in force at the time.
 */
#[Title('Hardroll Weight Allowances')]
class WeightAllowances extends DataGrid
{
    public ?int $customer_id = null;
    public string $gradetype = '';
    public string $ply = '1';
    public string $allowance = '';
    public string $note = '';

    public function pageKey(): string { return 'bpl.jumbo-rolls.weight-allowances'; }
    public function pageLabel(): string { return 'Hardroll Weight Allowances'; }
    public function pageSubtitle(): string { return 'Kilograms deducted from a reel\'s scale weight, by customer, grade and ply.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bpl::livewire.forms.weight-allowance'; }
    public function defaultSort(): array { return ['gradetype', 'asc']; }
    public function modalSize(): string { return '560px'; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Current rules',
                'type' => 'table',
                'columns' => [
                    ['Customer', 'customername'],
                    ['Grade Type', 'gradetype'],
                    ['Ply', 'ply'],
                    ['Allowance (kg)', 'allowance', fn ($r) => number_format((float) $r->allowance, 2)],
                    ['In Use', 'productcount', fn ($r) => $this->inUseCell($r)],
                    ['Note', 'note'],
                    ['Set By', 'username'],
                    ['Changed', 'updated_at', fn ($r) => $this->when($r->updated_at)],
                ],
                'query' => fn () => BplWeightAllowance::query()
                    ->leftJoin('bpl_customers as c', 'bpl_weight_allowances.customer_id', '=', 'c.id')
                    ->select(
                        'bpl_weight_allowances.*',
                        'c.customername',
                        // How many catalog products this rule would apply to —
                        // a rule matching nothing is almost certainly a typo in
                        // the grade type.
                        DB::connection('bpl')->raw(
                            '(SELECT COUNT(*) FROM `bpl_products_hardroll` p'
                            . ' WHERE p.`gradetype` = `bpl_weight_allowances`.`gradetype`'
                            . ' AND p.`ply` = `bpl_weight_allowances`.`ply`'
                            . ' AND p.`deleted_at` IS NULL) as productcount'
                        ),
                    ),
                'searchable' => ['bpl_weight_allowances.gradetype', 'bpl_weight_allowances.note', 'c.customername'],
                'sortable' => ['gradetype', 'ply', 'allowance', 'customername', 'username', 'updated_at'],
            ],

            'recorded' => [
                'label' => 'Recorded in production',
                'type' => 'table',
                'columns' => [
                    ['Customer', 'customername'],
                    ['Grade Type', 'gradetype'],
                    ['Ply', 'ply'],
                    ['Deducted (kg)', 'net_weight', fn ($r) => number_format((float) $r->net_weight, 2)],
                    ['Reels', 'reels'],
                    ['First', 'first_seen'],
                    ['Last', 'last_seen'],
                    ['Matches Rule', 'matches', fn ($r) => $this->matchesCell($r)],
                ],
                // What production has ACTUALLY been deducting, straight off the
                // reels. Grouped, so it is a handful of rows however many reels
                // there are — nine today, against five rules.
                'query' => fn () => BplProduction::query()
                    ->join('bpl_products_hardroll as p', 'bpl_production.product_id', '=', 'p.id')
                    ->leftJoin('bpl_customers as c', 'bpl_production.customer_id', '=', 'c.id')
                    ->whereNotNull('bpl_production.net_weight')
                    ->where('bpl_production.net_weight', '>', 0)
                    ->groupBy('bpl_production.customer_id', 'c.customername', 'p.gradetype', 'p.ply', 'bpl_production.net_weight')
                    ->select(
                        'bpl_production.customer_id',
                        'c.customername',
                        'p.gradetype',
                        'p.ply',
                        'bpl_production.net_weight',
                        DB::connection('bpl')->raw('COUNT(*) as reels'),
                        DB::connection('bpl')->raw('MIN(`bpl_production`.`dateofmanufacture`) as first_seen'),
                        DB::connection('bpl')->raw('MAX(`bpl_production`.`dateofmanufacture`) as last_seen'),
                    )
                    // Grouped rows have no id, so pagination needs a stable
                    // order of its own.
                    ->orderByDesc('reels'),
                'searchable' => [],
                'sortable' => [],
            ],

            'history' => [
                'label' => 'History',
                'type' => 'table',
                'columns' => [
                    ['When', 'date_modified', fn ($r) => $this->when($r->date_modified)],
                    ['Action', 'action', fn ($r) => $this->actionCell($r)],
                    ['Customer', 'customername'],
                    ['Grade Type', 'gradetype'],
                    ['Ply', 'ply'],
                    ['Change', 'allowance', fn ($r) => $this->changeCell($r)],
                    ['Note', 'note'],
                    ['By', 'username'],
                ],
                'query' => fn () => BplWeightAllowanceHistory::query()
                    ->leftJoin('bpl_customers as c', 'bpl_weight_allowance_history.customer_id', '=', 'c.id')
                    ->select('bpl_weight_allowance_history.*', 'c.customername')
                    ->orderByDesc('bpl_weight_allowance_history.id'),
                'searchable' => ['bpl_weight_allowance_history.gradetype', 'bpl_weight_allowance_history.username', 'c.customername'],
                'sortable' => ['date_modified', 'action', 'gradetype', 'ply', 'allowance', 'username'],
            ],
        ];
    }

    /* ---------------- Cells ---------------- */

    private function when($value): string
    {
        return $value ? e($value->format('d/M/Y H:i')) : '—';
    }

    /** A rule that matches no product in the catalog is dead weight — say so. */
    private function inUseCell($row): string
    {
        $count = (int) $row->productcount;

        return $count > 0
            ? e($count . ' product' . ($count === 1 ? '' : 's'))
            : '<span class="badge badge-warning" title="No hardroll product has this grade type and ply">No products</span>';
    }

    private function actionCell($row): string
    {
        $class = match ($row->action) {
            BplWeightAllowanceHistory::ADDED => 'badge-success',
            BplWeightAllowanceHistory::REMOVED => 'badge-danger',
            default => 'badge-muted',
        };

        return '<span class="badge ' . $class . '">' . e($row->action) . '</span>';
    }

    private function changeCell($row): string
    {
        $to = $row->allowance === null ? '—' : number_format((float) $row->allowance, 2);
        $from = $row->previous_allowance === null ? null : number_format((float) $row->previous_allowance, 2);

        return $from === null ? e($to) : e($from . ' → ' . $to);
    }

    /**
     * Does what production actually deducted agree with the rule now in force?
     * A "no" is not necessarily wrong — the rule may have been changed since —
     * but it is the thing worth looking at.
     */
    private function matchesCell($row): string
    {
        $current = WeightAllowance::for((int) $row->customer_id, $row->gradetype, (int) $row->ply);
        $recorded = (float) $row->net_weight;

        if (! WeightAllowance::has((int) $row->customer_id, $row->gradetype, (int) $row->ply)) {
            return '<span class="badge badge-danger" title="No rule covers this combination">No rule</span>';
        }

        return abs($current - $recorded) < 0.005
            ? '<span class="badge badge-success">Yes</span>'
            : '<span class="badge badge-warning" title="The rule now says '
                . e(number_format($current, 2)) . ' kg">Rule says ' . e(number_format($current, 2)) . '</span>';
    }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function customers()
    {
        return BplCustomer::query()->select('id', 'customername')->orderBy('customername')->get();
    }

    /**
     * Grade types drawn from the hardroll catalog, so a rule cannot be written
     * against a grade that does not exist. Folded to one entry per spelling —
     * the catalog holds `PBTS` and `PBTs`, and the lookup treats them as one.
     */
    #[Computed]
    public function gradeTypes()
    {
        return BplProductHardroll::query()
            ->whereNotNull('gradetype')->where('gradetype', '<>', '')
            ->distinct()->orderBy('gradetype')->pluck('gradetype')
            ->map(fn ($g) => strtoupper(trim($g)))
            ->unique()->values();
    }

    /* ---------------- Form ---------------- */

    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:bpl.bpl_customers,id'],
            'gradetype' => ['required', 'string', 'max:50'],
            'ply' => ['required', 'integer', 'min:0', 'max:255'],
            // 0 is allowed and is not the same as having no rule: it says "this
            // combination was considered and gets nothing".
            'allowance' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function resetForm(): void
    {
        // Belimpex is the only customer the rule has ever applied to, so it is
        // the sensible default rather than an empty picker.
        $this->customer_id = (int) config('bil.jumbo_roll_customer_id');
        $this->gradetype = '';
        $this->ply = '1';
        $this->allowance = '';
        $this->note = '';
    }

    protected function fillForm(int $id): void
    {
        $r = BplWeightAllowance::findOrFail($id);
        $this->customer_id = $r->customer_id;
        $this->gradetype = (string) $r->gradetype;
        $this->ply = (string) $r->ply;
        $this->allowance = (string) $r->allowance;
        $this->note = (string) $r->note;
    }

    protected function findRow(int $id)
    {
        return BplWeightAllowance::find($id);
    }

    public function save(): void
    {
        $data = $this->validate();

        $gradetype = strtoupper(trim($data['gradetype']));
        $ply = (int) $data['ply'];
        $customerId = (int) $data['customer_id'];
        $allowance = round((float) $data['allowance'], 2);

        // One rule per customer + grade + ply. Checked here rather than left to
        // the unique index so the operator gets a sentence, not a 500.
        $clash = BplWeightAllowance::query()
            ->where('customer_id', $customerId)
            ->where('gradetype', $gradetype)
            ->where('ply', $ply)
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('gradetype', 'There is already an allowance for this customer, grade type and ply.');

            return;
        }

        $user = (string) (auth()->user()?->username ?? auth()->user()?->name ?? 'gds');
        $previous = $this->editingId ? BplWeightAllowance::find($this->editingId) : null;

        DB::connection('bpl')->transaction(function () use ($customerId, $gradetype, $ply, $allowance, $data, $user, $previous) {
            $row = BplWeightAllowance::updateOrCreate(
                ['id' => $this->editingId],
                [
                    'customer_id' => $customerId,
                    'gradetype' => $gradetype,
                    'ply' => $ply,
                    'allowance' => $allowance,
                    'note' => trim((string) $data['note']) ?: null,
                    'username' => $user,
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]
            );

            // A no-op save must not fake a change — same rule as Conversion
            // Setup's changeover log.
            if ($row->wasRecentlyCreated || $row->wasChanged(['customer_id', 'gradetype', 'ply', 'allowance'])) {
                BplWeightAllowanceHistory::create([
                    'customer_id' => $customerId,
                    'gradetype' => $gradetype,
                    'ply' => $ply,
                    'allowance' => $allowance,
                    'previous_allowance' => $row->wasRecentlyCreated ? null : $previous?->allowance,
                    'action' => $row->wasRecentlyCreated
                        ? BplWeightAllowanceHistory::ADDED
                        : BplWeightAllowanceHistory::CHANGED,
                    'note' => trim((string) $data['note']) ?: null,
                    'username' => $user,
                    'date_modified' => now(),
                ]);
            }
        });

        WeightAllowance::flush();

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Allowance updated.' : 'Allowance added.');
    }

    protected function performDelete(int $id): void
    {
        $row = BplWeightAllowance::find($id);
        if (! $row) {
            return;
        }

        $user = (string) (auth()->user()?->username ?? auth()->user()?->name ?? 'gds');

        DB::connection('bpl')->transaction(function () use ($row, $user) {
            BplWeightAllowanceHistory::create([
                'customer_id' => $row->customer_id,
                'gradetype' => $row->gradetype,
                'ply' => $row->ply,
                'allowance' => null,
                'previous_allowance' => $row->allowance,
                'action' => BplWeightAllowanceHistory::REMOVED,
                'username' => $user,
                'date_modified' => now(),
            ]);

            $row->delete();
        });

        WeightAllowance::flush();
    }

    /**
     * The two read-out views are exactly that. "Recorded in production" rows
     * are grouped reels, not rules, and history is append-only.
     */
    public function mayDo(string $ability): bool
    {
        if (in_array($this->view, ['history', 'recorded'], true)
            && in_array($ability, ['create', 'edit', 'delete'], true)) {
            return false;
        }

        return parent::mayDo($ability);
    }
}
