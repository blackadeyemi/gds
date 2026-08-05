<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the legacy factory tree into core.factories / machine_lines /
 * machine_projects.
 *
 * The legacy tables join on NAMES, and an audit confirmed those names are
 * globally unique and unambiguous (no duplicate line/subline/project/subproject
 * names, no collisions between a line name and a sub-line name, none between a
 * project and a sub-project). So the name-keyed rebuild below is deterministic
 * — with seven exceptions, each applied explicitly and listed here so the
 * parity diff against the pre-migration snapshot is explainable:
 *
 *   1. 5 projects point at lineid=2, a REW 8 that was deleted and recreated as
 *      id 33. Their sub-line CORE REW 8 does live under 33 -> remapped.
 *   2. Sub-line CORE REW 7 is referenced by 3 projects but does not exist -> created.
 *   3. Sub-lines FJ 1 / FJ 2 exist in factory_details but not factory_sublines -> created.
 *   4. 2 REW 11 projects have a blank sublinename -> attached to the REW 11 line node.
 *   5. factory_sublines id 41 caches linename '13' (an id typed into a name).
 *      The cached column does not exist here, so it simply evaporates.
 *   6. factory_details spells three machines differently (FACIAL, Handkerchief,
 *      Aluminium Foil) -> canonicalised onto the factory_lines spelling, with the
 *      old spelling preserved in legacy_alias so the view still emits it.
 *   7. Sub-line 'SEPTEMBAR ' has a trailing space -> trimmed. Safe: zero rows in
 *      any consumer table reference it.
 *
 * Root-node ids are PRESERVED (machine_lines.id 1-33 == factory_lines.id,
 * machine_projects.id 1-95 == factory_projects.id) so the compatibility views
 * are id-identical to the tables they replace. Child nodes take ids from 1000 up
 * to stay clear of that range; sub-line / sub-project ids therefore change,
 * which is safe because only the retiring legacy admin screens referenced them.
 */
