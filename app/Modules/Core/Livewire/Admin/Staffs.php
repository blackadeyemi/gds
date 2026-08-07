<?php

namespace Modules\Core\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Models\Department;
use Modules\Core\Models\Division;
use Modules\Core\Models\Staff;
use Modules\Core\Models\User;

/**
 * Admin > Staff. Factory-floor people recorded against machine service jobs,
 * migrated from the legacy bil.factory_staff (now a view over core.staff).
 *
 * Class name is Staffs because Staff is the model; the page is labelled "Staff".
 */
#[Title('Staff Management')]
class Staffs extends DataGrid
{
    public ?int $staff_no = null;
    public string $name = '';
    public ?int $department_id = null;
    public ?int $division_id = null;
    public ?int $user_id = null;
    public bool $is_active = true;

    public function pageKey(): string { return 'admin.staff'; }
    public function pageLabel(): string { return 'Staff'; }
    public function pageSubtitle(): string { return 'Factory staff, their department and division, and the login account they use (if any).'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'core::livewire.forms.staff'; }
    public function defaultSort(): array { return ['name', 'asc']; }

    public function views(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Staff No', 'staff_no', fn ($r) => $r->staff_no ? e($r->staff_no) : '—'],
                    ['Name', 'name'],
                    ['Department', 'department.name', fn ($r) => e($r->department?->name ?? '—')],
                    ['Division', 'division.name', fn ($r) => e($r->division?->name ?? '—')],
                    ['Login', 'user.username', fn ($r) => $r->user ? e($r->user->username) : '<span class="badge badge-muted">unlinked</span>'],
                    ['Status', 'is_active', fn ($r) => '<span class="badge ' . ($r->is_active ? 'badge-success' : 'badge-muted') . '">' . ($r->is_active ? 'active' : 'inactive') . '</span>'],
                ],
                'query' => fn () => Staff::query()->with(['department', 'division', 'user']),
                'searchable' => ['name'],
                'sortable' => ['name', 'staff_no', 'is_active'],
            ],
            'by_division' => [
                'label' => 'Summary (by division)',
                'type' => 'summary',
                'columns' => [
                    ['Department', 'department_name'],
                    ['Division', 'division_name'],
                    ['Staff', 'total'],
                ],
                'query' => fn () => Staff::query()
                    ->leftJoin('departments', 'staff.department_id', '=', 'departments.id')
                    ->leftJoin('divisions', 'staff.division_id', '=', 'divisions.id')
                    ->selectRaw("COALESCE(departments.name, '—') as department_name, COALESCE(divisions.name, '—') as division_name, COUNT(*) as total")
                    ->groupBy('department_name', 'division_name'),
            ],
        ];
    }

    /**
     * Departments, qualified by company. Names repeat across companies —
     * Belimpex and Belpapyrus both have a "Factory" — so an unqualified list is
     * ambiguous. The company also makes it obvious when a department is an org
     * unit rather than a factory-floor one (Belimpex has both an "Electrical"
     * department for accounts and an "Electrical" division under Maintenance).
     */
    #[Computed]
    public function departments()
    {
        return Department::with('company')->orderBy('name')->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name . ($d->company ? ' · ' . $d->company->name : ''),
            ]);
    }

    /** Divisions within the chosen department — drives the dependent select. */
    #[Computed]
    public function divisionsForDepartment()
    {
        return $this->department_id
            ? Division::where('department_id', $this->department_id)->orderBy('name')->get()
            : collect();
    }

    /** Login accounts, with the one already linked to this row kept selectable. */
    #[Computed]
    public function users()
    {
        $taken = Staff::whereNotNull('user_id')
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->pluck('user_id');

        return User::whereNotIn('userid', $taken)->orderBy('username')->get();
    }

    /** Changing department invalidates the previously-picked division. */
    public function updatedDepartmentId(): void
    {
        $this->division_id = null;
    }

    protected function rules(): array
    {
        return [
            'staff_no' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'exists:departments,id'],
            // Optional, but must belong to the chosen department.
            'division_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('divisions', 'id')->where('department_id', $this->department_id),
            ],
            'user_id' => ['nullable', 'exists:user,userid'],
            'is_active' => ['boolean'],
        ];
    }

    protected function resetForm(): void
    {
        $this->staff_no = null;
        $this->name = '';
        $this->department_id = null;
        $this->division_id = null;
        $this->user_id = null;
        $this->is_active = true;
    }

    protected function fillForm(int $id): void
    {
        $s = Staff::findOrFail($id);
        $this->staff_no = $s->staff_no;
        $this->name = $s->name;
        $this->department_id = $s->department_id;
        $this->division_id = $s->division_id;
        $this->user_id = $s->user_id;
        $this->is_active = (bool) $s->is_active;
    }

    /**
     * Blocked once the person has service history. Unlike the machine tree,
     * that history joins on (division, name) as well as staff_id, so removing
     * the row would strand it.
     */
    public function deleteGuard($row): ?string
    {
        $jobs = DB::connection('bil')->table('factory_machine_maintenance')
            ->where('staff_id', $row->id)->count();

        return $jobs > 0
            ? 'Recorded on ' . $jobs . ' service ' . Str::plural('job', $jobs) . ' — set inactive instead.'
            : null;
    }

    protected function findRow(int $id)
    {
        return Staff::find($id);
    }

    protected function performDelete(int $id): void
    {
        Staff::whereKey($id)->delete();
    }

    public function save(): void
    {
        $data = $this->validate();

        // Names repeat across divisions on purpose ("OTHERS" is a per-division
        // placeholder), so uniqueness is enforced within a division only —
        // which a UNIQUE index can't do while division_id is nullable.
        $clash = Staff::where('name', $data['name'])
            ->where('department_id', $data['department_id'])
            ->where(fn ($q) => $data['division_id']
                ? $q->where('division_id', $data['division_id'])
                : $q->whereNull('division_id'))
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('name', 'That division already has a staff member with this name.');

            return;
        }

        Staff::updateOrCreate(['id' => $this->editingId], $data);
        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Staff updated.' : 'Staff added.');
    }
}
