<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

/**
 * Provision BHN (company 4) staff access by mirroring BIL's roles.
 *
 * BHN reuses the BIL screens under /bhn, which require `bhn.*` permissions (see
 * ModuleContext::normalizeKey). The existing roles grant `bil.*`, so this builds
 * parallel "<name> (BHN)" roles granting the `bhn.*` mirror of each role's bil
 * permissions, assigns each BHN user to the role matching their migrated legacy
 * `userlevel`, and seeds gate grants for roles that can open a gate screen.
 *
 * Idempotent. Admins (userlevel 1) are skipped — they already see everything.
 * Rehearse with --dry-run; the real run needs --force.
 */
class ProvisionBhnRbac extends Command
{
    protected $signature = 'gds:provision-bhn-rbac {--dry-run} {--force}';

    protected $description = 'Mirror BIL roles to BHN, assign BHN users by legacy level, seed gate grants';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('force')) {
            $this->error('Refusing to modify data without --force. Rehearse with --dry-run first.');

            return self::FAILURE;
        }

        $guard = 'web';

        // Gate pages (bhn.*) by kind, from the registry.
        $gatePages = fn (string $kind) => collect(config('pages.pages', []))
            ->filter(fn ($p) => ($p['gates'] ?? null) === $kind && str_starts_with($p['key'], 'bhn.'))
            ->pluck('key')->map(fn ($k) => $k.':view')->all();
        $whGatePerms = $gatePages('warehouse');
        $facGatePerms = $gatePages('factory');

        // BHN gate ids (company 4).
        $whGateIds = DB::connection('core')->table('warehouse_gates as g')
            ->join('warehouses as w', 'w.id', '=', 'g.warehouse_id')
            ->where('w.company_id', 4)->pluck('g.id')->all();
        $facGateIds = DB::connection('core')->table('factory_gates as g')
            ->join('factories as f', 'f.id', '=', 'g.factory_id')
            ->where('f.company_id', 4)->pluck('g.id')->all();

        // 1) Mirror each role that grants any bil.* permission.
        $sourceRoles = Role::with('permissions')->get()
            ->filter(fn ($r) => $r->permissions->contains(fn ($p) => str_starts_with($p->name, 'bil.')));

        $byLevel = [];   // legacy userlevel => bhn Role
        $rolesMade = 0;
        $permsAttached = 0;

        foreach ($sourceRoles as $src) {
            $bhnName = $src->name.' (BHN)';
            $bhnPerms = $src->permissions
                ->filter(fn ($p) => str_starts_with($p->name, 'bil.'))
                ->map(fn ($p) => 'bhn.'.substr($p->name, 4))
                ->filter(fn ($n) => DB::connection('core')->table('permissions')
                    ->where('name', $n)->where('guard_name', $guard)->exists())
                ->values()->all();

            if ($dry) {
                $exists = Role::where('name', $bhnName)->where('guard_name', $guard)->exists();
                $this->line(sprintf('  %-48s %s, %d perms', $bhnName, $exists ? 'exists' : 'CREATE', count($bhnPerms)));
            } else {
                $bhn = Role::firstOrCreate(
                    ['name' => $bhnName, 'guard_name' => $guard],
                    ['description' => 'BHN — '.($src->description ?: $src->name)]
                );
                $bhn->syncPermissions($bhnPerms);
                $rolesMade++;
                $permsAttached += count($bhnPerms);
                if ($src->legacy_level !== null) {
                    $byLevel[(int) $src->legacy_level] = $bhn;
                }
            }
        }

        // 2) Assign BHN users (company 4, non-admin) to the role matching their level.
        $assigned = 0;
        $unmatched = [];
        $grantsW = 0;
        $grantsF = 0;

        if (! $dry) {
            foreach (User::where('company_id', 4)->where('userlevel', '<>', 1)->get() as $u) {
                $role = $byLevel[(int) $u->userlevel] ?? null;
                if (! $role) {
                    $unmatched[] = $u->username.' (lvl '.$u->userlevel.')';

                    continue;
                }
                $u->assignRole($role);
                $assigned++;

                // 3) Gate grants for this user's role.
                $roleW = $role->permissions->whereIn('name', $whGatePerms)->isNotEmpty();
                $roleF = $role->permissions->whereIn('name', $facGatePerms)->isNotEmpty();
                if ($roleW) {
                    $grantsW += $this->grantGates('warehouse_gate_user', $u->userid, $whGateIds);
                }
                if ($roleF) {
                    $grantsF += $this->grantGates('factory_gate_user', $u->userid, $facGateIds);
                }
            }
        }

        $this->newLine();
        if ($dry) {
            $this->info('DRY RUN — '.$sourceRoles->count().' BIL roles would be mirrored to BHN.');
            $this->line('BHN users to assign: '.User::where('company_id', 4)->where('userlevel', '<>', 1)->count());

            return self::SUCCESS;
        }

        $this->info("Roles mirrored: {$rolesMade} ({$permsAttached} permission grants)");
        $this->info("Users assigned: {$assigned}; gate grants — warehouse {$grantsW}, factory {$grantsF}");
        if ($unmatched) {
            $this->warn('No role for userlevel (left unassigned): '.implode(', ', $unmatched));
        }

        return self::SUCCESS;
    }

    /** Insert missing (gate_id, user_id) rows; returns how many were added. */
    private function grantGates(string $pivot, int $userId, array $gateIds): int
    {
        $added = 0;
        foreach ($gateIds as $gid) {
            $exists = DB::connection('core')->table($pivot)
                ->where('gate_id', $gid)->where('user_id', $userId)->exists();
            if (! $exists) {
                DB::connection('core')->table($pivot)->insert(['gate_id' => $gid, 'user_id' => $userId]);
                $added++;
            }
        }

        return $added;
    }
}