return new class extends Migration
{
    private const CHILD_ID_BASE = 1000;

    /** factory_details spelling => factory_lines spelling */
    private array $aliases = [
        'FACIAL' => 'FACIAL TISSUE',
        'Handkerchief' => 'HANKERCHIEF',
        'Aluminium Foil' => 'ALUMINIUM FOIL',
    ];

    /** [company code, factory name] — code doubles as the legacy match key. */
    private array $factories = [
        ['BIL', 'Bil-1'],
        ['BIL', 'Bil-2'],
        ['BIL', 'Gambini'],
        ['BPL', 'PM2'],
        ['BPL', 'PM3'],
    ];

    /** Sub-lines the legacy data references but never created: name => parent line name. */
    private array $missingSublines = [
        'CORE REW 7' => 'REW 7',
        'FJ 1' => 'NAPKIN',
        'FJ 2' => 'NAPKIN',
    ];

    public function up(): void
    {
        $bil = DB::connection('bil');
        $core = DB::connection('core');
        $now = now();

        // --- Factories ----------------------------------------------------
        $companies = $core->table('companies')->pluck('id', 'code');
        $sort = 0;
        foreach ($this->factories as [$companyCode, $name]) {
            $core->table('factories')->insert([
                'company_id' => $companies[$companyCode],
                'name' => $name,
                // The legacy string lives in `code`, so the backfills and the
                // name->id triggers keep matching even after a display rename.
                'code' => $name,
                'sort_order' => ++$sort * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $factoryIds = $core->table('factories')->pluck('id', 'code');

        // --- Lines (root nodes, ids preserved) ----------------------------
        $lineRows = [];
        foreach ($bil->table('factory_lines')->orderBy('id')->get() as $l) {
            $lineRows[] = [
                'id' => $l->id,
                // NULL for COMPRESSOR / ELECTRICAL LIFTER / MANUAL LIFTER.
                'factory_id' => $l->factoryname ? ($factoryIds[$l->factoryname] ?? null) : null,
                'parent_id' => null,
                'name' => trim($l->linename),
                'code' => $l->linecode,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $core->table('machine_lines')->insert($lineRows);

        /** canonical line name => [id, factory_id] */
        $lines = $core->table('machine_lines')->whereNull('parent_id')
            ->get()->keyBy('name');

        // --- Sub-lines (child nodes, ids from 1000) -----------------------
        $childId = self::CHILD_ID_BASE;
        $subRows = [];
        foreach ($bil->table('factory_sublines')->orderBy('id')->get() as $s) {
            $parent = $lines->firstWhere('id', $s->lineid);
            $subRows[] = [
                'id' => $childId++,
                // A sub-line lives in whatever factory its line lives in; the
                // cached linename column is deliberately ignored (fix 5).
                'factory_id' => $parent?->factory_id,
                'parent_id' => $s->lineid,
                'name' => trim($s->sublinename),   // fix 7
                'code' => null,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // Fixes 2 and 3 — referenced everywhere but never actually created.
        foreach ($this->missingSublines as $name => $parentName) {
            $parent = $lines[$parentName];
            $subRows[] = [
                'id' => $childId++,
                'factory_id' => $parent->factory_id,
                'parent_id' => $parent->id,
                'name' => $name,
                'code' => null,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $core->table('machine_lines')->insert($subRows);

        /** every line node (root + child) by name */
        $nodes = $core->table('machine_lines')->get()->keyBy('name');
        $sublines = $core->table('machine_lines')->whereNotNull('parent_id')->get()->keyBy('name');

        // --- Projects (root nodes, ids preserved) -------------------------
        $projectRows = [];
        foreach ($bil->table('factory_projects')->orderBy('id')->get() as $p) {
            // Fix 1 — the deleted-and-recreated REW 8.
            $lineId = (int) $p->lineid === 2 ? 33 : (int) $p->lineid;
            $subName = trim($p->sublinename);

            // Normally a project hangs off a sub-line; where the legacy row has
            // no sub-line it attaches to the line node itself (fix 4).
            $ownerId = ($subName !== '' && isset($sublines[$subName]))
                ? $sublines[$subName]->id
                : $lineId;

            $projectRows[] = [
                'id' => $p->id,
                'line_id' => $ownerId,
                'parent_id' => null,
                'name' => trim($p->project),
                'code' => $p->code,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $core->table('machine_projects')->insert($projectRows);

        $projects = $core->table('machine_projects')->get()->keyBy('name');

        // --- Sub-projects (child nodes, ids from 1000) --------------------
        $childId = self::CHILD_ID_BASE;
        $subProjectRows = [];
        foreach ($bil->table('factory_subprojects')->orderBy('id')->get() as $sp) {
            $parent = $projects[trim($sp->project)];
            $subProjectRows[] = [
                'id' => $childId++,
                'line_id' => $parent->line_id,
                'parent_id' => $parent->id,
                'name' => trim($sp->subproject),
                // projectcode on the legacy row is the PARENT's code, not the
                // sub-project's own — it has none. The view reads it off the parent.
                'code' => null,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $core->table('machine_projects')->insert($subProjectRows);

        // --- factory_details carriers (fix 6) -----------------------------
        foreach ($bil->table('factory_details')->orderBy('id')->get() as $d) {
            $canonical = $this->aliases[$d->sublinename] ?? trim($d->sublinename);
            $node = $nodes[$canonical] ?? null;
            if (! $node) {
                continue;
            }

            $update = [
                'detail_id' => $d->id,
                'detail_code' => $d->linecode,
                'updated_at' => $now,
            ];
            // Only line-level detail rows can disagree with the canonical name;
            // store the legacy spelling so the view can still emit it verbatim.
            if ($node->parent_id === null && $d->linename !== $node->name) {
                $update['legacy_alias'] = $d->linename;
            }

            $core->table('machine_lines')->where('id', $node->id)->update($update);
        }
    }

    public function down(): void
    {
        $core = DB::connection('core');
        $core->table('machine_projects')->delete();
        $core->table('machine_lines')->delete();
        $core->table('factories')->delete();
    }
};
