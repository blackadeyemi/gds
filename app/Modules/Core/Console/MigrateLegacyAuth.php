<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ApplicationModule;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

/**
 * One-way migration from the legacy flat auth model into the new RBAC:
 *  - seed Application Modules,
 *  - create one role per core.userlevels row (1:1, keeping name/description/level),
 *  - seed a baseline admin permission set and grant it to the Admin role (level 1),
 *  - assign each user the role matching their legacy userlevel.
 * Idempotent — safe to re-run.
 */
class MigrateLegacyAuth extends Command
{
    protected $signature = 'gds:migrate-legacy-auth';
    protected $description = 'Seed roles/permissions from legacy userlevels and assign all users';

    /** Functional-area modules, qualified by company (null = cross-cutting). */
    protected array $modules = [
        ['name' => 'Admin', 'company' => null],
        ['name' => 'Factory', 'company' => 'BIL'],
        ['name' => 'Raw Materials', 'company' => 'BIL'],
        ['name' => 'Store', 'company' => 'BIL'],
        ['name' => 'Sales', 'company' => 'BIL'],
        ['name' => 'Quality', 'company' => 'BIL'],
        ['name' => 'Jumbo Rolls', 'company' => 'BPL'],
        ['name' => 'Reports', 'company' => null],
    ];
    /** Row-level capabilities seeded for Raw Materials (access is per-page). */
    protected array $actions = ['edit', 'delete'];

    public function handle(): int
    {
        $this->seedModules();
        $this->seedRolesFromUserlevels();
        $this->seedRawMaterialsPermissions();
        $this->seedBackdatePermission();
        $this->seedShiftPermissions();
        $this->assignUsers();

        $this->info('Legacy auth migrated.');
        return self::SUCCESS;
    }

    protected function seedModules(): void
    {
        foreach ($this->modules as $i => $m) {
            $slug = str(trim(($m['company'] ? $m['company'] . ' ' : '') . $m['name']))->slug()->value();
            ApplicationModule::firstOrCreate(
                ['slug' => $slug],
                ['name' => $m['name'], 'company' => $m['company'], 'sort_order' => $i, 'is_active' => true]
            );
        }
        $this->line('  modules seeded (' . count($this->modules) . ')');
    }

    protected function seedRolesFromUserlevels(): void
    {
        $levels = DB::connection('core')->table('userlevels')->get();
        foreach ($levels as $lvl) {
            $role = Role::firstOrNew(['legacy_level' => $lvl->level]);
            $role->name = $lvl->default_user;
            $role->guard_name = 'web';
            $role->description = $lvl->role_description ?: null;
            $role->save();
        }
        $this->line('  roles from userlevels: ' . $levels->count());
    }

    /**
     * Seed the Raw Materials row-level capabilities (edit/delete a report row +
     * approve two-stage flows) and grant them to Admin. Page ACCESS is handled
     * by the per-page model (config/pages.php), not permissions like these.
     * givePermissionTo is additive. Idempotent.
     */
    protected function seedRawMaterialsPermissions(): void
    {
        $module = ApplicationModule::where('slug', 'bil-raw-materials')->first();
        $created = [];
        foreach ($this->actions as $act) {
            $name = "$act-raw-materials";
            $perm = Permission::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $perm->description = ucfirst($act) . ' a raw-materials report row';
            $perm->module_id = $module?->id;
            $perm->save();
            $created[] = $name;
        }

        // Approval capability for the two-stage flows (Factory Returns,
        // Damaged Goods): who may sign off on submitted entries.
        $approve = Permission::firstOrNew(['name' => 'approve-raw-materials', 'guard_name' => 'web']);
        $approve->description = 'Approve raw-material returns and damaged goods';
        $approve->module_id = $module?->id;
        $approve->save();
        $created[] = 'approve-raw-materials';

        $admin = Role::where('legacy_level', 1)->first();
        if ($admin) {
            $admin->givePermissionTo($created);
            $this->line('  raw-materials capabilities: ' . count($created) . ' → granted to role "' . $admin->name . '"');
        }
    }

    /**
     * A cross-cutting capability: change/backdate the date on entry forms
     * (Warehouse Entry, Supplier Deliveries, …). Granted to Admin by default
     * (matches the legacy "only level 1 can edit the date" behaviour); other
     * roles can be granted it from the Roles admin. Idempotent.
     */
    protected function seedBackdatePermission(): void
    {
        $module = ApplicationModule::where('slug', 'bil-raw-materials')->first();
        $perm = Permission::firstOrNew(['name' => 'backdate', 'guard_name' => 'web']);
        $perm->description = 'Change/backdate the date on entry forms';
        $perm->module_id = $perm->module_id ?: $module?->id;
        $perm->save();

        $admin = Role::where('legacy_level', 1)->first();
        if ($admin) {
            $admin->givePermissionTo('backdate');
            $this->line('  backdate permission → granted to role "' . $admin->name . '"');
        }
    }

    /**
     * Shift capability: `bypass-shift-window` (reach a shift-gated page even when
     * it's closed). Granted to Admin; assign to others from the Roles admin.
     * (Configuring shift windows is now page access — the Shift Settings page —
     * not a permission.) Idempotent (givePermissionTo is additive).
     */
    protected function seedShiftPermissions(): void
    {
        $adminModule = ApplicationModule::where('slug', 'admin')->first();
        $perm = Permission::firstOrNew(['name' => 'bypass-shift-window', 'guard_name' => 'web']);
        $perm->description = 'Access a shift-gated page even when its window is closed';
        $perm->module_id = $perm->module_id ?: $adminModule?->id;
        $perm->save();

        $admin = Role::where('legacy_level', 1)->first();
        if ($admin) {
            $admin->givePermissionTo('bypass-shift-window');
            $this->line('  shift capability: bypass-shift-window → granted to role "' . $admin->name . '"');
        }
    }

    protected function assignUsers(): void
    {
        $rolesByLevel = Role::whereNotNull('legacy_level')->get()->keyBy('legacy_level');
        $assigned = 0;

        User::query()->select(['userid', 'userlevel'])->chunkById(200, function ($users) use ($rolesByLevel, &$assigned) {
            foreach ($users as $u) {
                $role = $rolesByLevel->get($u->userlevel);
                if ($role) {
                    $u->syncRoles([$role]);
                    $assigned++;
                }
            }
        }, 'userid');

        $this->line("  users assigned a role: $assigned");
    }
}
