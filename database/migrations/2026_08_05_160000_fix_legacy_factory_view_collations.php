<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Support\LegacyFactoryViews;

/**
 * Rebuild the five compatibility views without the collation cast they were
 * first created with.
 *
 * The original definitions wrapped each name in
 * `CONVERT(... USING latin1) COLLATE latin1_swedish_ci`, intending to match the
 * latin1 columns they replaced. But an explicit COLLATE makes the expression
 * coercibility 0 (EXPLICIT), and MySQL will not coerce an EXPLICIT collation to
 * another charset — so any legacy query joining these views to a utf8mb3
 * consumer table (factory_usage_rawmaterials, factory_usage_reel) failed with
 * "Illegal mix of collations (latin1_swedish_ci,EXPLICIT) and
 * (utf8mb3_general_ci,IMPLICIT)".
 *
 * Emitting the underlying utf8mb4 columns as-is restores the original
 * behaviour: they are ordinary column references (IMPLICIT), and utf8mb4 is a
 * superset of both latin1 and utf8mb3, so joins from either side coerce.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyFactoryViews::apply();
    }

    public function down(): void
    {
        // The corrected views are what every earlier migration now installs, so
        // there is nothing meaningful to revert to.
    }
};
