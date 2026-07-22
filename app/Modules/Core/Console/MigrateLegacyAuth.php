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

    protected array $modules = ['Admin', 'Factory', 'Raw Materials', 'Store', 'Sales', 'Quality', 'BPL', 'Reports'];
    protected array $resources = ['user', 'role', 'permission', 'department', 'company', 'module'];
    protected array $actions = ['view', 'create', 'edit', 'delete'];

    public function handle(): int
    {
        $this->seedModules();
        $this->seedRolesFromUserlevels();
        $this->seedAdminPermissions();
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
