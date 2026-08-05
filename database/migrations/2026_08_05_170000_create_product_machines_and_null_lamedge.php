<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a finished-goods product real machine assignments, and let it have more
 * than one.
 *
 * `products.mach` is a single free-text machine name — it can't express "this
 * product runs on two lines", and it doesn't survive a rename. This adds
 * `product_machines`, where each row is one Factory → Line → Project path from
 * the hierarchy in `core` (see the factory_hierarchy migrations). Line and
 * project are optional, so an assignment can be pinned at whatever depth is
 * actually meaningful — which matches the legacy data, where `mach` holds a
 * line name for some products and a project name for others.
 *
 * `products.mach` STAYS and is kept as a readable summary of the assignments:
 * the legacy QC screens still read it, and the revision archive has years of
 * history in it. Ids are not foreign keys because the hierarchy lives in the
 * `core` database while products live in `bil` — the same convention the rest
 * of the cross-database references here follow.
 *
 * Also normalises `lamedge`: "N/A" and "" both meant "not applicable" and are
 * folded to NULL so the column has one way of saying nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('bil')->create('product_machines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            // core.factories / core.machine_lines / core.machine_projects.
            $table->unsignedBigInteger('factory_id')->nullable()->index();
            $table->unsignedBigInteger('line_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->backfillFromMach();

        // "N/A" and "" are the same statement as NULL; keep one of them.
        DB::connection('bil')->table('products')
            ->where('lamedge', 'N/A')->orWhere('lamedge', '')
            ->update(['lamedge' => null]);
    }

    /**
     * Seed assignments from the existing `mach` text.
     *
     * Each distinct value is matched against the hierarchy by name — most are
     * line names, a few are project names. A project match also records the
     * line and factory above it so the row is a complete path. Values that
     * match nothing (free text like "RW 9/10/GAMBINI", "d") are left alone:
     * `mach` still holds them, and the form shows them until someone picks a
     * real machine.
     */
    private function backfillFromMach(): void
    {
        $bil = DB::connection('bil');
        $core = DB::connection('core');

        // Keyed case-insensitively: MySQL's collation matches "Rotomac" to the
        // stored "ROTOMAC", but a PHP array lookup would not.
        $key = fn ($name) => mb_strtoupper(trim((string) $name));

        $lineIds = $core->table('machine_lines')->whereNull('deleted_at')
            ->get(['id', 'name'])->keyBy(fn ($l) => $key($l->name))->map->id;
        $projects = $core->table('machine_projects')->whereNull('deleted_at')
            ->get(['id', 'name', 'line_id'])->keyBy(fn ($p) => $key($p->name));

        $now = now();
        $rows = [];

        $products = $bil->table('products')
            ->whereNotNull('mach')->where('mach', '<>', '')
            ->get(['productid', 'mach']);

        foreach ($products as $product) {
            $name = $key($product->mach);

            if (isset($lineIds[$name])) {
                $lineId = $lineIds[$name];
                $rows[] = [
                    'product_id' => $product->productid,
                    'factory_id' => $this->factoryOfLine($core, $lineId),
                    'line_id' => $lineId,
                    'project_id' => null,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                continue;
            }

            if ($projects->has($name)) {
                $project = $projects[$name];
                $rows[] = [
                    'product_id' => $product->productid,
                    'factory_id' => $this->factoryOfLine($core, $project->line_id),
                    'line_id' => $project->line_id,
                    'project_id' => $project->id,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $bil->table('product_machines')->insert($chunk);
        }
    }

    /**
     * The factory a line belongs to, walking up through parent sub-lines.
     * Site-wide equipment (compressors, lifters) genuinely has none.
     */
    private function factoryOfLine($core, ?int $lineId): ?int
    {
        $seen = 0;

        while ($lineId && $seen++ < 10) {
            $line = $core->table('machine_lines')->where('id', $lineId)
                ->first(['factory_id', 'parent_id']);
            if (! $line) {
                return null;
            }
            if ($line->factory_id) {
                return $line->factory_id;
            }
            $lineId = $line->parent_id;
        }

        return null;
    }

    public function down(): void
    {
        Schema::connection('bil')->dropIfExists('product_machines');
        // lamedge is left normalised: "N/A" carried no information the NULL
        // doesn't, and restoring it would mean guessing which blanks were which.
    }
};
