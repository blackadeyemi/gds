<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ApplicationModule;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

/**
 * One-way migration from the legacy flat auth model into the new RBAC:
 *  - seed Application Modules (company-qualified),
 *  - create one role per core.userlevels row (1:1, keeping name/description/level),
 *  - assign each user the role matching their legacy userlevel.
 * Access is per-page: abilities are seeded by `gds:sync-pages`, granted to roles
 * in the Role editor. Idempotent — safe to re-run.
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
    public function handle(): int
    {
        $this->seedModules();
        $this->seedRolesFromUserlevels();
        $this->assignUsers();

        $this->info('Legacy auth migrated. Run gds:sync-pages for ability permissions.');
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
