<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Where a piece of waste came from: a jumbo roll, or raw materials.
 *
 * The origin is what decides which lookup the entry is classified against —
 * jumbo roll waste is attributed to a GRADE TYPE, raw materials waste to a
 * GROUP. `source` names that lookup.
 *
 * An admin may rename, reorder or retire an origin in Settings → Waste, but
 * `source` is a closed vocabulary (SOURCES): it maps to a query, so a value
 * nothing can resolve would just render an empty dropdown. Adding a third
 * origin therefore means adding its source here — deliberately, since something
 * has to know where its options come from.
 */
class WasteOrigin extends Model
{
    use SoftDeletes;

    protected $connection = 'core';
    protected $table = 'waste_origins';

    protected $fillable = ['key', 'label', 'source', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** source => the label shown for it in Settings. */
    public const SOURCES = [
        'grade_types' => 'Jumbo roll grade types',
        'rm_groups' => 'Raw materials groups',
        'none' => 'No sub-classification',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('label');
    }

    /**
     * The options this origin classifies against, as [value => label].
     *
     * Both lookups live on the `bil` connection while the waste tables live on
     * `core`, so nothing here can be a join — the chosen value is copied onto
     * the entry row instead (see conversion_waste_entries.origin_ref).
     */
    public function options(): array
    {
        return match ($this->source) {
            'grade_types' => DB::connection('bil')->table('jumboreel_grades')
                ->orderBy('gradetype')
                ->get(['gradetype', 'gradename'])
                // "PBT — Premium Bathroom Tissue": the code alone is not
                // readable to anyone who has not memorised twenty of them.
                ->mapWithKeys(fn ($g) => [$g->gradetype => $g->gradetype . ' — ' . $g->gradename])
                ->all(),

            'rm_groups' => DB::connection('bil')->table('rawmaterials_groups')
                ->orderBy('groupname')->pluck('groupname', 'groupname')->all(),

            default => [],
        };
    }

    /** The row id behind a chosen option, kept alongside the value for reports. */
    public function refId(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->source) {
            'grade_types' => DB::connection('bil')->table('jumboreel_grades')
                ->where('gradetype', $value)->value('id'),
            'rm_groups' => DB::connection('bil')->table('rawmaterials_groups')
                ->where('groupname', $value)->value('id'),
            default => null,
        };
    }

    /** Whether this origin asks for a sub-classification at all. */
    public function needsRef(): bool
    {
        return $this->source !== 'none';
    }
}
