<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country → state → city reference data.
 *
 * WHY NEW TABLES
 * `core.countries` (239 rows) and `core.states` (37) came over from the legacy
 * app. `states` holds Nigeria's states only and has no country column, so it
 * can never answer "what are Ghana's regions?"; there has never been a city
 * table at all. Both legacy tables are LEFT IN PLACE — `Bil\Country` still
 * reads `countries` in the legacy app — and gds reads only these.
 *
 * Populated by `gds:import-geo` from the Countries States Cities Database
 * (ODbL v1.0), not by this migration: the city file is 19 MB and does not
 * belong in a migration or in git. Running the import is part of deploying.
 *
 * DENORMALISED ON PURPOSE. The source CSVs carry `country_name`/`state_name`
 * alongside the codes, and the customer record stores a state as a NAME, not an
 * id. Keeping the codes on every row turns "cities in this state" into two
 * indexed lookups with no joins, and means a city row still makes sense if its
 * state is ever re-imported under a different id.
 *
 * Attribution required by the licence:
 *   Data by Countries States Cities Database
 *   https://github.com/dr5hn/countries-states-cities-database | ODbL v1.0
 */
return new class extends Migration
{
    public function up(): void
    {
        $core = Schema::connection('core');

        $core->create('geo_countries', function (Blueprint $table) {
            // Dataset ids, kept so a re-import matches rows rather than
            // duplicating them.
            $table->unsignedInteger('id')->primary();
            $table->string('iso2', 2)->unique();
            $table->string('iso3', 3)->nullable();
            $table->string('name', 100)->index();
            // Dial code — "234". Not an int: some are "1-242".
            $table->string('phonecode', 16)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('region', 40)->nullable();
        });

        $core->create('geo_states', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('country_code', 2);
            $table->string('state_code', 12)->nullable();
            $table->string('name', 120);
            // province / state / region / district … worth keeping so a label
            // can say "Province" where that is the right word. 64, not 40: the
            // longest real value is 45 ("autonomous republic"-style compounds).
            $table->string('type', 64)->nullable();

            // Resolving a stored state NAME to its code, per country.
            $table->index(['country_code', 'name'], 'geo_states_country_name_idx');
            $table->index(['country_code', 'state_code'], 'geo_states_country_code_idx');
        });

        $core->create('geo_cities', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('country_code', 2);
            $table->string('state_code', 12)->nullable();
            $table->string('name', 160);

            // The only question asked of this table: cities in a state.
            $table->index(['country_code', 'state_code', 'name'], 'geo_cities_lookup_idx');
        });
    }

    public function down(): void
    {
        $core = Schema::connection('core');
        $core->dropIfExists('geo_cities');
        $core->dropIfExists('geo_states');
        $core->dropIfExists('geo_countries');
    }
};
