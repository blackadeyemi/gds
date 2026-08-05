<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move bil.factory_staff into core.departments / divisions / staff.
 *
 * The legacy division strings prefix their parent department, so they are split
 * on migration: "MAINTENANCE ELECTRICAL" becomes department MAINTENANCE +
 * division Electrical. The department is taken from factory_staff.department
 * (authoritative) rather than from the prefix — the CONVERSION department's
 * divisions are prefixed "PRODUCTION", so the prefix would give the wrong
 * answer there.
 *
 * The original string is preserved in divisions.legacy_name because 43k
 * maintenance rows and the live legacy app still speak it.
 *
 * Staff ids are preserved so the factory_staff compatibility view is
 * id-identical to the table it replaces.
 */
return new class extends Migration
{
    /** legacy division string => [department name, canonical division name] */
    private array $divisionSplit = [
        'MAINTENANCE ELECTRICAL' => ['MAINTENANCE', 'Electrical'],
        'MAINTENANCE MECHANICAL' => ['MAINTENANCE', 'Mechanical'],
        'PRODUCTION QUALITY CONTROL' => ['CONVERSION', 'Quality Control'],
        'PRODUCTION SUPERVISOR' => ['CONVERSION', 'Supervisor'],
    ];

    public function up(): void
    {
        $bil = DB::connection('bil');
        $core = DB::connection('core');
        $now = now();

        // --- Departments -------------------------------------------------
        // Kept upper-case, matching the legacy strings (and the existing
        // MARKETING row), so the compatibility view needs no alias here.
        $belimpex = $core->table('companies')->where('code', 'BIL')->value('id');

        foreach (['MAINTENANCE', 'CONVERSION'] as $name) {
            if (! $core->table('departments')->where('name', $name)->exists()) {
                $core->table('departments')->insert([
                    'name' => $name,
                    'company_id' => $belimpex,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        $departments = $core->table('departments')->pluck('id', 'name');

        // --- Divisions ---------------------------------------------------
        $sort = 0;
        foreach ($this->divisionSplit as $legacy => [$deptName, $name]) {
            $core->table('divisions')->insert([
                'department_id' => $departments[$deptName],
                'name' => $name,
                'legacy_name' => $legacy,
                'sort_order' => ++$sort * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $divisions = $core->table('divisions')->whereNotNull('legacy_name')
            ->pluck('id', 'legacy_name');

        // --- Staff (ids preserved) ---------------------------------------
        $rows = [];
        foreach ($bil->table('factory_staff')->orderBy('id')->get() as $s) {
            $legacyDivision = trim($s->division);
            $deptName = $this->divisionSplit[$legacyDivision][0] ?? trim($s->department);

            $rows[] = [
                'id' => $s->id,
                'staff_no' => $s->staff_id,
                'name' => trim($s->name),
                'department_id' => $departments[$deptName],
                'division_id' => $divisions[$legacyDivision] ?? null,
                'user_id' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $core->table('staff')->insert($rows);
    }

    public function down(): void
    {
        $core = DB::connection('core');
        $core->table('staff')->delete();
        $core->table('divisions')->delete();
        $core->table('departments')->whereIn('name', ['MAINTENANCE', 'CONVERSION'])->delete();
    }
};
