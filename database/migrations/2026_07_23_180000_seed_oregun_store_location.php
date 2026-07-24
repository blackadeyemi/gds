<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Establish Oregun as a real second raw-materials store location so Stock
 * Transfer (Ogba ⇄ Oregun) has a valid destination. Legacy only ever had
 * Ogba (location_id 1) in `rawmaterial_store_location`. Idempotent.
 */
return new class extends Migration
{
    protected string $conn = 'bil';

    public function up(): void
    {
        DB::connection($this->conn)->table('rawmaterial_store_location')->updateOrInsert(
            ['location' => 'Oregun'],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::connection($this->conn)->table('rawmaterial_store_location')->where('location', 'Oregun')->delete();
    }
};
