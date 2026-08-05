<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Factories moves from Settings to Admin, alongside Company / Department /
 * Division / Staff — the organisation structure it belongs to.
 *
 * This re-keys rather than just moving the nav link: the Admin nav group is
 * wrapped in @canPrefix('admin.'), so a page still keyed settings.factories
 * would be hidden inside it for anyone without another admin.* page, and
 * /settings/factories would open the Settings group instead of highlighting
 * Admin.
 *
 * Renaming the permission rows in place (rather than dropping and re-seeding)
 * keeps any role grants attached — they hang off permission ids.
 */
return new class extends Migration
{
    private const OLD = 'settings.factories';
    private const NEW = 'admin.factories';

    public function up(): void
    {
        $this->rekey(self::OLD, self::NEW, 'Admin');
    }

    public function down(): void
    {
        $this->rekey(self::NEW, self::OLD, 'Settings');
    }

    private function rekey(string $from, string $to, string $module): void
    {
        $core = DB::connection('core');

        $core->table('pages')->where('key', $from)->update([
            'key' => $to,
            'module' => $module,
            'updated_at' => now(),
        ]);

        // {key}:{ability} permissions — renamed in place so grants survive.
        foreach ($core->table('permissions')->where('name', 'like', $from . ':%')->get() as $p) {
            $core->table('permissions')->where('id', $p->id)->update([
                'name' => $to . substr($p->name, strlen($from)),
                'updated_at' => now(),
            ]);
        }

        // DataGrid view config is keyed by the grid's pageKey().
        $core->table('data_pages')->where('key', $from)->update([
            'key' => $to,
            'updated_at' => now(),
        ]);
    }
};
