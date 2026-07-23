<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchy is Company > Department > User: a non-admin user sits inside a
 * department, which belongs to a company. Nullable so existing rows and
 * Admins (who span everything) stay unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
    }
};
