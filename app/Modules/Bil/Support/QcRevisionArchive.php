<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;

/**
 * Revision history of a finished-goods QC specification (bil.qc_revision).
 *
 * One row per product, keyed by productid. `data` holds the ARCHIVED specs:
 * editing a product pushes the spec exactly as it was before the change and
 * bumps products.revnumber, so the live row is always the newest revision and
 * the full history is [...archived, current].
 *
 * The legacy app stored `data` two ways — a bare JSON object for a product's
 * first archived revision, a JSON array once a second was appended — and both
 * shapes are still in the table. Reads therefore normalise, and writes go
 * through JSON_ARRAY_APPEND, which auto-wraps a non-array at '$'. That is what
 * the legacy app does too, so a product edited in either app stays readable in
 * both.
 */
class QcRevisionArchive
{
    /** Archived (i.e. superseded) revisions of a product. */
    public static function archived(int $productId): array
    {
        $json = DB::connection('bil')->table('qc_revision')->where('id', $productId)->value('data');
        if (! $json) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        // A list means several archived revisions; an object means just one.
        $revisions = array_is_list($decoded) ? $decoded : [$decoded];

        return array_map(
            fn ($revision) => is_array($revision) ? self::normalise($revision) : [],
            $revisions
        );
    }

    /**
     * Flatten a legacy archive entry so it reads like a products row.
     *
     * The archives are not quite uniform: `sheetwidth` was sometimes written as
     * the form's [min, mid, max] array rather than the "min:mid:max" string the
     * table stores (506 of the entries currently on record). Anything else that
     * arrives structured is rendered rather than left to blow up a view.
     *
     * `machines` (a list of "Factory → Line → Project" labels) is flattened the
     * same way, so a revision always presents flat, printable values.
     */
    protected static function normalise(array $revision): array
    {
        foreach ($revision as $key => $value) {
            if (is_array($value)) {
                $revision[$key] = implode($key === 'sheetwidth' ? ':' : ', ', array_map('strval', $value));
            } elseif (is_object($value)) {
                $revision[$key] = json_encode($value);
            }
        }

        return $revision;
    }

    /**
     * Every revision of a product oldest-first: the archived ones plus the
     * current spec, which is passed in because it lives on the products row.
     */
    public static function history(int $productId, array $current): array
    {
        $all = self::archived($productId);
        // Normalised too, so every entry in the history has the same shape —
        // notably `machines`, which is passed in as a list of labels.
        $all[] = self::normalise($current);

        // Revision numbers are the user-facing ordering and are not always
        // dense (the legacy Revision form could insert one), so sort on them
        // rather than trusting insertion order.
        usort($all, fn ($a, $b) => (float) ($a['revnumber'] ?? 0) <=> (float) ($b['revnumber'] ?? 0));

        return $all;
    }

    /** Append a spec snapshot to a product's archive. */
    public static function archive(int $productId, array $spec): void
    {
        $db = DB::connection('bil');
        $json = json_encode($spec);

        if ($db->table('qc_revision')->where('id', $productId)->exists()) {
            $db->statement(
                'UPDATE `qc_revision` SET `data` = JSON_ARRAY_APPEND(`data`, "$", CAST(? AS JSON)) WHERE `id` = ?',
                [$json, $productId]
            );
        } else {
            $db->table('qc_revision')->insert(['id' => $productId, 'data' => $json]);
        }
    }
}
