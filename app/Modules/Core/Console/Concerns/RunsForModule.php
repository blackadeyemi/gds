<?php

namespace Modules\Core\Console\Concerns;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\ModuleContext;

/**
 * Lets a bil-bound console command run for another company module (BHN).
 *
 * The web ResolveCompanyModule middleware rebinds the `bil` connection per
 * request, but it never runs in the console — so a reconcile/maintenance command
 * that queries `DB::connection('bil')` or the Bil models would always hit BIL.
 * A command that `use`s this trait and adds a `{--module=bil}` option can call
 * applyModuleConnection() first to point the `bil` connection + config at the
 * chosen company's, exactly as the middleware does.
 */
trait RunsForModule
{
    protected function applyModuleConnection(): string
    {
        $m = (string) ($this->option('module') ?: 'bil');

        if ($m !== 'bil') {
            if (! config("database.connections.{$m}")) {
                $this->warn("Unknown module '{$m}' — staying on bil.");

                return 'bil';
            }
            config(['database.connections.bil' => config("database.connections.{$m}")]);
            DB::purge('bil');
            config(['bil' => config($m)]);
            ModuleContext::set($m);
            $this->line("Module: {$m}  (bil connection -> ".DB::connection('bil')->getDatabaseName().')');
        }

        return $m;
    }
}
