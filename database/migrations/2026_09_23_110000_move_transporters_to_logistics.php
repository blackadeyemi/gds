<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BPL Transporters moves from Sales to the new Logistics group.
 *
 * The page key is part of every grant (`permissions.name` is
 * `<page key>:<ability>`) and of the per-page grid config (`data_pages.key`),
 * so moving the screen without moving those would silently revoke whoever had
 * been granted it and reset its view settings.
 */
return new class extends Migration
{
    /** old page key => new page key */
    private const KEYS = [
        'bpl.sales.transporters' => 'bpl.logistics.transporters',
    ];

    public function up(): void
    {
        $this->move(self::KEYS);
    }

    public function down(): void
    {
        $this->move(array_flip(self::KEYS));
    }

    private function move(array $map): void
    {
        foreach ($map as $old => $new) {
            DB::connection('core')->table('permissions')
                ->where('name', 'like', $old . ':%')
                ->update(['name' => DB::raw("REPLACE(`name`, '{$old}:', '{$new}:')")]);

            // data_pages is keyed by the grid's pageKey(), which is the dashed
            // form; transporters has no dash in either half, so one update does.
            DB::connection('core')->table('data_pages')
                ->where('key', $old)
                ->update(['key' => $new]);
        }
    }
};
