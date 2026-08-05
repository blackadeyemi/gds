<?php

namespace Modules\Bil\Support;

/**
 * The `basepaper` field of a finished-goods spec: the BPL hardroll grade type
 * used for each ply, joined with '-', where brackets mark plies that come from
 * one combined hardroll. e.g. "PBT-PBT", "[SBT-EBT]-SBT", "[PTN-PTN-PTN]".
 *
 * Mirrors computateGradetype() in the legacy js/quality_control.js so strings
 * written here are read back identically by the legacy dashboard.
 */
class GradeType
{
    /** Which plies share a hardroll. 'none' = each ply on its own. */
    public const GROUPINGS = ['none', '1-2', '2-3', '1-2-3'];

    /**
     * Above this many plies the bracket groupings stop being expressible — the
     * legacy vocabulary only ever named combinations of the first three — so a
     * higher ply count is always composed ungrouped.
     */
    public const MAX_GROUPED_PLIES = 3;

    /** Build the stored string from one grade type per ply plus a grouping. */
    public static function compose(array $types, string $grouping = 'none'): string
    {
        $types = array_values(array_filter(array_map('trim', $types), fn ($t) => $t !== ''));

        if (count($types) > self::MAX_GROUPED_PLIES) {
            return implode('-', $types);
        }

        return match (count($types)) {
            0 => '',
            1 => $types[0],
            2 => $grouping === 'none' ? "{$types[0]}-{$types[1]}" : "[{$types[0]}-{$types[1]}]",
            default => match ($grouping) {
                '1-2' => "[{$types[0]}-{$types[1]}]-{$types[2]}",
                '2-3' => "{$types[0]}-[{$types[1]}-{$types[2]}]",
                '1-2-3' => "[{$types[0]}-{$types[1]}-{$types[2]}]",
                default => "{$types[0]}-{$types[1]}-{$types[2]}",
            },
        };
    }

    /** Whether a grouping can be applied at this ply count. */
    public static function groupable(int $plies): bool
    {
        return $plies > 1 && $plies <= self::MAX_GROUPED_PLIES;
    }

    /**
     * Split a stored string back into ['types' => [...], 'grouping' => '…'] so
     * an existing product round-trips through the edit form unchanged.
     */
    public static function parse(?string $basepaper): array
    {
        $s = trim((string) $basepaper);
        if ($s === '') {
            return ['types' => [], 'grouping' => 'none'];
        }

        $grouping = 'none';

        if (preg_match('/^\[([^\]]*)\]$/', $s, $m)) {
            // Fully bracketed: every ply off the same hardroll. Two plies can
            // only be '1-2'; three is the all-together '1-2-3'.
            $s = $m[1];
            $grouping = substr_count($s, '-') >= 2 ? '1-2-3' : '1-2';
        } elseif (preg_match('/^\[([^\]]*)\]-(.+)$/', $s, $m)) {
            $grouping = '1-2';
            $s = $m[1] . '-' . $m[2];
        } elseif (preg_match('/^(.+?)-\[([^\]]*)\]$/', $s, $m)) {
            $grouping = '2-3';
            $s = $m[1] . '-' . $m[2];
        }

        // Grade types never contain '-' (they may contain '+', e.g. PTN+).
        $types = array_values(array_filter(array_map('trim', explode('-', $s)), fn ($t) => $t !== ''));

        return ['types' => $types, 'grouping' => $grouping];
    }

    /** The bare grade types in a stored string, brackets ignored. */
    public static function types(?string $basepaper): array
    {
        return self::parse($basepaper)['types'];
    }
}
