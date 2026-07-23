<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope each user to a company. A "Report User" role can belong to either
 * Belimpex (bil) or Belpapyrus (bpl), so the company is picked per user —
 * except Admin users, who span all companies and leave this null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('userlevel');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
};
