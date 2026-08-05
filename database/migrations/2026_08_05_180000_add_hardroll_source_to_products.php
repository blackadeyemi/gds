<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structure where a product's hardroll comes from.
 *
 * `hardrollsource` is free text that conflates a company with one of its
 * plants — "BPL" for 194 products, "BPL PM 3" for 7. This adds a real company
 * reference and an optional factory beneath it (core.companies / core.factories),
 * so "BPL PM 3" becomes company BPL, factory PM3.
 *
 * External mills stay as they are: "PT Pindo/BPL" (24) and "Imported" (3) name
 * suppliers that aren't our companies, and "PT Pindo/BPL" is two sources at
 * once. Those keep their text and no ids, and the form shows the text as-is
 * rather than pretending it fits the hierarchy.
 *
 * `hardrollsource` stays either way — for structured sources it is rewritten as
 * a readable summary ("BPL", "BPL PM3"), which is what the legacy QC screens
 * and the revision archive read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('bil')->table('products', function (Blueprint $table) {
            // core.companies / core.factories — no FK, the tables are in
            // another database (same convention as product_machines).
            $table->unsignedBigInteger('hardroll_company_id')->nullable()->after('hardrollsource');
            $table->unsignedBigInteger('hardroll_factory_id')->nullable()->after('hardroll_company_id');
            $table->index('hardroll_company_id', 'products_hardroll_company_idx');
        });

        $this->backfill();
    }

    /**
     * Resolve the existing text where it names one of our companies.
     *
     * Matched conservatively: the value must BE a company code, or start with
     * one followed by a factory of that company. "PT Pindo/BPL" mentions BPL
     * but is a combined external source, so it is deliberately left alone.
     */
    private function backfill(): void
    {
        $bil = DB::connection('bil');
        $core = DB::connection('core');

        $companies = $core->table('companies')->whereNotNull('code')
            ->get(['id', 'code'])->keyBy(fn ($c) => mb_strtoupper($c->code));

        // Factory names compared with spaces stripped, so "PM 3" finds "PM3".
        $factories = $core->table('factories')->whereNull('deleted_at')
            ->get(['id', 'name', 'company_id'])
            ->groupBy('company_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($f) => $this->squash($f->name)));

        $values = $bil->table('products')
            ->whereNotNull('hardrollsource')->where('hardrollsource', '<>', '')
            ->distinct()->pluck('hardrollsource');

        foreach ($values as $value) {
            $text = trim((string) $value);
            $upper = mb_strtoupper($text);

            $company = null;
            $remainder = '';

            if ($companies->has($upper)) {
                $company = $companies[$upper];
            } else {
                foreach ($companies as $code => $candidate) {
                    if (str_starts_with($upper, $code . ' ')) {
                        $company = $candidate;
                        $remainder = trim(mb_substr($text, mb_strlen($code)));
                        break;
                    }
                }
            }

            if (! $company) {
                continue;
            }

            $factoryId = null;
            if ($remainder !== '') {
                $factory = ($factories[$company->id] ?? collect())->get($this->squash($remainder));
                if (! $factory) {
                    // Names a company plus something we can't resolve — leave the
                    // whole value as text rather than half-recording it.
                    continue;
                }
                $factoryId = $factory->id;
            }

            $bil->table('products')->where('hardrollsource', $value)->update([
                'hardroll_company_id' => $company->id,
                'hardroll_factory_id' => $factoryId,
                // Normalise the text to the summary the app now generates.
                'hardrollsource' => $company->code . ($factoryId ? ' ' . $factories[$company->id]->firstWhere('id', $factoryId)->name : ''),
            ]);
        }
    }

    private function squash(string $value): string
    {
        return mb_strtoupper(str_replace(' ', '', trim($value)));
    }

    public function down(): void
    {
        Schema::connection('bil')->table('products', function (Blueprint $table) {
            $table->dropIndex('products_hardroll_company_idx');
            $table->dropColumn(['hardroll_company_id', 'hardroll_factory_id']);
        });
        // hardrollsource text is left as normalised ("BPL PM3"); the original
        // spacing carried no extra meaning.
    }
};
