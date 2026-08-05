<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Companies get the short code the business already uses for them — BIL, BPL,
 * BOU. `application_modules.company` and the page-registry module labels are
 * already written in that vocabulary; this makes it a real attribute of the
 * company rather than a string convention repeated across the codebase.
 */
return new class extends Migration
{
    /** company name => code */
    private array $codes = [
        'Belimpex' => 'BIL',
        'Belpapyrus' => 'BPL',
        'Boulos' => 'BOU',
    ];

    public function up(): void
    {
        Schema::connection('core')->table('companies', function (Blueprint $table) {
            $table->string('code', 8)->nullable()->unique()->after('name');
        });

        foreach ($this->codes as $name => $code) {
            DB::connection('core')->table('companies')
                ->where('name', $name)
                ->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::connection('core')->table('companies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
