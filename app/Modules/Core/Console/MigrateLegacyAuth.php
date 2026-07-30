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

    protected array $modules = ['Admin', 'Factory', 'Raw Materials', 'Store', 'Sales', 'Quality', 'Jumbo Rolls', 'Reports'];
    protected array $resources = ['user', 'role', 'permission', 'department', 'company', 'module'];
    protected array $actions = ['view', 'create', 'edit', 'delete'];

    public function handle(): int
    {
        $this->seedModules();
        $this->seedRolesFromUserlevels();
        $this->seedAdminPermissions();
        $this->seedRawMaterialsPermissions();
        $this->seedBplPermissions();
        $this->seedBackdatePermission();
        $this->seedShiftPermissions();
        $this->assignUsers();

        $this->info('Legacy auth migrated.');
        return self::SUCCESS;
    }

    protected function seedModules(): void
    {
        foreach ($this->modules as $i => $name) {
            ApplicationModule::firstOrCreate(
                ['slug' => str($name)->slug()->value()],
                ['name' => $name, 'sort_order' => $i, 'is_active' => true]
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

    protected function seedAdminPermissions(): void
    {
        $adminModule = ApplicationModule::where('slug', 'admin')->first();
        $created = [];
        foreach ($this->resources as $res) {
            foreach ($this->actions as $act) {
                $name = "$act-$res";
                $perm = Permission::firstOrNew(['name' => $name, 'guard_name' => 'web']);
                $perm->description = ucfirst($act) . ' ' . $res;
                $perm->module_id = $adminModule?->id;
                $perm->save();
                $created[] = $name;
            }
        }

        $admin = Role::where('legacy_level', 1)->first();
        if ($admin) {
            $admin->syncPermissions($created);
            $this->line('  baseline admin permissions: ' . count($created) . ' → role "' . $admin->name . '"');
        }
    }

    /**
     * Seed the Raw Materials module's page permissions and grant the baseline
     * `view-raw-materials` to the Admin role so the BIL → Raw Materials nav and
     * routes are reachable. givePermissionTo (not sync) preserves the admin's
     * other permissions. Idempotent.
     */
    protected function seedRawMaterialsPermissions(): void
    {
        $module = ApplicationModule::where('slug', 'raw-materials')->first();
        $created = [];
        foreach ($this->actions as $act) {
            $name = "$act-raw-materials";
            $perm = Permission::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $perm->description = ucfirst($act) . ' raw materials';
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
            // Admin gets the full raw-materials set (view/create/edit/delete + approve);
            // report row edit/delete is gated by edit-/delete-raw-materials.
            $admin->givePermissionTo($created);
            $this->line('  raw-materials permissions: ' . count($created) . ' → all granted to role "' . $admin->name . '"');
        }
    }

    /**
     * Seed the BPL module's page permissions (view/create/edit/delete-bpl) and
     * grant the full set to Admin so the BPL nav (Grades, Products) and routes
     * are reachable. Mirrors seedRawMaterialsPermissions. Idempotent.
     */
    protected function seedBplPermissions(): void
    {
        $module = ApplicationModule::where('slug', 'jumbo-rolls')->first();
        $created = [];
        foreach ($this->actions as $act) {
            $name = "$act-bpl";
            $perm = Permission::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $perm->description = ucfirst($act) . ' BPL';
            $perm->module_id = $module?->id;
            $perm->save();
            $created[] = $name;
        }

        $admin = Role::where('legacy_level', 1)->first();
        if ($admin) {
            $admin->givePermissionTo($created);
            $this->line('  bpl permissions: ' . count($created) . ' → all granted to role "' . $admin->name . '"');
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
        $module = ApplicationModule::where('slug', 'raw-materials')->first();
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
     * Shift-scheduling capabilities: `manage-shift-settings` (configure the
     * Day/Night windows per area) and `bypass-shift-window` (reach a gated page
     * even when it's closed). Granted to Admin; assign to an Operations Manager
     * (or any) role from the Roles admin. Idempotent (givePermissionTo is additive).
     */
    protected function seedShiftPermissions(): void
    {
        $adminModule = ApplicationModule::where('slug', 'admin')->first();
        $perms = [
            'manage-shift-settings' => 'Configure shift windows (Day/Night times) per area',
            'bypass-shift-window' => 'Access a shift-gated page even when its window is closed',
        ];
        foreach ($perms as $name => $desc) {
            $perm = Permission::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $perm->description = $desc;
            $perm->module_id = $perm->module_id ?: $adminModule?->id;
            $perm->save();
        }

        $admin = Role::where('legacy_level', 1)->first();
        if ($admin) {
            $admin->givePermissionTo(array_keys($perms));
            $this->line('  shift permissions: ' . count($perms) . ' → granted to role "' . $admin->name . '"');
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
