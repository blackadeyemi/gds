<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the legacy CRUD/module permissions that the per-page access model made
 * obsolete (view-*, create-*, the admin edit-/delete-* set, *-bpl,
 * manage-shift-settings). Kept: the capabilities still checked in code
 * (edit-/delete-/approve-raw-materials, backdate, bypass-shift-window) and any
 * permission linked to pages. One-way cleanup; the seeder no longer creates the
 * removed set. FK cascade clears role/user pivots.
 */
return new class extends Migration
{
    private array $keep = [
        'edit-raw-materials', 'delete-raw-materials', 'approve-raw-materials',
        'backdate', 'bypass-shift-window',
    ];

    public function up(): void
    {
        $conn = DB::connection('core');
        $pageLinked = $conn->table('permission_page')->distinct()->pluck('permission_id')->all();

        $conn->table('permissions')
            ->whereNotIn('name', $this->keep)
            ->when($pageLinked, fn ($q) => $q->whereNotIn('id', $pageLinked))
            ->delete();

        if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Irreversible: the removed permissions are obsolete under per-page access.
    }
};
