<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The global capabilities (edit-/delete-/approve-raw-materials, backdate,
 * bypass-shift-window) are replaced by per-page abilities ("{key}:{ability}").
 * All real permissions are now colon-named; drop everything else. One-way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('core')->table('permissions')->where('name', 'not like', '%:%')->delete();

        if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Irreversible: these capabilities are obsolete under per-page abilities.
    }
};
